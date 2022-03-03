<?php declare(strict_types=1);

namespace Reconmap\Controllers;

use Fig\Http\Message\StatusCodeInterface;
use GuzzleHttp\Psr7\Response;
use Monolog\Logger;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Reconmap\Models\User;
use Reconmap\Services\ContainerConsumer;
use Reconmap\Services\TemplateEngine;

abstract class Controller implements ContainerConsumer
{
    protected ?Logger $logger = null;
    protected ?TemplateEngine $template = null;
    protected ?ContainerInterface $container = null;

    // abstract public function __invoke(ServerRequestInterface $request, array $args): array|ResponseInterface;

    public function setLogger(Logger $logger): void
    {
        $this->logger = $logger;
    }

    public function setContainer(ContainerInterface $container): void
    {
        $this->container = $container;
    }

    public function getJsonBodyDecoded(ServerRequestInterface $request): object
    {
        return json_decode((string)$request->getBody());
    }

    public function getJsonBodyDecodedAsClass(ServerRequestInterface $request, object $instance, bool $strictNullTypes = true): object
    {
        $jsonMapper = new \JsonMapper();
        $jsonMapper->bStrictNullTypes = $strictNullTypes;
        return $jsonMapper->map($this->getJsonBodyDecoded($request), $instance);
    }

    public function getJsonBodyDecodedAsArray(ServerRequestInterface $request): array
    {
        return json_decode((string)$request->getBody(), true);
    }

    protected function createForbiddenResponse(): ResponseInterface
    {
        return (new Response())->withStatus(StatusCodeInterface::STATUS_FORBIDDEN);
    }

    protected function createNoContentResponse(): ResponseInterface
    {
        return (new Response())->withStatus(StatusCodeInterface::STATUS_NO_CONTENT);
    }

    protected function createNotFoundResponse(): ResponseInterface
    {
        return (new Response())->withStatus(StatusCodeInterface::STATUS_NOT_FOUND);
    }

    protected function createDeletedResponse(): ResponseInterface
    {
        return (new Response())->withStatus(StatusCodeInterface::STATUS_NO_CONTENT);
    }

    protected function createStatusCreatedResponse(string|array|object $body): ResponseInterface
    {
        $jsonBody = is_string($body) ? $body : json_encode($body);

        $response = (new Response())
            ->withStatus(StatusCodeInterface::STATUS_CREATED)
            ->withHeader('Content-type', 'application/json');
        $response->getBody()->write($jsonBody);
        return $response;
    }

    public function getUserFromRequest(ServerRequestInterface $request): User
    {
        $user = new User();
        $user->id = $request->getAttribute('userId');
        $user->role = $request->getAttribute('role');
        return $user;
    }
}
