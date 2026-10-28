<?php

declare(strict_types=1);

namespace Local\MediaLibrary;

use Flarum\Http\UrlGenerator;
use Flarum\User\User;
use Illuminate\Support\Collection;

/** Only the metadata needed to choose a picture, without management data or counts. */
final class ImageCatalog
{
    public function __construct(private UrlGenerator $url)
    {
    }

    public function page(User $actor, array $params): array
    {
        $actor->assertRegistered();
        $limit = min(50, max(1, (int) ($params['limit'] ?? 24)));
        $tag = mb_strtolower(trim((string) ($params['tag'] ?? '')));
        $rows = new Collection();
        // Two indexed ranges, each contributing at most limit+1 rows, are
        // sufficient to produce the next page without a broad owner OR public query.
        foreach (['mine', 'shared'] as $scope) {
            $query = Asset::where('status', 'active')->where('mime', 'like', 'image/%');
            if ($scope === 'mine') {
                $query->where('user_id', $actor->id);
            } else {
                $query->where('is_public', true)->where('user_id', '<>', $actor->id);
            }
            if ($tag !== '') {
                if (in_array($tag, ['public', 'emoji'], true)) {
                    $query->where($tag === 'public' ? 'is_public' : 'is_emoji', true);
                } else {
                    $query->whereHas('tags', fn ($q) => $q->where('name', $tag));
                }
            }
            AssetSearch::apply($query, (string) ($params['q'] ?? ''));
            if (isset($params['before'])) {
                $query->where('id', '<', max(0, (int) $params['before']));
            }
            $rows = $rows->concat($query->select(['id', 'uuid', 'name'])->orderByDesc('id')->limit($limit + 1)->get());
        }
        $rows = $rows->sortByDesc('id');
        $more = $rows->count() > $limit;
        $rows = $rows->take($limit);
        return [
            'items' => $rows->map(fn (Asset $asset) => $this->serialize($asset))->values()->all(),
            'next' => $more ? (string) $rows->last()->id : null,
        ];
    }

    public function find(User $actor, string $uuid): array
    {
        $actor->assertRegistered();
        $asset = Asset::where('uuid', $uuid)->where('status', 'active')->where('mime', 'like', 'image/%')
            ->where(fn ($query) => $query->where('user_id', $actor->id)->orWhere('is_public', true))
            ->firstOrFail();
        return $this->serialize($asset);
    }

    private function serialize(Asset $asset): array
    {
        $url = $this->url->to('api')->route('local-media-library.file', ['uuid' => $asset->uuid]);
        return ['id' => $asset->uuid, 'name' => $asset->name, 'thumbnail' => $url.'?thumbnail=1'];
    }
}
