<?php

declare(strict_types=1);

namespace Local\MediaLibrary\Tests;

/** Small valid GIFs with GD-generated frame data and explicit local palettes. */
final class GifFixture
{
    public static function make(int $width = 32, int $height = 32, int $frames = 2, int $delay = 10, bool $delta = false): string
    {
        $result = '';
        for ($i = 0; $i < $frames; $i++) {
            $image = imagecreate($delta ? 1 : $width, $delta ? 1 : $height);
            imagecolorallocate($image, $i % 2 ? 210 : 40, 80, $i % 2 ? 40 : 210);
            ob_start();
            imagegif($image);
            $gif = ob_get_clean();
            imagedestroy($image);
            $paletteFlags = ord($gif[10]) & 7;
            $palette = substr($gif, 13, 3 * (2 << $paletteFlags));
            $frame = substr($gif, 13 + strlen($palette), -1);
            if ($frame[0] !== "\x2c") {
                throw new \RuntimeException('Unexpected GD test GIF.');
            }
            if ($i === 0) {
                $result = 'GIF89a'.pack('vvCCC', $width, $height, 128 | $paletteFlags, 0, 0).$palette
                    ."\x21\xff\x0bNETSCAPE2.0\x03\x01\x00\x00\x00";
            }
            $frame[9] = chr(128 | $paletteFlags | (ord($frame[9]) & 64));
            $result .= "\x21\xf9\x04\x00".pack('v', $delay)."\x00\x00"
                .substr($frame, 0, 10).$palette.substr($frame, 10);
        }
        return $result."\x3b";
    }
}
