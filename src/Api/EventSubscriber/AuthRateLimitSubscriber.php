<?php

declare(strict_types=1);

namespace App\Api\EventSubscriber;

use App\Api\ApiResponse;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Per-IP rate limiting on the public auth surface. Lives on KernelEvents::
 * REQUEST (before the firewalls authenticate) so it covers json_login and the
 * refresh firewall too — neither of which runs a controller.
 *
 *   login    → 5 attempts / 5 min / IP   (limiter.auth_login)
 *   register → 3 attempts / 10 min / IP  (limiter.auth_register)
 *
 * 429 + Retry-After in the standard envelope when exceeded.
 */
final class AuthRateLimitSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly RateLimiterFactory $loginLimiter,
        private readonly RateLimiterFactory $registerLimiter,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // Priority 8: after routing (we need the route name), before security (8).
        return [KernelEvents::REQUEST => ['onKernelRequest', 8]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $path = $request->getPathInfo();

        $limiter = null;
        if ('/api/auth/login' === $path && 'POST' === $request->getMethod()) {
            $limiter = $this->loginLimiter;
        } elseif ('/api/auth/register' === $path && 'POST' === $request->getMethod()) {
            $limiter = $this->registerLimiter;
        }

        if (null === $limiter) {
            return;
        }

        $limit = $limiter->create($this->clientKey($request))->consume();
        if ($limit->isAccepted()) {
            return;
        }

        $retryAfter = max(1, $limit->getRetryAfter()->getTimestamp() - time());
        $response = ApiResponse::error(
            'TOO_MANY_REQUESTS',
            'Too many attempts. Try again later.',
            429,
        );
        $response->headers->set('Retry-After', (string) $retryAfter);

        $event->setResponse($response);
    }

    /**
     * Per-client limiter key. RateLimiterFactory::create() with no argument
     * generates a NEW random key per call — i.e. no limiting at all — so the
     * key MUST be passed explicitly.
     *
     * Key = client IP, plus the (normalized, lowercase) email for login so
     * one attacker behind one IP cannot grind through a single victim's
     * password: each (IP, email) pair gets its own budget while the IP budget
     * still bounds total traffic.
     *
     * Trusted proxies: behind a reverse proxy/balancer the client IP is only
     * correct if TRUSTED_PROXIES (framework.yaml) is set to that proxy's
     * addresses — otherwise every client shares the proxy's IP and one shared
     * bucket locks everyone out. When keys differ, buckets are independent.
     */
    private function clientKey(Request $request): string
    {
        $parts = [(string) $request->getClientIp()];

        if ('/api/auth/login' === $request->getPathInfo()) {
            $payload = json_decode($request->getContent() ?: '', true);
            $email = \is_array($payload) && isset($payload['email']) && \is_string($payload['email'])
                ? $payload['email']
                : '';
            $parts[] = mb_strtolower(trim($email));
        }

        return sha1(implode('|', $parts));
    }
}
