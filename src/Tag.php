<?php

declare(strict_types=1);

namespace Local\MediaLibrary;

use Flarum\Database\AbstractModel;

final class Tag extends AbstractModel
{
    protected $table = 'local_media_tags';
    public $timestamps = false;
}
