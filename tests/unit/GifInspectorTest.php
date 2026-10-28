<?php

declare(strict_types=1);

namespace Local\MediaLibrary\Tests\unit;

use Flarum\Foundation\ValidationException;
use Flarum\Settings\SettingsRepositoryInterface;
use Local\MediaLibrary\GifInspector;
use Local\MediaLibrary\ImageEncoder;
use Local\MediaLibrary\ImageProfiles;
use Local\MediaLibrary\Tests\GifFixture;
use PHPUnit\Framework\TestCase;

final class GifInspectorTest extends TestCase
{
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $path) {
            unlink($path);
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
        $path = tempnam($directory, 'gif-');
        file_put_contents($path, $bytes);
        $this->files[] = $path;
        return $path;
    }

    public function test_animation_is_preserved_but_picker_stays_static(): void
    {
        $bytes = GifFixture::make();
        $path = $this->file($bytes);
        $profile = $this->profile();
        $profile['gifPolicy'] = 'animated';
        $info = (new GifInspector())->inspect($path, $profile);
        $this->assertSame(2, $info['frames']);
        $this->assertSame(200, $info['durationMs']);
        $variants = (new ImageEncoder())->encode($path, 'image/gif', $profile);
        foreach (['inline', 'sticker'] as $kind) {
            $this->assertSame($bytes, $variants[$kind]['bytes']);
            $this->assertSame('image/gif', $variants[$kind]['mime']);
            $this->assertSame(32, $variants[$kind]['width']);
        }
        $this->assertSame('image/webp', $variants['picker']['mime']);
        $this->assertSame($bytes, file_get_contents($path));
        $profile['gifPolicy'] = 'static';
        $static = (new ImageEncoder())->encode($path, 'image/gif', $profile);
        $this->assertSame('image/webp', $static['inline']['mime']);
    }

    public function test_blocks_inside_comments_are_not_mistaken_for_frames(): void
    {
        $comment = "\x2c\x21\xf9\x04fake";
        $bytes = substr(GifFixture::make(), 0, -1)."\x21\xfe".chr(strlen($comment)).$comment."\x00\x3b";
        $this->assertSame(2, (new GifInspector())->inspect($this->file($bytes), $this->profile())['frames']);
    }

    /** @dataProvider budgets */
    public function test_each_resource_budget_is_enforced(string $budget): void
    {
        $profile = $this->profile();
        $bytes = GifFixture::make(256, 256, 3, 100, true);
        switch ($budget) {
            case 'bytes':
                $profile['gifMaxKb'] = 16;
                $bytes = substr($bytes, 0, -1)."\x21\xfe".str_repeat("\xff".str_repeat('x', 255), 70)."\x00\x3b";
                break;
            case 'edge': $profile['gifMaxEdge'] = 32; break;
            case 'pixels': $profile['maxPixels'] = 65535; break;
            case 'frames': $profile['gifMaxFrames'] = 2; break;
            case 'cumulative': $profile['gifMaxPixels'] = 65536; break;
            case 'duration': $profile['gifMaxDuration'] = 1; break;
        }
        $this->expectException(ValidationException::class);
        (new GifInspector())->inspect($this->file($bytes), $profile);
    }

    public static function budgets(): array
    {
        return array_map(fn ($key) => [$key], ['bytes', 'edge', 'pixels', 'frames', 'cumulative', 'duration']);
    }

    /** @dataProvider malformed */
    public function test_malformed_or_out_of_canvas_frames_are_rejected(string $kind): void
    {
        $bytes = GifFixture::make();
        if ($kind === 'truncated') {
            $bytes = substr($bytes, 0, -2);
        } elseif ($kind === 'trailing') {
            $bytes .= 'unexpected data';
        } elseif ($kind === 'frame') {
            $second = strpos($bytes, "\x21\xf9\x04", strpos($bytes, "\x21\xf9\x04") + 1) + 8;
            $bytes = substr_replace($bytes, pack('v', 10000), $second + 5, 2);
        } else {
            $bytes = substr($bytes, 0, -1)."\x21\x01\x00\x3b";
        }
        $this->expectException(ValidationException::class);
        (new GifInspector())->inspect($this->file($bytes), $this->profile());
    }

    public static function malformed(): array
    {
        return [['truncated'], ['trailing'], ['frame'], ['plain-text']];
    }

    public function test_removed_reject_policy_falls_back_to_static_without_enabling_animation(): void
    {
        $settings = $this->createMock(SettingsRepositoryInterface::class);
        $settings->method('get')->willReturnCallback(fn ($key, $default = null) => str_ends_with($key, '.gifPolicy') ? 'reject' : $default);
        $profiles = new ImageProfiles($settings);
        $this->assertSame('static', $profiles->values()['gifPolicy']);
        $this->expectException(ValidationException::class);
        $profiles->validate(['gifPolicy' => 'reject']);
    }
}
