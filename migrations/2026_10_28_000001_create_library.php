<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        $schema->table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('local_media_bytes')->default(0);
            $table->unsignedInteger('local_media_count')->default(0);
        });
        $schema->create('local_media_assets', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->char('uuid', 32)->unique();
            $table->unsignedInteger('user_id');
            $table->string('name', 180);
            $table->string('mime', 80);
            $table->unsignedInteger('size');
            $table->char('sha256', 64);
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->boolean('is_public')->default(false);
            $table->boolean('is_emoji')->default(false);
            $table->string('status', 12)->default('active');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->timestamp('archived_at')->nullable();
            $table->index(['user_id', 'status', 'id'], 'lm_owner_page');
            $table->index(['is_public', 'status', 'id'], 'lm_shared_page');
            $table->index(['user_id', 'is_emoji', 'status', 'id'], 'lm_owner_emoji');
            $table->index(['is_public', 'is_emoji', 'status', 'id'], 'lm_shared_emoji');
            $table->index(['status', 'archived_at', 'id'], 'lm_prune');
            $table->index(['user_id', 'status', 'name'], 'lm_owner_name');
            $table->index(['is_public', 'status', 'name'], 'lm_shared_name');
        });
        $schema->create('local_media_tags', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name', 32)->unique();
        });
        $schema->create('local_media_asset_tags', function (Blueprint $table) {
            $table->unsignedBigInteger('asset_id');
            $table->unsignedInteger('tag_id');
            $table->primary(['asset_id', 'tag_id']);
            $table->index(['tag_id', 'asset_id']);
            $table->foreign('asset_id')->references('id')->on('local_media_assets')->onDelete('cascade');
            $table->foreign('tag_id')->references('id')->on('local_media_tags')->onDelete('cascade');
        });
        $schema->create('local_media_post_refs', function (Blueprint $table) {
            $table->unsignedInteger('post_id');
            $table->unsignedBigInteger('asset_id');
            $table->primary(['post_id', 'asset_id']);
            $table->index(['asset_id', 'post_id']);
            $table->foreign('post_id')->references('id')->on('posts')->onDelete('cascade');
            $table->foreign('asset_id')->references('id')->on('local_media_assets');
        });
        $schema->getConnection()->table('group_permission')->insertOrIgnore([
            'group_id' => 3, 'permission' => 'local-media-library.upload',
        ]);
    },
    'down' => function (Builder $schema) {
        $schema->getConnection()->table('group_permission')->where('permission', 'like', 'local-media-library.%')->delete();
        $schema->dropIfExists('local_media_post_refs');
        $schema->dropIfExists('local_media_asset_tags');
        $schema->dropIfExists('local_media_tags');
        $schema->dropIfExists('local_media_assets');
        $schema->table('users', function (Blueprint $table) {
            $table->dropColumn(['local_media_bytes', 'local_media_count']);
        });
    },
];
