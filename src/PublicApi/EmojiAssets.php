<?php

declare(strict_types=1);

namespace Local\MediaLibrary\PublicApi;

use Flarum\Foundation\ValidationException;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Local\MediaLibrary\Asset;
use Local\MediaLibrary\ImageProfiles;
use Local\MediaLibrary\Library;
use Local\MediaLibrary\Variants;

/** Public API v1: detached arrays only; callers never depend on the private models or tables. */
final class EmojiAssets
{
    public const VERSION = 1;

    public function __construct(
        private Library $library,
        private Variants $variants,
        private ImageProfiles $profiles,
        private ConnectionInterface $db
    ) {}

    public function page(User $actor, array $params): array
    {
        if (!in_array($params['scope'] ?? 'mine', ['mine', 'shared'], true)) {
            throw new ValidationException(['scope' => '无效的图片表情范围。']);
        }
        $page = $this->library->page($actor, array_replace($params, ['emoji' => '1']), false);
        $page['items'] = array_map(fn ($item) => $this->item($item), $page['items']);
        return $page;
    }

    public function resolve(User $actor, array $ids): array
    {
        $actor->assertRegistered();
        if (count($ids) > 50 || array_filter($ids, fn ($id) => !is_string($id) || !preg_match('/^[a-f0-9]{32}$/D', $id))) {
            throw new ValidationException(['items' => '无效的附件标识或数量。']);
        }
        return Asset::whereIn('uuid', $ids)->where('is_emoji', true)->where('status', 'active')
            ->where(fn ($q) => $q->where('user_id', $actor->id)->orWhere('is_public', true))
            ->with('tags')->get()
            ->map(fn ($asset) => $this->item($this->library->serialize($asset, $actor, false)))->all();
    }

    public function withManaged(User $actor, string $id, int $version, callable $callback): void
    {
        $actor->assertRegistered();
        $source = Asset::where('uuid', $id)->firstOrFail();
        $this->db->transaction(function () use ($actor, $source, $version, $callback) {
            User::whereKey($source->user_id)->lockForUpdate()->firstOrFail();
            $asset = Asset::whereKey($source->id)->lockForUpdate()->firstOrFail();
            if ($asset->user_id !== (int) $actor->id) {
                $actor->assertCan('local-media-library.manage');
            }
            if ($asset->status !== 'active' || !$asset->is_emoji || $asset->version !== $version) {
                throw new ValidationException(['version' => '附件已改变或不再是可用表情，请刷新。']);
            }
            $callback($this->item($this->library->serialize($asset, $actor)));
        });
    }

    public function prepare(User $actor, string $id): void
    {
        $this->variants->prepare($actor, Asset::where('uuid', $id)->firstOrFail());
    }

    public function settings(): array { return $this->profiles->values(); }
    /** Metadata reconciliation only. Exposes no filename, URL, ownership or permissions. */
    public function registeredIds(array $ids): array
    {
        if (count($ids) > 100 || array_filter($ids, fn ($id) => !is_string($id) || !preg_match('/^[a-f0-9]{32}$/D', $id))) {
            throw new \InvalidArgumentException('Invalid metadata ID batch.');
        }
        return Asset::whereIn('uuid', $ids)->pluck('uuid')->all();
    }
    public function settingsSchema(): array { return ImageProfiles::FIELDS; }
    public function validateSettings(array $input): array { return $this->profiles->validate($input); }
    public function saveSettings(array $input): void { $this->profiles->save($input); }

    private function item(array $asset): array
    {
        return [
            'kind' => 'image', 'id' => $asset['id'], 'name' => $asset['name'],
            'tags' => $asset['tags'], 'ownerId' => $asset['ownerId'], 'canManage' => $asset['canManage'],
            'assetVersion' => $asset['version'], 'width' => $asset['width'], 'height' => $asset['height'],
            'size' => $asset['size'], 'derivedBytes' => $asset['derivedBytes'], 'ready' => $asset['emojiReady'],
            'preview' => $asset['emojiReady'] ? $asset['url'].'?variant=picker' : $asset['thumbnail'],
        ];
    }
}
