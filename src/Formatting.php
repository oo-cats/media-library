<?php

declare(strict_types=1);

namespace Local\MediaLibrary;

use Flarum\Post\CommentPost;
use Illuminate\Database\ConnectionInterface;
use Psr\Http\Message\ServerRequestInterface;
use s9e\TextFormatter\Renderer;

final class Formatting
{
    public function __construct(private ConnectionInterface $db) {}

    public function __invoke(Renderer $renderer, mixed $context, string $xml, ?ServerRequestInterface $request = null): string
    {
        $ids = array_keys(References::nodes($xml));
        if (!$ids) {
            return $xml;
        }
        $assets = Asset::whereIn('uuid', $ids)->get()->keyBy('uuid');
        $variants = $this->db->table('local_media_variants')->whereIn('asset_id', $assets->pluck('id')->all())
            ->whereIn('kind', ['inline', 'sticker'])->get()
            ->keyBy(fn ($row) => $row->asset_id.':'.$row->profile.':'.$row->kind);
        $document = new \DOMDocument();
        $document->loadXML($xml, LIBXML_NONET);
        $xpath = new \DOMXPath($document);
        foreach ($xpath->query('//LMMEDIA | //LMFILE | //LMEMOJI | //LMSTICKER') as $node) {
            $asset = $assets->get($node->getAttribute('id'));
            $node->setAttribute('post', $context instanceof CommentPost ? (string) $context->id : '');
            $node->setAttribute('name', $asset ? $asset->name : '附件已移除');
            if ($asset) {
                $kind = $node->nodeName === 'LMSTICKER' ? 'sticker' : 'inline';
                $variant = in_array($node->nodeName, ['LMEMOJI', 'LMSTICKER'], true)
                    ? $variants->get($asset->id.':'.$asset->emoji_profile.':'.$kind) : null;
                $node->setAttribute('width', (string) ($variant->width ?? $asset->width ?? ''));
                $node->setAttribute('height', (string) ($variant->height ?? $asset->height ?? ''));
            }
        }
        foreach (iterator_to_array($xpath->query('//LMSTICKER')) as $node) {
            if ($node->parentNode->nodeName === 'LMSTICKERGROUP') {
                continue;
            }
            $group = $document->createElement('LMSTICKERGROUP');
            $node->parentNode->insertBefore($group, $node);
            while ($node && ($node->nodeName === 'LMSTICKER' || ($node instanceof \DOMText && trim($node->textContent) === ''))) {
                $next = $node->nextSibling;
                $group->appendChild($node);
                $node = $next;
            }
        }
        return $document->saveXML($document->documentElement);
    }
}
