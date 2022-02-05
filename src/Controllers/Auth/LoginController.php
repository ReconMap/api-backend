<?php declare(strict_types=1);

namespace Reconmap\Controllers\Auth;

use Fig\Http\Message\StatusCodeInterface;
use Firebase\JWT\JWT;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Reconmap\Controllers\Controller;
use Reconmap\Models\AuditActions\UserAuditActions;
use Reconmap\Repositories\UserRepository;
use Reconmap\Services\ApplicationConfig;
use Reconmap\Services\AuditLogService;
use Reconmap\Services\JwtPayloadCreator;
use Reconmap\Services\Security\Permissions;

class LoginController extends Controller
{
    public function __construct(
        private UserRepository    $userRepository,
        private ApplicationConfig $applicationConfig,
        private AuditLogService   $auditLogService,
        private JwtPayloadCreator $jwtPayloadCreator)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $json = $request->getParsedBody();
        $username = $json['username'];
        $password = $json['password'];

        $user = $this->userRepository->findByUsername($username);

        $response = new Response;

        if (is_null($user) || !password_verify($password, $user['password'])) {
            $this->audit(0, UserAuditActions::USER_LOGIN_FAILED, ['username' => $username]);
            return $response->withStatus(StatusCodeInterface::STATUS_FORBIDDEN);
        }

        unset($user['password']); // DO NOT leak password in the response.

        $user['mfa'] = match (true) {
            $user['mfa_enabled'] === 1 => $user['mfa_secret'] ? 'ready' : 'setup',
            default => 'disabled'
        };

        $this->audit($user['id'], UserAuditActions::USER_LOGGED_IN);

        $jwtPayload = $this->jwtPayloadCreator->createFromUserArray($user);

        $jwtConfig = $this->applicationConfig->getSettings('jwt');

        $user['access_token'] = JWT::encode($jwtPayload, $jwtConfig['key'], 'HS256');
        $user['permissions'] = Permissions::ByRoles[$user['role']];

        $response->getBody()->write(json_encode($user));
        return $response->withHeader('Content-type', 'application/json');
    }

    private function audit(int $userId, string $action, ?array $object = null): void
    {
        $this->auditLogService->insert($userId, $action, $object);
    }
}
