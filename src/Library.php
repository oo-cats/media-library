<?php

declare(strict_types=1);

namespace Local\MediaLibrary;

use Carbon\Carbon;
use Flarum\Foundation\ValidationException;
use Flarum\Http\UrlGenerator;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Psr\Http\Message\UploadedFileInterface;

final class Library
{
    public function __construct(
        private ConnectionInterface $db,
        private PrivateStorage $storage,
        private SettingsRepositoryInterface $settings,
        private UrlGenerator $url,
        private ImageProfiles $profiles,
        private Variants $variants
    ) {
    }

    public function upload(User $actor, UploadedFileInterface $file, array $tags): Asset
    {
        $actor->assertRegistered();
        $actor->assertCan('local-media-library.upload');
        $name = Metadata::name(basename(str_replace('\\', '/', $file->getClientFilename() ?? 'attachment')));
        $labels = Metadata::tags($tags);
        $user = User::findOrFail($actor->id);
        $remaining = $this->limit('quotaMb', 100, 1, 102400) * 1048576 - (int) $user->local_media_bytes;
        if ($remaining <= 0 || (int) $user->local_media_count >= $this->limit('maxFiles', 1000, 1, 100000)) {
            throw new ValidationException(['file' => '个人附件空间或数量已达上限。']);
        }
        $uuid = bin2hex(random_bytes(16));
        $data = $this->storage->receive($file, $uuid, min($remaining, $this->limit('maxFileMb', 5, 1, 25) * 1048576));
        try {
            $asset = $this->db->transaction(function () use ($actor, $uuid, $name, $data, $labels) {
                // One indexed user row serializes quota changes, including concurrent uploads.
                $user = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
                if ((int) $user->local_media_bytes + $data['size'] > $this->limit('quotaMb', 100, 1, 102400) * 1048576
                    || (int) $user->local_media_count >= $this->limit('maxFiles', 1000, 1, 100000)) {
                    throw new ValidationException(['file' => '个人附件空间或数量已达上限，归档文件清理后释放额度。']);
                }
                $asset = new Asset();
                foreach ($data + ['uuid' => $uuid, 'user_id' => $actor->id, 'name' => $name, 'status' => 'active', 'version' => 1] as $key => $value) {
                    $asset->$key = $value;
                }
                $this->setLabels($asset, $labels);
                $user->local_media_bytes = (int) $user->local_media_bytes + $asset->size;
                $user->local_media_count = (int) $user->local_media_count + 1;
                $user->save();
                return $asset;
            });
        } catch (\Throwable $error) {
            $this->storage->remove($uuid);
            throw $error;
        }
        return $this->processEmoji($actor, $asset);
    }

    public function update(User $actor, string $uuid, array $input, bool $archive = false): Asset
    {
        $actor->assertRegistered();
        $asset = $this->db->transaction(function () use ($actor, $uuid, $input, $archive) {
            $asset = Asset::where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            if ((int) $asset->user_id !== (int) $actor->id) {
                $actor->assertCan('local-media-library.manage');
            }
            if ((int) ($input['version'] ?? 0) !== $asset->version) {
                throw new \Flarum\Foundation\ValidationException(['version' => '附件已被修改，请刷新后再操作。']);
            }
            if ($asset->status === 'purging') {
                throw new ValidationException(['file' => '附件正在物理清理，不能恢复。']);
            }
            if ($archive) {
                $asset->status = 'archived';
                $asset->archived_at = Carbon::now();
            } else {
                if (array_key_exists('name', $input)) {
                    $asset->name = Metadata::name($input['name']);
                }
                if (($input['restore'] ?? false) === true) {
                    $asset->status = 'active';
                    $asset->archived_at = null;
                }
            }
            $asset->version++;
            if (!$archive && array_key_exists('tags', $input)) {
                $this->setLabels($asset, Metadata::tags($input['tags']));
            } else {
                $asset->save();
            }
            return $asset;
        });
        return $this->processEmoji($actor, $asset);
    }

