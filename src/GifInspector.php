<?php

declare(strict_types=1);

namespace Local\MediaLibrary;

use Flarum\Foundation\ValidationException;

/** GIF87a/89a block walk; no frame bitmaps or LZW dictionaries are allocated. */
final class GifInspector
{
    public function inspect(string $path, array $limits): array
    {
        $size = @filesize($path);
        if ($size === false || $size > $limits['gifMaxKb'] * 1024) {
            throw new ValidationException(['file' => 'GIF 原文件超过管理员设置的大小上限。']);
        }
        $stream = @fopen($path, 'rb');
        if (!$stream) {
            throw new \RuntimeException('Cannot inspect GIF.');
        }
        try {
            if (!in_array($this->read($stream, 6), ['GIF87a', 'GIF89a'], true)) {
                $this->invalid();
            }
            $screen = unpack('vwidth/vheight/Cflags/Cbackground/Caspect', $this->read($stream, 7));
            $width = $screen['width'];
            $height = $screen['height'];
            $pixels = $width * $height;
            if (!$width || !$height || max($width, $height) > min($limits['maxEdge'], $limits['gifMaxEdge'])
                || $pixels > $limits['maxPixels']) {
                throw new ValidationException(['file' => 'GIF 画布超过尺寸或像素上限。']);
            }
            $globalPalette = ($screen['flags'] & 128) !== 0;
            if ($globalPalette) {
                $this->read($stream, 3 * (2 << ($screen['flags'] & 7)));
            }
            $frames = $durationMs = $delay = 0;
            while (true) {
                $marker = $this->read($stream, 1);
                if ($marker === "\x3b") {
                    if (!$frames || fread($stream, 1) !== '') {
                        $this->invalid();
                    }
                    return compact('width', 'height', 'frames', 'durationMs', 'size');
                }
                if ($marker === "\x21") {
                    $extension = $this->read($stream, 1);
                    if ($extension === "\xf9") {
                        if ($this->read($stream, 1) !== "\x04") {
                            $this->invalid();
                        }
                        $control = unpack('Cflags/vdelay/Ctransparent', $this->read($stream, 4));
                        // User-input waits and reserved disposal methods are outside this policy.
                        if (($control['flags'] & 226) || (($control['flags'] >> 2) & 7) > 3
                            || $this->read($stream, 1) !== "\x00") {
                            $this->invalid();
                        }
                        $delay = $control['delay'];
                    } elseif ($extension === "\xff") {
                        if ($this->read($stream, 1) !== "\x0b") {
                            $this->invalid();
                        }
                        $this->read($stream, 11);
                        $this->subBlocks($stream);
                    } elseif ($extension === "\xfe") {
                        $this->subBlocks($stream);
                    } else {
                        // Plain-text rendering and unknown extensions are not accepted.
                        $this->invalid();
                    }
                    continue;
                }
                if ($marker !== "\x2c") {
                    $this->invalid();
                }
                $frame = unpack('vleft/vtop/vwidth/vheight/Cflags', $this->read($stream, 9));
                if (!$frame['width'] || !$frame['height'] || ($frame['flags'] & 24)
                    || $frame['left'] + $frame['width'] > $width || $frame['top'] + $frame['height'] > $height) {
                    $this->invalid();
                }
                $frames++;
                // Charge a full compositing canvas for every frame, even a one-pixel delta.
                if ($frames > $limits['gifMaxFrames'] || $pixels * $frames > $limits['gifMaxPixels']) {
                    throw new ValidationException(['file' => 'GIF 帧数或累计画布像素超过上限。']);
                }
                // Use 100 ms for absent/0/1-centisecond delays; this is a per-cycle estimate.
                $durationMs += ($delay < 2 ? 10 : $delay) * 10;
                $delay = 0;
                if ($durationMs > $limits['gifMaxDuration'] * 1000) {
                    throw new ValidationException(['file' => 'GIF 单轮动画时长超过上限。']);
                }
                if ($frame['flags'] & 128) {
                    $this->read($stream, 3 * (2 << ($frame['flags'] & 7)));
                } elseif (!$globalPalette) {
                    $this->invalid();
                }
                $codeSize = ord($this->read($stream, 1));
                if ($codeSize < 2 || $codeSize > 8 || !$this->subBlocks($stream)) {
                    $this->invalid();
                }
            }
        } finally {
            fclose($stream);
        }
    }

    private function read($stream, int $length): string
    {
        $bytes = fread($stream, $length);
        if ($bytes === false || strlen($bytes) !== $length) {
            $this->invalid();
        }
        return $bytes;
    }

    private function subBlocks($stream): int
    {
        $bytes = 0;
        while ($length = ord($this->read($stream, 1))) {
            $this->read($stream, $length);
            $bytes += $length;
        }
        return $bytes;
    }

    private function invalid(): never
    {
        throw new ValidationException(['file' => 'GIF 结构不完整、帧越界或包含不支持的扩展。']);
    }
}
