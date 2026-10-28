<?php

declare(strict_types=1);

namespace Local\MediaLibrary;

use Flarum\Foundation\ValidationException;
use Flarum\Http\UrlGenerator;
use Flarum\Post\CommentPost;
use Flarum\Post\Event\Saving;
use Illuminate\Database\ConnectionInterface;

final class References
{
    public function __construct(private ConnectionInterface $db, private UrlGenerator $url, private ImageProfiles $profiles)
    {
    }

    /** Extract actual formatter nodes; text in code blocks is not an attachment reference. */
    public static function nodes(string $xml, ?int $maxOccurrences = null, ?int $maxStickers = null): array
    {
        if (!str_contains($xml, '<LM')) {
            return [];
        }
        $document = new \DOMDocument();
        if (!$document->loadXML($xml, LIBXML_NONET)) {
            throw new \RuntimeException('Invalid formatted post.');
        }
        $result = [];
        $occurrences = $stickers = 0;
        foreach ((new \DOMXPath($document))->query('//LMMEDIA | //LMFILE | //LMEMOJI | //LMSTICKER') as $node) {
            if (in_array($node->nodeName, ['LMEMOJI', 'LMSTICKER'], true)) {
                $occurrences++;
            }
            if ($node->nodeName === 'LMSTICKER') {
                $stickers++;
            }
            $uuid = $node->getAttribute('id');
            if (preg_match('/^[a-f0-9]{32}$/D', $uuid)) {
                $result[$uuid][$node->nodeName] = true;
            }
        }
        if (count($result) > 50) {
            throw new ValidationException(['content' => '一篇帖子最多引用 50 份不同附件。']);
        }
        if (($maxOccurrences !== null && $occurrences > $maxOccurrences) || ($maxStickers !== null && $stickers > $maxStickers)) {
            throw new ValidationException(['content' => '图片表情或贴纸出现次数超过管理员限制。']);
        }
        return $result;
    }

    public function __invoke(Saving $event): void
    {
        $post = $event->post;
        if (!$post instanceof CommentPost || ($post->exists && !$post->isDirty('content'))) {
            return;
        }
        $this->rejectUnmanagedLinks($post->parsed_content ?? '');
        $limits = $this->profiles->values();
        $nodes = self::nodes($post->parsed_content ?? '', $limits['maxOccurrences'], $limits['maxStickers']);
        $old = self::nodes((string) $post->getRawOriginal('content'));
        if (!$nodes && !$old) {
            return;
        }
        // Core API writes are wrapped by AtomicPostWrites. Custom importers must also use a transaction.
        if ($this->db->transactionLevel() === 0) {
            throw new \LogicException('Attachment post writes require a database transaction.');
        }
        $assets = Asset::whereIn('uuid', array_keys($nodes))->orderBy('id')->lockForUpdate()->get()->keyBy('uuid');
        $existing = $post->exists
            ? $this->db->table('local_media_post_refs')->where('post_id', $post->id)->pluck('asset_id')->all()
            : [];
        $existing = array_fill_keys($existing, true);
        $rows = [];
        foreach ($nodes as $uuid => $modes) {
            $asset = $assets->get($uuid);
            if (!$asset || $asset->status === 'purging') {
                throw new ValidationException(['content' => '附件不存在、已归档，或没有复用许可。']);
            }
            if ((isset($modes['LMMEDIA']) || isset($modes['LMEMOJI']) || isset($modes['LMSTICKER'])) && !$asset->isImage()) {
                throw new ValidationException(['content' => '非图片附件请使用 file 引用。']);
            }
            foreach (array_keys($modes) as $mode) {
                $preserved = isset($existing[$asset->id], $old[$uuid][$mode]);
                if (!$preserved && ($asset->status !== 'active' || !$event->actor->exists
                    || ((int) $asset->user_id !== (int) $event->actor->id && !$asset->is_public))) {
                    throw new ValidationException(['content' => '附件不存在、已归档，或没有复用许可。']);
                }
                if (in_array($mode, ['LMEMOJI', 'LMSTICKER'], true) && !$preserved) {
                    if (!$asset->is_emoji || ($this->profiles->enabled() && !$asset->emoji_profile)) {
                        throw new ValidationException(['content' => '该附件不是就绪的图片表情，请先完成处理。']);
                    }
                }
            }
            $rows[] = ['asset_id' => $asset->id];
        }
        $post->afterSave(function (CommentPost $saved) use ($rows) {
            $ids = array_column($rows, 'asset_id');
            $query = $this->db->table('local_media_post_refs')->where('post_id', $saved->id);
            if ($ids) {
                $query->whereNotIn('asset_id', $ids);
            }
            $query->delete();
            if ($ids) {
                $this->db->table('local_media_post_refs')->insertOrIgnore(
                    array_map(fn ($row) => $row + ['post_id' => $saved->id], $rows)
                );
            }
        });
    }

    /** A copied preview URL must not bypass reuse checks or the reference index. */
    private function rejectUnmanagedLinks(string $xml): void
    {
        if (!str_contains(rawurldecode($xml), 'media-library/files/')) {
            return;
        }
        $base = parse_url($this->url->to('api')->path('media-library/files').'/');
        $document = new \DOMDocument();
        $document->loadXML($xml, LIBXML_NONET);
        foreach ((new \DOMXPath($document))->query('//@src | //@url | //@href') as $attribute) {
            $parts = parse_url($attribute->value);
            if ($parts === false) {
                continue;
            }
            $scheme = strtolower($parts['scheme'] ?? $base['scheme']);
            $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
            $basePort = $base['port'] ?? ($base['scheme'] === 'https' ? 443 : 80);
            $sameOrigin = !isset($parts['host']) || (
                strcasecmp($parts['host'], $base['host']) === 0
                && $scheme === strtolower($base['scheme']) && $port === $basePort
            );
            $segments = [];
            foreach (explode('/', rawurldecode($parts['path'] ?? '')) as $segment) {
                if ($segment === '..') {
                    array_pop($segments);
                } elseif ($segment !== '' && $segment !== '.') {
                    $segments[] = $segment;
                }
            }
            if ($sameOrigin && str_starts_with('/'.implode('/', $segments), $base['path'])) {
                throw new ValidationException(['content' => '请用附件库生成的 media/file/memoji/msticker 引用插入附件，不要粘贴预览下载链接。']);
            }
        }
    }
}
