<?php

declare(strict_types=1);

namespace Local\MediaLibrary\Tests\unit;

use Flarum\Foundation\ValidationException;
use Local\MediaLibrary\Metadata;
use Local\MediaLibrary\References;
use PHPUnit\Framework\TestCase;

final class MetadataTest extends TestCase
{
    public function test_labels_are_canonical_and_behaviours_are_separate(): void
    {
        $this->assertSame(
            ['public' => true, 'emoji' => true, 'categories' => ['猫', '旅行']],
            Metadata::tags([' Public ', 'EMOJI', '猫', '猫', '旅行'])
        );
    }

    public function test_path_like_names_are_rejected(): void
    {
        $this->expectException(ValidationException::class);
        Metadata::name('../image.png');
    }

    public function test_too_many_labels_are_rejected(): void
    {
        $this->expectException(ValidationException::class);
        Metadata::tags(array_fill(0, 13, 'tag'));
    }

    public function test_references_are_extracted_from_formatter_nodes_not_escaped_text(): void
    {
        $uuid = str_repeat('a', 32);
        $this->assertSame([$uuid => ['LMEMOJI' => true]], References::nodes(
            '<r><CODE>&lt;LMMEDIA id="'.$uuid.'"/&gt;</CODE><LMEMOJI id="'.$uuid.'"/></r>'
        ));
    }
}
