<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        $schema->table('local_media_assets', function (Blueprint $table) {
            $table->char('emoji_profile', 64)->nullable();
            $table->unsignedBigInteger('derived_bytes')->default(0);
        });
        $schema->create('local_media_variants', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('asset_id');
            $table->char('profile', 64);
            $table->string('kind', 8);
            $table->string('mime', 32);
            $table->unsignedInteger('size');
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['asset_id', 'profile', 'kind'], 'lm_variant_unique');
            $table->foreign('asset_id')->references('id')->on('local_media_assets')->onDelete('cascade');
        });
    },
    'down' => function (Builder $schema) {
        // Rollback does not delete files; use normal protected lifecycle maintenance.
        $schema->dropIfExists('local_media_variants');
        $schema->table('local_media_assets', function (Blueprint $table) {
            $table->dropColumn(['emoji_profile', 'derived_bytes']);
        });
    },
];
