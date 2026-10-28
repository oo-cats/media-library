<?php

declare(strict_types=1);

namespace Local\MediaLibrary;

use Flarum\Foundation\Paths;
use Flarum\Foundation\ValidationException;
use Psr\Http\Message\UploadedFileInterface;

/** Disk paths depend only on a validated random ID, never on a client filename. */
final class PrivateStorage
{
    private string $root;
    public const TYPES = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp',
        'application/pdf', 'text/plain', 'application/zip',
    ];

    public function __construct(Paths $paths, private ImageProfiles $profiles)
    {
        $this->root = $paths->storage.'/local-media-library';
    }

    public function path(string $uuid, bool $thumbnail = false): string
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $uuid)) {
            throw new \InvalidArgumentException('Invalid attachment ID.');
        }
        return $this->root.'/'.substr($uuid, 0, 2).'/'.substr($uuid, 2, 2).'/'.$uuid.($thumbnail ? '.webp' : '.bin');
    }

    public function receive(UploadedFileInterface $upload, string $uuid, int $limit): array
    {
        if ($upload->getError() !== UPLOAD_ERR_OK) {
            throw new ValidationException(['file' => '上传未完成；请检查文件大小及 PHP 请求限制。']);
        }
        $path = $this->path($uuid);
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Cannot create private attachment directory.');
        }
        $part = $path.'.part';
        $out = fopen($part, 'xb');
        if ($out === false) {
            throw new \RuntimeException('Cannot create attachment.');
        }
        $size = 0;
        $hash = hash_init('sha256');
        try {
            $stream = $upload->getStream();
            while (!$stream->eof()) {
                $chunk = $stream->read(65536);
                $size += strlen($chunk);
                if ($size > $limit) {
                    throw new ValidationException(['file' => '文件超过上传大小限制。']);
                }
                if ($chunk === '' && !$stream->eof()) {
                    throw new \RuntimeException('Upload stream stalled.');
                }
                hash_update($hash, $chunk);
                if (fwrite($out, $chunk) !== strlen($chunk)) {
                    throw new \RuntimeException('Attachment write failed.');
                }
            }
            fclose($out);
            $out = null;
            if ($size === 0) {
                throw new ValidationException(['file' => '不能上传空文件。']);
            }
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($part);
            if (!in_array($mime, self::TYPES, true)) {
                throw new ValidationException(['file' => '支持 JPEG、PNG、GIF、WebP、PDF、纯文本和 ZIP。']);
            }
            $width = $height = null;
            if (str_starts_with($mime, 'image/')) {
                $dimensions = @getimagesize($part);
                if (!$dimensions || ($dimensions['mime'] ?? '') !== $mime) {
                    throw new ValidationException(['file' => '图片内容与格式不匹配。']);
                }
                [$width, $height] = $dimensions;
                // Bound GD decoding memory as well as the on-disk upload size.
                if ($width > 10000 || $height > 10000 || $width * $height > 16000000) {
                    throw new ValidationException(['file' => '图片最多 1600 万像素，单边不超过 10000 像素。']);
                }
                if ($mime === 'image/gif') {
                    // Inspect every frame rectangle before GD decodes even the first frame.
                    (new GifInspector())->inspect($part, $this->profiles->values());
                }
                $this->thumbnail($part, $uuid, $mime, $width, $height);
            }
            if (!rename($part, $path)) {
                throw new \RuntimeException('Attachment finalization failed.');
            }
            return compact('mime', 'size', 'width', 'height') + ['sha256' => hash_final($hash)];
        } catch (\Throwable $error) {
            if (is_resource($out)) {
                fclose($out);
            }
            $this->remove($uuid);
            throw $error;
        }
    }

    private function thumbnail(string $part, string $uuid, string $mime, int $width, int $height): void
    {
        $image = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($part),
            'image/png' => @imagecreatefrompng($part),
            'image/gif' => @imagecreatefromgif($part),
            'image/webp' => @imagecreatefromwebp($part),
        };
        if (!$image) {
            throw new ValidationException(['file' => '无法解码图片。']);
        }
        $scale = min(1, 320 / max($width, $height));
        $thumb = imagecreatetruecolor(max(1, (int) ($width * $scale)), max(1, (int) ($height * $scale)));
        imagealphablending($thumb, false);
        imagesavealpha($thumb, true);
        imagecopyresampled($thumb, $image, 0, 0, 0, 0, imagesx($thumb), imagesy($thumb), $width, $height);
        try {
            if (!imagewebp($thumb, $this->path($uuid, true), 80)) {
                throw new \RuntimeException('Thumbnail write failed.');
            }
        } finally {
            imagedestroy($image);
            imagedestroy($thumb);
        }
    }

    public function remove(string $uuid): void
    {
        foreach (array_merge([$this->path($uuid), $this->path($uuid, true), $this->path($uuid).'.part'], $this->variantPaths($uuid)) as $path) {
            if (is_file($path) && !unlink($path)) {
                throw new \RuntimeException('Attachment removal failed; retry maintenance.');
            }
        }
    }

    public function variantPath(string $uuid, string $profile, string $kind): string
    {
        $original = $this->path($uuid);
        if (!preg_match('/^[a-f0-9]{64}$/D', $profile) || !in_array($kind, Variants::KINDS, true)) {
            throw new \InvalidArgumentException('Invalid image variant.');
        }
        return dirname($original).'/'.$uuid.'.'.$profile.'.'.$kind.'.bin';
    }

    public function writeVariant(string $path, string $bytes): void
    {
        $temporary = $path.'.'.bin2hex(random_bytes(8)).'.part';
        try {
            if (file_put_contents($temporary, $bytes, LOCK_EX) !== strlen($bytes) || !rename($temporary, $path)) {
                throw new \RuntimeException('Cannot publish image variant.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    /** Enumerated only during maintenance or deletion, never on list/read requests. */
    public function variantPaths(string $uuid): array
    {
        $original = $this->path($uuid);
        return array_values(array_filter(glob(dirname($original).'/'.$uuid.'.*') ?: [], static function ($path) use ($uuid) {
            return !is_link($path) && is_file($path)
                && preg_match('/^'.$uuid.'\.[a-f0-9]{64}\.(picker|inline|sticker)\.bin(?:\.[a-f0-9]{16}\.part)?$/D', basename($path));
        }));
    }

    /** Maintenance only. No filesystem scans occur on user list/search requests. */
    public function storedIds(): iterable
    {
        if (!is_dir($this->root)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $entry) {
            if ($entry->isLink() || !$entry->isFile()
                || !preg_match('/^([a-f0-9]{32})(\.bin(?:\.part)?|\.webp|\.[a-f0-9]{64}\.(?:picker|inline|sticker)\.bin(?:\.[a-f0-9]{16}\.part)?)$/D', $entry->getFilename(), $match)) {
                continue;
            }
            $expected = dirname($this->path($match[1])).'/'.$entry->getFilename();
            if (str_replace('\\', '/', $entry->getPathname()) === str_replace('\\', '/', $expected)) {
                yield $match[1];
            }
        }
    }

    public function isOldOrphan(string $uuid, int $cutoff): bool
    {
        $found = false;
        foreach (array_merge([$this->path($uuid), $this->path($uuid, true), $this->path($uuid).'.part'], $this->variantPaths($uuid)) as $path) {
            if (is_file($path)) {
                $found = true;
                if (filemtime($path) > $cutoff) {
                    return false;
                }
            }
        }
        return $found;
    }
}
