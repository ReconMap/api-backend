<?php declare(strict_types=1);

namespace Reconmap\Http;

use Fig\Http\Message\StatusCodeInterface;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use League\Route\Http\Exception;
use League\Route\Http\Exception\ForbiddenException;
use Monolog\Logger;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Reconmap\Services\ApplicationConfig;
use Reconmap\Services\Keycloak;
use Reconmap\Services\KeycloakService;

class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly KeycloakService   $keycloak,
                                private readonly Logger            $logger,
                                private readonly ApplicationConfig $config)
    {
    }

    /**
     * @throws ForbiddenException
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $jwt = $this->getToken($request);

        $jwtConfig = $this->config->getSettings('jwt');

        try {
            $token = JWT::decode($jwt, new Key($this->keycloak->getPublicKey(), 'RS256'));

            if ($token->iss !== $jwtConfig['issuer']) {
                throw new ForbiddenException("Invalid JWT issuer: " . $token->iss);
            }
            if ($token->aud !== $jwtConfig['audience']) {
                throw new ForbiddenException("Invalid JWT audience: " . $token->aud);
            }

            $request = $request->withAttribute('userId', 1)//$token->data->id)
            ->withAttribute('role', 'administrator');//$token->data->role);
            return $handler->handle($request);
        } catch (ForbiddenException|ExpiredException $e) {
            $this->logger->warning($e->getMessage());
            return (new Response)->withStatus(StatusCodeInterface::STATUS_UNAUTHORIZED)
                ->withBody(Utils::streamFor($e->getMessage()));
        } catch (Exception $httpException) {
            throw $httpException;
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
            return (new Response)->withStatus(StatusCodeInterface::STATUS_BAD_REQUEST);
        }
    }

    /**
     * @param ServerRequestInterface $request
     * @return string
     * @throws ForbiddenException
     */
    private function getToken(ServerRequestInterface $request): string
    {
        $params = $request->getQueryParams();
        if (isset($params['accessToken'])) {
            return $params['accessToken'];
        }
        $authorizationHeader = $request->getHeader('Authorization');
        if (empty($authorizationHeader)) {
            throw new ForbiddenException("Missing 'Authorization' header");
        }
        $authHeaderParts = explode(' ', $authorizationHeader[0]);
        if (count($authHeaderParts) !== 2 || strcasecmp($authHeaderParts[0], 'Bearer') !== 0) {
            throw new ForbiddenException("Invalid 'Bearer' token");
        }
        return $authHeaderParts[1];
    }
}
