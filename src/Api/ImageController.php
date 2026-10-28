<?php

declare(strict_types=1);

namespace Local\MediaLibrary\Api;

use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Local\MediaLibrary\ImageCatalog;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class ImageController implements RequestHandlerInterface
{
    public function __construct(private ImageCatalog $catalog)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();
        $actor = RequestUtil::getActor($request);
        $data = isset($params['uuid'])
            ? $this->catalog->find($actor, (string) $params['uuid'])
            : $this->catalog->page($actor, $params);
        return new JsonResponse($data, 200, ['Cache-Control' => 'private, no-store']);
    }
}
