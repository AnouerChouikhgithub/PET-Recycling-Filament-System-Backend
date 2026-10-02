<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Api\ApiResponse;
use App\Dto\Request\RegisterRequest;
use App\Dto\Transformer\UserViewFactory;
use App\Entity\User;
use App\Service\Auth\RegistrationService;
use Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Public auth surface.
 *
 *   POST /api/auth/register       — rate-limited per IP; disableable via env
 *   POST /api/auth/login          — json_login firewall (envelope via success handler)
 *   POST /api/auth/token/refresh  — refresh_jwt firewall (rotation, envelope via success handler)
 *   POST /api/auth/logout         — revokes the presented refresh token
 *
 * Rate limiting lives in App\RateLimit\AuthRateLimitSubscriber so it wraps the
 * firewalls themselves (login never reaches a controller).
 */
final class AuthController
{
    public function __construct(
        private readonly RegistrationService $registrationService,
        private readonly JWTTokenManagerInterface $jwtManager,
        private readonly UserViewFactory $userViewFactory,
        private readonly RefreshTokenManagerInterface $refreshTokens,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly bool $registrationEnabled = true,
    ) {
    }

    /**
     * POST /api/auth/register — public, rate-limited (AuthRateLimitSubscriber),
     * globally disableable with AUTH_REGISTER_ENABLED=false.
     * Creates the account and returns access + refresh tokens.
     */
    #[Route('/api/auth/register', name: 'api_auth_register', methods: ['POST'])]
    public function register(#[MapRequestPayload] RegisterRequest $body): JsonResponse
    {
        if (!$this->registrationEnabled) {
            throw new AccessDeniedException('Registration is disabled on this server.');
        }

        $user = $this->registrationService->register($body);

        $data = [
            'token' => $this->jwtManager->create($user),
            'tokenType' => 'Bearer',
            'user' => $this->userViewFactory->create($user),
        ];

        // The refresh token is attached to this data array by
        // AttachRefreshTokenOnSuccessListener (gesdinet) via Lexik's
        // AUTHENTICATION_SUCCESS event — keeping web + mobile payloads
        // identical to /auth/login.
        $event = new \Lexik\Bundle\JWTAuthenticationBundle\Event\AuthenticationSuccessEvent(
            $data,
            $user,
            new \Symfony\Component\HttpFoundation\Response(),
        );
        $this->eventDispatcher->dispatch(
            $event,
            \Lexik\Bundle\JWTAuthenticationBundle\Events::AUTHENTICATION_SUCCESS,
        );

        return ApiResponse::success($event->getData(), 201);
    }

    /**
     * POST /api/auth/logout — revokes the refresh token presented in the body
     * ({"refresh_token": "…"}). The access token keeps its short TTL
     * (stateless JWT); the client must discard it. Always 204 — never leaks
     * whether a token existed.
     */
    #[Route('/api/auth/logout', name: 'api_auth_logout', methods: ['POST'])]
    public function logout(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $payload = json_decode($request->getContent() ?: '{}', true);
        $presented = \is_array($payload) && isset($payload['refresh_token']) && \is_string($payload['refresh_token'])
            ? trim($payload['refresh_token'])
            : '';

        if ('' !== $presented) {
            $token = $this->refreshTokens->get($presented);
            if (null !== $token) {
                $callerEmail = $user?->getUserIdentifier();
                if (null !== $callerEmail && $callerEmail === $token->getUsername()) {
                    $this->refreshTokens->delete($token);
                }
            }
        }

        return new JsonResponse(null, 204);
    }

    /**
     * Unreachable: POST /api/auth/token/refresh is answered by the
     * refresh_jwt firewall before routing (single_use rotation). This action
     * exists so the route appears in debug:router with a real controller.
     */
    #[Route('/api/auth/token/refresh', name: 'api_auth_refresh', methods: ['POST'])]
    public function refresh(): JsonResponse
    {
        throw new \LogicException('Handled by the refresh_jwt firewall (security.yaml).');
    }
}
