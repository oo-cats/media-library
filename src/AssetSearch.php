<?php

declare(strict_types=1);

namespace Local\MediaLibrary;

use Illuminate\Database\Eloquent\Builder;

/** Adds search inside the caller's existing ownership/status scope. */
final class AssetSearch
{
    public static function apply(Builder $query, string $search): void
    {
        $search = trim($search);
        if ($search === '') {
            return;
        }
        if (preg_match('/^[a-f0-9]{32}$/iD', $search)) {
            $query->where('uuid', strtolower($search));
            return;
        }
        $prefix = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_substr($search, 0, 180)).'%';
        $tag = mb_strtolower($search);
        // Keep OR branches grouped so they cannot bypass visibility or the cursor.
        $query->where(function (Builder $match) use ($prefix, $tag) {
            $match->where('name', 'like', $prefix);
            if (in_array($tag, ['public', 'emoji'], true)) {
                $match->orWhere($tag === 'public' ? 'is_public' : 'is_emoji', true);
            } else {
                $match->orWhereHas('tags', fn ($tags) => $tags->where('name', $tag));
            }
        });
    }
}
