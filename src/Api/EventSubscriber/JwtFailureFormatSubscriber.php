<?php

declare(strict_types=1);

namespace App\Api\EventSubscriber;

use App\Api\ApiResponse;
use Lexik\Bundle\JWTAuthenticationBundle\Event\AuthenticationFailureEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTExpiredEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTInvalidEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTNotFoundEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Events;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rewrites lexik JWT authentication failure responses (invalid / expired /
 * missing token) into the consistent 3awedlou error envelope, while keeping
 * the 401 status and headers such as WWW-Authenticate.
 */
final class JwtFailureFormatSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            Events::JWT_INVALID => 'onJwtInvalid',
            Events::JWT_EXPIRED => 'onJwtExpired',
            Events::JWT_NOT_FOUND => 'onJwtNotFound',
            Events::AUTHENTICATION_FAILURE => 'onAuthenticationFailure',
        ];
    }

    public function onJwtInvalid(JWTInvalidEvent $event): void
    {
        $event->setResponse($this->envelope('INVALID_TOKEN', 'Invalid JWT token.', $event->getResponse()));
    }

    public function onJwtExpired(JWTExpiredEvent $event): void
    {
        $event->setResponse($this->envelope('TOKEN_EXPIRED', 'Your session has expired. Please log in again.', $event->getResponse()));
    }

    public function onJwtNotFound(JWTNotFoundEvent $event): void
    {
        $event->setResponse($this->envelope('TOKEN_NOT_FOUND', 'Authentication required. Provide a Bearer token.', $event->getResponse()));
    }

    public function onAuthenticationFailure(AuthenticationFailureEvent $event): void
    {
        $event->setResponse($this->envelope('INVALID_CREDENTIALS', 'Invalid credentials.', $event->getResponse()));
    }

    private function envelope(string $code, string $message, ?Response $original): Response
    {
        $response = ApiResponse::error($code, $message, Response::HTTP_UNAUTHORIZED);

        if (null !== $original) {
            $response->headers->add($original->headers->all());
        }

        return $response;
    }
}
