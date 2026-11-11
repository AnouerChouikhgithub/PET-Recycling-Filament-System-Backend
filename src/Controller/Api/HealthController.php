<?php

declare(strict_types=1);

namespace App\Controller\Api;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/** Minimal public liveness/readiness check. Never expose infrastructure detail. */
final class HealthController
{
    public function __construct(
        private readonly Connection $connection,
        private readonly RateLimiterFactory $healthLimiter,
    ) {
    }

    #[Route('/api/health', name: 'api_health', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $limit = $this->healthLimiter->create((string) $request->getClientIp())->consume();
        if (!$limit->isAccepted()) {
            return new JsonResponse(['status' => 'unavailable'], 429);
        }

        try {
            $this->connection->executeQuery('SELECT 1')->fetchOne();

            return new JsonResponse(['status' => 'ok']);
        } catch (\Throwable) {
            return new JsonResponse(['status' => 'unavailable'], 503);
        }
    }
}
