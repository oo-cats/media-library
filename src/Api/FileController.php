<?php

declare(strict_types=1);

namespace Local\MediaLibrary\Api;

use Flarum\Http\RequestUtil;
use Flarum\Post\PostRepository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Laminas\Diactoros\Response;
use Laminas\Diactoros\Stream;
use Local\MediaLibrary\Asset;
use Local\MediaLibrary\PrivateStorage;
use Local\MediaLibrary\ImageProfiles;
use Local\MediaLibrary\Variants;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class FileController implements RequestHandlerInterface
{
    public function __construct(private ConnectionInterface $db, private PrivateStorage $storage, private PostRepository $posts, private ImageProfiles $profiles)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();
        $actor = RequestUtil::getActor($request);
        $asset = Asset::where('uuid', (string) ($params['uuid'] ?? ''))->firstOrFail();
        if ($asset->status === 'purging') {
            throw new ModelNotFoundException();
        }
        $postId = (int) ($params['post'] ?? 0);
        if ($postId > 0) {
            // Both checks are required: a visible unrelated post must not unlock a guessed ID.
            $this->posts->findOrFail($postId, $actor);
            if (!$this->db->table('local_media_post_refs')->where('post_id', $postId)->where('asset_id', $asset->id)->exists()) {
                throw new ModelNotFoundException();
            }
        } elseif ((int) $actor->id !== $asset->user_id && !(
            $actor->exists && $asset->is_public && $asset->status === 'active'
        ) && !$actor->can('local-media-library.manage')) {
            throw new ModelNotFoundException();
        }
        $thumbnail = ($params['thumbnail'] ?? '') === '1' && $asset->isImage();
        $path = $this->storage->path($asset->uuid, $thumbnail);
        $mime = $thumbnail ? 'image/webp' : $asset->mime;
        $kind = (string) ($params['variant'] ?? 'original');
        if ($kind !== 'original') {
            if (!in_array($kind, Variants::KINDS, true) || !$asset->isImage()) {
                throw new ModelNotFoundException();
            }
            if ($asset->emoji_profile) {
                $variant = $this->db->table('local_media_variants')->where('asset_id', $asset->id)
                    ->where('profile', $asset->emoji_profile)->where('kind', $kind)->first();
                if (!$variant) {
                    throw new ModelNotFoundException();
                }
                $path = $this->storage->variantPath($asset->uuid, $asset->emoji_profile, $kind);
                $mime = $variant->mime;
            } elseif ($this->profiles->enabled() && $postId === 0) {
                throw new ModelNotFoundException();
            }
        }
        if (!is_file($path)) {
            throw new ModelNotFoundException();
        }
        return new Response(new Stream($path, 'rb'), 200, [
            'Content-Type' => $mime,
            'Content-Length' => (string) filesize($path),
            'Content-Disposition' => ($asset->isImage() ? 'inline' : 'attachment')."; filename=\"attachment\"; filename*=UTF-8''".rawurlencode($asset->name),
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }
}
