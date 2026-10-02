<?php

declare(strict_types=1);

namespace App\Security;

use App\Api\ApiResponse;
use Lexik\Bundle\JWTAuthenticationBundle\Event\AuthenticationFailureEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Events;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;

/**
 * Converts failed logins into the consistent 401 envelope. The message is a
 * generic "Invalid credentials." — it never reveals whether the account exists.
 */
final class JwtLoginFailureHandler implements AuthenticationFailureHandlerInterface
{
    public function __construct(private readonly EventDispatcherInterface $dispatcher)
    {
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): JsonResponse
    {
        $event = new AuthenticationFailureEvent($exception, null);
        $this->dispatcher->dispatch($event, Events::AUTHENTICATION_FAILURE);

        $envelope = ApiResponse::error('INVALID_CREDENTIALS', 'Invalid credentials.', JsonResponse::HTTP_UNAUTHORIZED);

        // Keep lexik's response headers (if any) while replacing the body.
        if (null !== $event->getResponse()) {
            $envelope->headers->add($event->getResponse()->headers->all());
        }

        return $envelope;
    }
}
