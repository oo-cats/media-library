<?php

declare(strict_types=1);

namespace Local\MediaLibrary\Api;

use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Local\MediaLibrary\Library;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class ListController implements RequestHandlerInterface
{
    public function __construct(private Library $library)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return new JsonResponse($this->library->page(RequestUtil::getActor($request), $request->getQueryParams()), 200, [
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
