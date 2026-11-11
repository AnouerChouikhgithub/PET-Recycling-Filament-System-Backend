<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Controller\Api\HealthController;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class HealthControllerTest extends TestCase
{
    public function testSuccessBodyAndHeadersContainNoInfrastructureDetails(): void
    {
        $result = $this->createMock(Result::class);
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('executeQuery')->with('SELECT 1')->willReturn($result);
        $result->expects(self::once())->method('fetchOne')->willReturn(1);

        $response = $this->controller($connection)->__invoke($this->request());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('{"status":"ok"}', $response->getContent());
        self::assertNull($response->headers->get('Server'));
        self::assertNull($response->headers->get('X-Powered-By'));
        self::assertStringNotContainsString('database', (string) $response->getContent());
        self::assertStringNotContainsString('localhost', (string) $response->getContent());
    }

    public function testDatabaseFailureReturnsOnlyUnavailable(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('executeQuery')->with('SELECT 1')->willThrowException(new \RuntimeException('private host detail'));

        $response = $this->controller($connection)->__invoke($this->request());

        self::assertSame(503, $response->getStatusCode());
        self::assertSame('{"status":"unavailable"}', $response->getContent());
        self::assertStringNotContainsString('private host detail', (string) $response->getContent());
        self::assertNull($response->headers->get('Server'));
        self::assertNull($response->headers->get('X-Powered-By'));
    }

    public function testRateLimitIsPerClientIpAndHasOnlySafeBody(): void
    {
        $result = $this->createStub(Result::class);
        $result->method('fetchOne')->willReturn(1);
        $connection = $this->createStub(Connection::class);
        $connection->method('executeQuery')->willReturn($result);
        $controller = $this->controller($connection);
        $request = $this->request('198.51.100.21');

        for ($i = 0; $i < 120; ++$i) {
            self::assertSame(200, $controller->__invoke($request)->getStatusCode());
        }

        $limited = $controller->__invoke($request);
        self::assertSame(429, $limited->getStatusCode());
        self::assertSame('{"status":"unavailable"}', $limited->getContent());
    }

    private function controller(Connection $connection): HealthController
    {
        return new HealthController($connection, new RateLimiterFactory(
            ['id' => 'health-test', 'policy' => 'sliding_window', 'limit' => 120, 'interval' => '1 minute'],
            new InMemoryStorage(),
        ));
    }

    private function request(string $ip = '198.51.100.20'): Request
    {
        return Request::create('/api/health', 'GET', [], [], [], ['REMOTE_ADDR' => $ip]);
    }
}
