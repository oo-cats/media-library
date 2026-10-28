<?php

declare(strict_types=1);

namespace Local\MediaLibrary\Api;

use Flarum\Foundation\ValidationException;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Local\MediaLibrary\Library;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class UploadController implements RequestHandlerInterface
{
    public function __construct(private Library $library)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $file = $request->getUploadedFiles()['file'] ?? null;
        if (!$file instanceof UploadedFileInterface) {
            throw new ValidationException(['file' => '请选择附件；超出 PHP 请求上限也可能导致文件缺失。']);
        }
        $body = $request->getParsedBody() ?? [];
        $tags = $body['tags'] ?? [];
        // Multipart form sends the array as JSON; JSON requests are not used for file bodies.
        if (is_string($tags)) {
            $tags = json_decode($tags, true);
        }
        if (!is_array($tags)) {
            throw new ValidationException(['tags' => '标签格式错误。']);
        }
        $actor = RequestUtil::getActor($request);
        $asset = $this->library->upload($actor, $file, $tags);
        return new JsonResponse($this->library->serialize($asset, $actor), 201, ['Cache-Control' => 'private, no-store']);
    }
}
