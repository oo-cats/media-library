<?php

use Flarum\Extend;
use Local\MediaLibrary\Api;
use Local\MediaLibrary\AtomicPostWrites;
use Local\MediaLibrary\Formatting;
use Local\MediaLibrary\PruneCommand;
use Local\MediaLibrary\References;
use Local\MediaLibrary\ConfigureFormatting;
use Local\MediaLibrary\ForumAttributes;

return [
    (new Extend\Routes('api'))
        ->get('/media-library/images', 'local-media-library.images', Api\ImageController::class)
        ->get('/media-library/images/{uuid}', 'local-media-library.image', Api\ImageController::class)
        ->get('/media-library/assets', 'local-media-library.list', Api\ListController::class)
        ->post('/media-library/assets', 'local-media-library.upload', Api\UploadController::class)
        ->patch('/media-library/assets/{uuid}', 'local-media-library.update', Api\UpdateController::class)
        ->delete('/media-library/assets/{uuid}', 'local-media-library.archive', Api\UpdateController::class)
        ->get('/media-library/files/{uuid}', 'local-media-library.file', Api\FileController::class),
    (new Extend\Frontend('forum'))->js(__DIR__.'/js/dist/forum.js')->css(__DIR__.'/resources/forum.less'),
    (new Extend\Frontend('admin'))->js(__DIR__.'/js/dist/admin.js'),
    (new Extend\Locales(__DIR__.'/resources/locale')),
    (new Extend\Middleware('api'))->add(AtomicPostWrites::class),
    (new Extend\Event())->listen(\Flarum\Post\Event\Saving::class, References::class),
    (new Extend\Formatter())->configure(ConfigureFormatting::class)->render(Formatting::class),
    (new Extend\ApiSerializer(\Flarum\Api\Serializer\ForumSerializer::class))->attributes(ForumAttributes::class),
    (new Extend\Console())->command(PruneCommand::class)->command(\Local\MediaLibrary\OrphansCommand::class)->command(\Local\MediaLibrary\RebuildEmojiCommand::class),
    (new Extend\Settings())
        ->default('local-media-library.maxFileMb', 5)
        ->default('local-media-library.quotaMb', 100)
        ->default('local-media-library.maxFiles', 1000),
    (new Extend\Model(\Flarum\User\User::class))
        ->cast('local_media_bytes', 'int')->cast('local_media_count', 'int'),
];