    private function processEmoji(User $actor, Asset $asset): Asset
    {
        if ($this->profiles->enabled() && $asset->is_emoji && $asset->status === 'active' && !$asset->emoji_profile) {
            try {
                return $this->variants->prepare($actor, $asset);
            } catch (ValidationException $error) {
                $asset->processing_error = '原附件已保存，但表情处理未完成；请在表情选择器中重试并查看原因。';
            }
        }
        return $asset;
    }

    private function setLabels(Asset $asset, array $labels): void
    {
        if ($labels['emoji'] && !$asset->isImage()) {
            throw new ValidationException(['tags' => '只有图片附件能设置 emoji 标签。']);
        }
        $asset->is_public = $labels['public'];
        $asset->is_emoji = $labels['emoji'];
        $asset->save();
        $ids = [];
        sort($labels['categories'], SORT_STRING);
        foreach ($labels['categories'] as $name) {
            $this->db->table('local_media_tags')->insertOrIgnore(['name' => $name]);
            $ids[] = (int) $this->db->table('local_media_tags')->where('name', $name)->value('id');
        }
        $asset->tags()->sync($ids);
        $asset->unsetRelation('tags');
    }

    /** Keyset pagination; no OFFSET, total count, or filesystem enumeration. */
    public function page(User $actor, array $params, bool $includeCounts = true): array
    {
        $actor->assertRegistered();
        $scope = $params['scope'] ?? 'mine';
        $query = Asset::query()->with('tags');
        if ($includeCounts) {
            $query->withCount('posts');
        }
        if ($scope === 'shared') {
            $query->where('is_public', true)->where('status', 'active');
        } else {
            $query->where('user_id', $actor->id)->where('status', $scope === 'archived' ? 'archived' : 'active');
        }
        if (($params['emoji'] ?? '') === '1') {
            $query->where('is_emoji', true);
        }
        $tag = mb_strtolower(trim((string) ($params['tag'] ?? '')));
        if ($tag !== '') {
            if (in_array($tag, ['public', 'emoji'], true)) {
                $query->where($tag === 'public' ? 'is_public' : 'is_emoji', true);
            } else {
                $query->whereHas('tags', fn ($q) => $q->where('name', mb_strtolower($tag)));
            }
        }
        AssetSearch::apply($query, (string) ($params['q'] ?? ''));
        if (isset($params['before'])) {
            $query->where('id', '<', max(0, (int) $params['before']));
        }
        $limit = min(50, max(1, (int) ($params['limit'] ?? 24)));
        $rows = $query->orderByDesc('id')->limit($limit + 1)->get();
        $more = $rows->count() > $limit;
        $rows = $rows->take($limit);
        return [
            'items' => $rows->map(fn (Asset $asset) => $this->serialize($asset, $actor, $includeCounts))->values()->all(),
            'next' => $more ? (string) $rows->last()->id : null,
        ];
    }

    public function serialize(Asset $asset, User $actor, bool $includeCounts = true): array
    {
        $tags = $asset->tags->pluck('name')->all();
        if ($asset->is_public) {
            $tags[] = 'public';
        }
        if ($asset->is_emoji) {
            $tags[] = 'emoji';
        }
        $file = $this->url->to('api')->route('local-media-library.file', ['uuid' => $asset->uuid]);
        return [
            'id' => $asset->uuid, 'name' => $asset->name, 'mime' => $asset->mime, 'size' => $asset->size,
            'width' => $asset->width, 'height' => $asset->height,
            'tags' => $tags, 'status' => $asset->status, 'version' => $asset->version,
            'ownerId' => $asset->user_id, 'image' => $asset->isImage(),
            'referenceCount' => $includeCounts ? ($asset->posts_count ?? $asset->posts()->count()) : null,
            'emojiReady' => (bool) $asset->emoji_profile, 'derivedBytes' => (int) $asset->derived_bytes,
            'processingError' => $asset->processing_error ?? null,
            'url' => $file, 'thumbnail' => $asset->isImage() ? $file.'?thumbnail=1' : null,
            'canManage' => $asset->user_id === (int) $actor->id || $actor->can('local-media-library.manage'),
        ];
    }

    public function limit(string $key, int $default, int $min, int $max): int
    {
        return max($min, min($max, (int) ($this->settings->get('local-media-library.'.$key) ?? $default)));
    }
}
