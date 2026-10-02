<?php

declare(strict_types=1);

namespace App\Api\Http;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Security response headers on every /api response. The API serves JSON only:
 *
 *   - nosniff + deny framing: classic hardening, costs nothing;
 *   - Referrer-Policy: no referrer leakage from API URLs (tokens are in
 *     headers, not URLs, but defense in depth);
 *   - no Cache-Control on purpose EXCEPT a hard no-store on auth endpoints —
 *     status/telemetry responses must never be hidden by caches, and the
 *     HTTP 200 JSON is already uncached by default (no cache headers sent).
 */
final class ApiKernelHeadersSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => ['onKernelResponse', 0]];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!str_starts_with($request->getPathInfo(), '/api/')) {
            return;
        }

        $response = $event->getResponse();
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        // Auth payloads (tokens!) and the device surface are never cacheable.
        if (str_starts_with($request->getPathInfo(), '/api/auth/')
            || str_contains($request->getMethod(), 'POST')) {
            $response->headers->set('Cache-Control', 'no-store');
        }
    }
}
