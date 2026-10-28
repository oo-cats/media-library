<?php

declare(strict_types=1);

namespace Local\MediaLibrary\Api;

use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Local\MediaLibrary\Library;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class UpdateController implements RequestHandlerInterface
{
    public function __construct(private Library $library)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $asset = $this->library->update(
            $actor,
            (string) ($request->getQueryParams()['uuid'] ?? ''),
            (array) ($request->getParsedBody() ?? []),
            $request->getMethod() === 'DELETE'
        );
        return new JsonResponse($this->library->serialize($asset, $actor), 200, ['Cache-Control' => 'private, no-store']);
    }
}
