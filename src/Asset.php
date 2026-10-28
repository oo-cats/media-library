<?php

declare(strict_types=1);

namespace Local\MediaLibrary;

use Flarum\Database\AbstractModel;

final class Asset extends AbstractModel
{
    protected $table = 'local_media_assets';
    public $timestamps = true;
    protected $casts = [
        'user_id' => 'int', 'size' => 'int', 'version' => 'int', 'derived_bytes' => 'int',
        'width' => 'int', 'height' => 'int', 'is_public' => 'bool', 'is_emoji' => 'bool',
    ];

    public function tags()
    {
        return $this->belongsToMany(Tag::class, 'local_media_asset_tags', 'asset_id', 'tag_id');
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime, 'image/');
    }

    public function posts()
    {
        return $this->belongsToMany(\Flarum\Post\Post::class, 'local_media_post_refs', 'asset_id', 'post_id');
    }
}
