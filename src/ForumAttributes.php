<?php

declare(strict_types=1);

namespace Local\MediaLibrary;

use Flarum\Api\Serializer\ForumSerializer;

final class ForumAttributes
{
    public function __construct(private Library $library)
    {
    }

    public function __invoke(ForumSerializer $serializer): array
    {
        $actor = $serializer->getActor();
        return [
            'mediaLibraryCanUpload' => $actor->exists && $actor->can('local-media-library.upload'),
            'mediaLibraryMaxFileMb' => $this->library->limit('maxFileMb', 5, 1, 25),
        ];
    }
}
