<?php

declare(strict_types=1);

namespace Local\MediaLibrary;

use Flarum\Foundation\ValidationException;

/** Bounded GD work; animated GIFs retain their validated original byte stream. */
final class ImageEncoder
{
    public function encode(string $path, string $mime, array $profile): array
    {
        $gif = $mime === 'image/gif' ? (new GifInspector())->inspect($path, $profile) : null;
        $image = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            'image/gif' => @imagecreatefromgif($path),
            'image/webp' => @imagecreatefromwebp($path),
            default => false,
        };
        if (!$image) {
            throw new ValidationException(['file' => '无法静态解码图片，暂不支持此动画格式。']);
        }
        try {
            if ($mime === 'image/jpeg') {
                $image = $this->orient($image, $this->jpegOrientation($path));
            }
            $width = imagesx($image);
            $height = imagesy($image);
            if ($width * $height > $profile['maxPixels'] || max($width, $height) > $profile['maxEdge']) {
                throw new ValidationException(['file' => '图片超过表情处理的像素上限。']);
            }
            if ($gif && $profile['gifPolicy'] === 'animated') {
                $picker = $this->variant($image, 'picker', $profile);
                $bytes = file_get_contents($path);
                if ($bytes === false || strlen($bytes) !== $gif['size']) {
                    throw new ValidationException(['file' => 'GIF 读取失败，请重试。']);
                }
                $animation = ['bytes' => $bytes, 'mime' => 'image/gif', 'size' => strlen($bytes),
                    'width' => $gif['width'], 'height' => $gif['height']];
                // Existing variant paths, authorization, accounting and retirement remain shared.
                return ['picker' => $picker, 'inline' => $animation, 'sticker' => $animation];
            }
            $result = [];
            foreach (['picker', 'inline', 'sticker'] as $kind) {
                $result[$kind] = $this->variant($image, $kind, $profile);
            }
            return $result;
        } finally {
            imagedestroy($image);
        }
    }

    private function variant(\GdImage $source, string $kind, array $profile): array
    {
        $edge = min($profile[$kind.'Edge'], max(imagesx($source), imagesy($source)));
        $quality = $profile['quality'];
        for ($attempt = 0; $attempt < $profile['attempts']; $attempt++) {
            $scale = min(1, $edge / max(imagesx($source), imagesy($source)));
            $width = max(1, (int) floor(imagesx($source) * $scale));
            $height = max(1, (int) floor(imagesy($source) * $scale));
            $target = imagecreatetruecolor($width, $height);
            imagealphablending($target, false);
            imagesavealpha($target, true);
            imagefill($target, 0, 0, imagecolorallocatealpha($target, 0, 0, 0, 127));
            imagecopyresampled($target, $source, 0, 0, 0, 0, $width, $height, imagesx($source), imagesy($source));
            $format = $profile['format'] === 'auto' ? 'webp' : $profile['format'];
            try {
                if ($format === 'jpeg' && $this->hasAlpha($target)) {
                    throw new ValidationException(['file' => 'JPEG 不支持透明度，请选择 WebP 或 PNG。']);
                }
                ob_start();
                try {
                    $ok = match ($format) {
                        'webp' => imagewebp($target, null, $quality),
                        'jpeg' => imagejpeg($target, null, $quality),
                        'png' => imagepng($target, null, $profile['pngLevel']),
                    };
                    $bytes = ob_get_contents();
                } finally {
                    ob_end_clean();
                }
            } finally {
                imagedestroy($target);
            }
            $mime = 'image/'.($format === 'jpeg' ? 'jpeg' : $format);
            $info = $bytes !== '' ? @getimagesizefromstring($bytes) : false;
            if (!$ok || !$info || ($info['mime'] ?? '') !== $mime || $info[0] !== $width || $info[1] !== $height) {
                throw new ValidationException(['file' => '图片编码失败或输出不完整。']);
            }
            if (strlen($bytes) <= $profile[$kind.'Kb'] * 1024) {
                $check = @imagecreatefromstring($bytes);
                if (!$check) {
                    throw new ValidationException(['file' => '处理图无法重新解码。']);
                }
                imagedestroy($check);
                return ['bytes' => $bytes, 'mime' => $mime, 'size' => strlen($bytes), 'width' => $width, 'height' => $height];
            }
            if ($format !== 'png' && $quality > $profile['qualityFloor']) {
                $quality = max($profile['qualityFloor'], $quality - 10);
            } else {
                $edge = max(1, (int) floor($edge * .75));
            }
        }
        throw new ValidationException(['file' => '达到编码次数上限，'.$kind.' 处理图仍超过字节上限。']);
    }

    private function hasAlpha(\GdImage $image): bool
    {
        for ($y = 0; $y < imagesy($image); $y++) {
            for ($x = 0; $x < imagesx($image); $x++) {
                if ((imagecolorat($image, $x, $y) >> 24) & 127) {
                    return true;
                }
            }
        }
        return false;
    }

    /** Read only bounded JPEG headers and the standard IFD0 orientation tag. */
    private function jpegOrientation(string $path): int
    {
        $stream = fopen($path, 'rb');
        if (!$stream) {
            return 1;
        }
        try {
            if (fread($stream, 2) !== "\xff\xd8") {
                return 1;
            }
            for ($n = 0; $n < 64 && ftell($stream) < 1048576; $n++) {
                $marker = fread($stream, 2);
                if (strlen($marker) !== 2 || $marker[0] !== "\xff" || in_array(ord($marker[1]), [0xda, 0xd9], true)) {
                    break;
                }
                $lengthBytes = fread($stream, 2);
                if (strlen($lengthBytes) !== 2) {
                    break;
                }
                $length = unpack('n', $lengthBytes)[1] - 2;
                if ($length < 0) {
                    break;
                }
                $data = $length ? fread($stream, $length) : '';
                if ($marker[1] !== "\xe1" || !str_starts_with($data, "Exif\0\0")) {
                    continue;
                }
                $tiff = substr($data, 6);
                $little = substr($tiff, 0, 2) === 'II';
                if (!$little && substr($tiff, 0, 2) !== 'MM') {
                    return 1;
                }
                $read = static function (int $offset, int $size) use ($tiff, $little): int {
                    return $offset >= 0 && $offset + $size <= strlen($tiff)
                        ? unpack($size === 2 ? ($little ? 'v' : 'n') : ($little ? 'V' : 'N'), substr($tiff, $offset, $size))[1] : 0;
                };
                $offset = $read(4, 4);
                $count = min(512, $read($offset, 2));
                for ($i = 0; $i < $count; $i++) {
                    $entry = $offset + 2 + 12 * $i;
                    if ($read($entry, 2) === 0x112 && $read($entry + 2, 2) === 3 && $read($entry + 4, 4) === 1) {
                        return max(1, min(8, $read($entry + 8, 2)));
                    }
                }
                return 1;
            }
            return 1;
        } finally {
            fclose($stream);
        }
    }

    private function orient(\GdImage $image, int $orientation): \GdImage
    {
        $angle = match ($orientation) { 3 => 180, 5, 6 => -90, 7, 8 => 90, default => 0 };
        if ($angle) {
            $rotated = imagerotate($image, $angle, 0);
            if (!$rotated) {
                throw new ValidationException(['file' => '图片方向校正失败。']);
            }
            imagedestroy($image);
            $image = $rotated;
        }
        if (in_array($orientation, [2, 5, 7], true)) {
            imageflip($image, IMG_FLIP_HORIZONTAL);
        } elseif ($orientation === 4) {
            imageflip($image, IMG_FLIP_VERTICAL);
        }
        return $image;
    }
}
