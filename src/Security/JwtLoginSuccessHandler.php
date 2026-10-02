<?php

declare(strict_types=1);

namespace App\Security;

use App\Api\ApiResponse;
use App\Dto\Transformer\UserViewFactory;
use Lexik\Bundle\JWTAuthenticationBundle\Event\AuthenticationSuccessEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Events;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationSuccessHandlerInterface;

/**
 * Returns the 3awedlou envelope on successful login:
 *
 *   { "success": true, "data": { "token": "...", "tokenType": "Bearer", "user": {...} } }
 *
 * The data array is passed through Lexik's AUTHENTICATION_SUCCESS event so
 * gesdinet's AttachRefreshTokenOnSuccessListener can append `refresh_token`
 * to it (the bundle's own listener only reacts to that event — without this
 * dispatch, login would never return a refresh token). The same shape is
 * reproduced for /api/auth/register in AuthController::register().
 */
final class JwtLoginSuccessHandler implements AuthenticationSuccessHandlerInterface
{
    public function __construct(
        private readonly JWTTokenManagerInterface $jwtManager,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly UserViewFactory $userViewFactory,
    ) {
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token): JsonResponse
    {
        $user = $token->getUser();
        \assert($user instanceof \App\Entity\User);
        $jwt = $this->jwtManager->create($user);

        $data = [
            'token' => $jwt,
            'tokenType' => 'Bearer',
            'user' => $this->userViewFactory->create($user),
        ];

        // gesdinet appends `refresh_token` here (creates + stores it hashed).
        $event = new AuthenticationSuccessEvent($data, $user, new \Symfony\Component\HttpFoundation\Response());
        $this->dispatcher->dispatch($event, Events::AUTHENTICATION_SUCCESS);

        return ApiResponse::success($event->getData());
    }
}
