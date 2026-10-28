<?php

declare(strict_types=1);

namespace Local\MediaLibrary\Tests\unit;

use Flarum\Foundation\ValidationException;
use Flarum\Settings\SettingsRepositoryInterface;
use Local\MediaLibrary\ImageEncoder;
use Local\MediaLibrary\ImageProfiles;
use PHPUnit\Framework\TestCase;

final class ImageEncoderTest extends TestCase
{
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    private function profile(): array
    {
        $settings = $this->createMock(SettingsRepositoryInterface::class);
        $settings->method('get')->willReturnCallback(fn ($key, $default = null) => $default);
        return (new ImageProfiles($settings))->values();
    }

    private function file(string $bytes): string
    {
        $directory = __DIR__.'/../../.local/unit-images';
        if (!is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        $path = tempnam($directory, 'image-');
        file_put_contents($path, $bytes);
        $this->files[] = $path;
        return $path;
    }

    public function test_transparency_bounds_and_immutable_source(): void
    {
        $image = imagecreatetruecolor(640, 320);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagefilledrectangle($image, 100, 100, 200, 200, imagecolorallocatealpha($image, 100, 180, 200, 0));
        ob_start();
        imagepng($image);
        $path = $this->file(ob_get_clean());
        imagedestroy($image);
        $hash = hash_file('sha256', $path);
        $profile = $this->profile();
        $variants = (new ImageEncoder())->encode($path, 'image/png', $profile);
        foreach ($variants as $kind => $variant) {
            $this->assertLessThanOrEqual($profile[$kind.'Kb'] * 1024, $variant['size']);
            $this->assertLessThanOrEqual($profile[$kind.'Edge'], max($variant['width'], $variant['height']));
            $this->assertEquals(2, $variant['width'] / $variant['height']);
            $decoded = imagecreatefromstring($variant['bytes']);
            $this->assertGreaterThan(100, (imagecolorat($decoded, 0, 0) >> 24) & 127);
            imagedestroy($decoded);
        }
        $this->assertSame($hash, hash_file('sha256', $path));
    }

    public function test_jpeg_orientation_is_applied_without_exif_extension(): void
    {
        $image = imagecreatetruecolor(120, 60);
        imagefilledrectangle($image, 0, 0, 59, 59, imagecolorallocate($image, 240, 10, 10));
        imagefilledrectangle($image, 60, 0, 119, 59, imagecolorallocate($image, 10, 10, 240));
        ob_start();
        imagejpeg($image, null, 95);
        $jpeg = ob_get_clean();
        imagedestroy($image);
        // Explicit TIFF IFD: orientation SHORT, count 1, value 6.
        $exif = "Exif\0\0II".pack('vVv', 42, 8, 1).pack('vvVvvV', 0x112, 3, 1, 6, 0, 0);
        $path = $this->file(substr($jpeg, 0, 2)."\xff\xe1".pack('n', strlen($exif) + 2).$exif.substr($jpeg, 2));
        $profile = $this->profile();
        $profile['format'] = 'png';
        $variant = (new ImageEncoder())->encode($path, 'image/jpeg', $profile)['inline'];
        $this->assertSame(48, $variant['width']);
        $this->assertSame(96, $variant['height']);
        $decoded = imagecreatefromstring($variant['bytes']);
        $top = imagecolorsforindex($decoded, imagecolorat($decoded, 24, 10));
        $bottom = imagecolorsforindex($decoded, imagecolorat($decoded, 24, 85));
        $this->assertGreaterThan($top['blue'], $top['red']);
        $this->assertGreaterThan($bottom['red'], $bottom['blue']);
        imagedestroy($decoded);
    }

    public function test_transparent_image_cannot_silently_become_jpeg(): void
    {
        $image = imagecreatetruecolor(32, 32);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        ob_start();
        imagepng($image);
        $path = $this->file(ob_get_clean());
        imagedestroy($image);
        $profile = $this->profile();
        $profile['format'] = 'jpeg';
        $this->expectException(ValidationException::class);
        (new ImageEncoder())->encode($path, 'image/png', $profile);
    }

    public function test_output_byte_budget_is_a_hard_limit(): void
    {
        $image = imagecreatetruecolor(256, 256);
        for ($y = 0; $y < 256; $y++) {
            for ($x = 0; $x < 256; $x++) {
                imagesetpixel($image, $x, $y, (($x * 1103515245) ^ ($y * 2654435761)) & 0xffffff);
            }
        }
        ob_start();
        imagepng($image);
        $path = $this->file(ob_get_clean());
        imagedestroy($image);
        $profile = $this->profile();
        $profile['format'] = 'png';
        $profile['pickerKb'] = 4;
        $profile['attempts'] = 1;
        $this->expectException(ValidationException::class);
        (new ImageEncoder())->encode($path, 'image/png', $profile);
    }
}
