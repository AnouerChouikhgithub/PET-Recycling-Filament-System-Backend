<?php

declare(strict_types=1);

namespace App\Tests\Api;

use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Trusted proxies are opt-in: with no configuration a spoofed
 * X-Forwarded-For header must be ignored; with the proxy address configured
 * it becomes the client IP used by the auth rate limiters.
 */
final class TrustedProxyTest extends KernelTestCase
{
    private const PROXY = '127.0.0.1';

    protected function tearDown(): void
    {
        Request::setTrustedProxies([], -1);
        unset($_ENV['TRUSTED_PROXIES'], $_SERVER['TRUSTED_PROXIES']);
        parent::tearDown();
    }

    public function testForwardedForIsIgnoredWithoutTrustedProxies(): void
    {
        Request::setTrustedProxies([], -1);

        $request = $this->requestFromProxy('198.51.100.10');

        self::assertSame(self::PROXY, $request->getClientIp(), 'untrusted X-Forwarded-For must be ignored');
    }

    public function testForwardedForDeterminesClientIpForATrustedProxy(): void
    {
        self::ensureKernelShutdown();
        $_ENV['TRUSTED_PROXIES'] = self::PROXY;
        $_SERVER['TRUSTED_PROXIES'] = self::PROXY;
        self::bootKernel();

        $first = $this->requestFromProxy('198.51.100.10');
        $second = $this->requestFromProxy('198.51.100.11');

        self::assertSame('198.51.100.10', $first->getClientIp(), 'a trusted proxy supplies the real client IP');
        self::assertSame('198.51.100.11', $second->getClientIp(), 'two clients behind the proxy get separate IPs/buckets');
    }

    private function requestFromProxy(string $forwardedFor): Request
    {
        return Request::create('/api/auth/login', 'POST', [], [], [], [
            'REMOTE_ADDR' => self::PROXY,
            'HTTP_X_FORWARDED_FOR' => $forwardedFor,
        ]);
    }
}
