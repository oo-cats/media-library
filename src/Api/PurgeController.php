<?php

declare(strict_types=1);

namespace Local\MediaLibrary\Api;

use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Local\MediaLibrary\Library;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class PurgeController implements RequestHandlerInterface
{
    public function __construct(private Library $library)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $input = (array) ($request->getParsedBody() ?? []);
        $this->library->purge(
            RequestUtil::getActor($request),
            (string) ($request->getQueryParams()['uuid'] ?? ''),
            (int) ($input['version'] ?? 0)
        );
        return new JsonResponse(['deleted' => true], 200, ['Cache-Control' => 'private, no-store']);
    }
}
