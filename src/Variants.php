<?php

declare(strict_types=1);

namespace Local\MediaLibrary;

use Carbon\Carbon;
use Flarum\Foundation\ValidationException;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;

final class Variants
{
    public const KINDS = ['picker', 'inline', 'sticker'];

    public function __construct(
        private ConnectionInterface $db,
        private PrivateStorage $storage,
        private ImageEncoder $encoder,
        private ImageProfiles $profiles,
        private SettingsRepositoryInterface $settings
    ) {}

    public function prepare(User $actor, Asset $asset, bool $rebuild = false): Asset
    {
        $actor->assertRegistered();
        if ($actor->id !== $asset->user_id) {
            $actor->assertCan('local-media-library.manage');
        }
        if (!$asset->is_emoji || $asset->status !== 'active' || !$asset->isImage()) {
            throw new ValidationException(['file' => '仅能处理未归档的图片表情。']);
        }
        if ($asset->emoji_profile && !$rebuild) {
            return $asset;
        }
        $profile = $this->profiles->values();
        if ($asset->size > $profile['maxFileMb'] * 1048576) {
            throw new ValidationException(['file' => '原文件超过当前表情处理上限。']);
        }
        $hash = $this->profiles->hash($profile);
        $existing = $this->db->table('local_media_variants')->where('asset_id', $asset->id)->where('profile', $hash)->get();
        if ($asset->emoji_profile === $hash && $existing->count() === 3
            && $existing->every(fn ($row) => is_file($this->storage->variantPath($asset->uuid, $hash, $row->kind)))) {
            return $asset;
        }
        // Expensive decoding is outside row locks. The commit below rechecks ownership and quota.
        $encoded = $this->encoder->encode($this->storage->path($asset->uuid), $asset->mime, $profile);
        return $this->db->transaction(function () use ($actor, $asset, $hash, $encoded) {
            $user = User::whereKey($asset->user_id)->lockForUpdate()->firstOrFail();
            $locked = Asset::whereKey($asset->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'active' || !$locked->is_emoji) {
                throw new ValidationException(['file' => '处理期间附件状态已改变，请刷新。']);
            }
            if ($actor->id !== $locked->user_id) {
                $actor->assertCan('local-media-library.manage');
            }
            $previous = $this->db->table('local_media_variants')->where('asset_id', $asset->id)->where('profile', $hash)->get()->keyBy('kind');
            $newBytes = array_sum(array_column($encoded, 'size'));
            $delta = $newBytes - (int) $previous->sum('size');
            $quota = max(1, min(102400, (int) $this->settings->get('local-media-library.quotaMb', 100))) * 1048576;
            if ((int) $user->local_media_bytes + $delta > $quota) {
                throw new ValidationException(['file' => '生成处理图所需空间超过附件配额。']);
            }
            $written = [];
            try {
                foreach ($encoded as $kind => $data) {
                    $path = $this->storage->variantPath($locked->uuid, $hash, $kind);
                    // Reuse verified output of a competing request that committed first.
                    if (!$previous->has($kind) || !is_file($path)) {
                        $this->storage->writeVariant($path, $data['bytes']);
                        $written[] = $path;
                    }
                    $this->db->table('local_media_variants')->updateOrInsert(
                        ['asset_id' => $locked->id, 'profile' => $hash, 'kind' => $kind],
                        array_diff_key($data, ['bytes' => true]) + ['created_at' => Carbon::now()]
                    );
                }
                $locked->emoji_profile = $hash;
                $locked->derived_bytes = max(0, (int) $locked->derived_bytes + $delta);
                $locked->version++;
                $locked->save();
                $user->local_media_bytes = max(0, (int) $user->local_media_bytes + $delta);
                $user->save();
                return $locked;
            } catch (\Throwable $error) {
                // Compensate before row locks are released; never unlink a later writer's output.
                foreach ($written as $path) {
                    if (is_file($path)) {
                        unlink($path);
                    }
                }
                throw $error;
            }
        });
    }

    /** Only retired profiles older than a day; current output remains available. */
    public function pruneRetired(Asset $asset): int
    {
        return $this->db->transaction(function () use ($asset) {
            $user = User::whereKey($asset->user_id)->lockForUpdate()->firstOrFail();
            $locked = Asset::whereKey($asset->id)->lockForUpdate()->firstOrFail();
            $rows = $this->db->table('local_media_variants')->where('asset_id', $locked->id)
                ->where('profile', '<>', $locked->emoji_profile)->where('created_at', '<', Carbon::now()->subDay())->get();
            $bytes = 0;
            foreach ($rows as $row) {
                $path = $this->storage->variantPath($locked->uuid, $row->profile, $row->kind);
                if (is_file($path) && !unlink($path)) {
                    throw new \RuntimeException('Cannot remove retired variant; retry maintenance.');
                }
                $bytes += $row->size;
            }
            if ($rows->isNotEmpty()) {
                $this->db->table('local_media_variants')->whereIn('id', $rows->pluck('id')->all())->delete();
                $locked->derived_bytes = max(0, (int) $locked->derived_bytes - $bytes);
                $locked->save();
                $user->local_media_bytes = max(0, (int) $user->local_media_bytes - $bytes);
                $user->save();
            }
            return $bytes;
        });
    }

    /** Maintenance-only reconciliation of crash leftovers, with one variant query per batch. */
    public function orphanPaths(array $assets): array
    {
        if (!$assets) {
            return [];
        }
        $known = [];
        foreach ($this->db->table('local_media_variants')->whereIn('asset_id', array_keys($assets))->get() as $row) {
            $known[$this->storage->variantPath($assets[$row->asset_id], $row->profile, $row->kind)] = true;
        }
        $orphans = [];
        foreach ($assets as $uuid) {
            foreach ($this->storage->variantPaths($uuid) as $path) {
                if (!isset($known[$path]) && filemtime($path) < time() - 86400) {
                    $orphans[] = $path;
                }
            }
        }
        return $orphans;
    }
}
