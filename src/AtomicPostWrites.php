<?php

declare(strict_types=1);

namespace Local\MediaLibrary;

use Illuminate\Database\ConnectionInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Keep post content and the reference index in one commit. No retry of external side effects. */
final class AtomicPostWrites implements MiddlewareInterface
{
    public function __construct(private ConnectionInterface $db)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!in_array($request->getAttribute('routeName'), [
            'posts.create', 'posts.update', 'posts.delete', 'discussions.create', 'discussions.delete',
        ], true)) {
            return $handler->handle($request);
        }
        $this->db->beginTransaction();
        try {
            $response = $handler->handle($request);
            if ($response->getStatusCode() >= 400) {
                $this->db->rollBack();
            } else {
                $this->db->commit();
            }
            return $response;
        } catch (\Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }
    }
}
