<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Api\EventSubscriber\AuthRateLimitSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * The 429 path of the auth rate limiter — proven deterministically.
 *
 * dev/prod keep the strict limits (login 5 / 5 min, register 3 / 10 min, see
 * config/packages/rate_limiter.yaml); the test env relaxes them to 1000 so
 * the functional suite is not noise. Here the subscriber is exercised with a
 * real RateLimiterFactory limited to ONE request per window: the first
 * consume passes, the second must bounce with the envelope's 429 + the
 * Retry-After header.
 */
final class AuthRateLimitTest extends TestCase
{
    public function testSecondLoginAttemptGets429WithRetryAfter(): void
    {
        $loginLimiter = new RateLimiterFactory([
            'id' => 'auth_login_test',
            'policy' => 'fixed_window',
            'limit' => 1,
            'interval' => '10 minutes',
        ], new InMemoryStorage());
        $registerLimiter = new RateLimiterFactory([
            'id' => 'auth_register_test',
            'policy' => 'fixed_window',
            'limit' => 1000,
            'interval' => '10 minutes',
        ], new InMemoryStorage());

        $subscriber = new AuthRateLimitSubscriber($loginLimiter, $registerLimiter);

        $request = Request::create('/api/auth/login', 'POST', [], [], [], ['REMOTE_ADDR' => '203.0.113.7']);

        $first = $this->runSubscriber($subscriber, $request);
        self::assertNull($first, 'first attempt must pass through to the firewalls');

        $response = $this->runSubscriber($subscriber, $request);
        self::assertNotNull($response, 'second attempt must be blocked');
        self::assertSame(429, $response->getStatusCode());
        self::assertSame('TOO_MANY_REQUESTS', json_decode((string) $response->getContent(), true)['error']['code']);
        self::assertNotEmpty($response->headers->get('Retry-After'), '429 must carry Retry-After');
        self::assertGreaterThan(0, (int) $response->headers->get('Retry-After'));
    }

    public function testRegisterSurfaceIsRateLimitedSeparately(): void
    {
        $loginLimiter = new RateLimiterFactory([
            'id' => 'auth_login_sep',
            'policy' => 'fixed_window',
            'limit' => 1000,
            'interval' => '10 minutes',
        ], new InMemoryStorage());
        $registerLimiter = new RateLimiterFactory([
            'id' => 'auth_register_sep',
            'policy' => 'fixed_window',
            'limit' => 1,
            'interval' => '10 minutes',
        ], new InMemoryStorage());

        $subscriber = new AuthRateLimitSubscriber($loginLimiter, $registerLimiter);

        $register = Request::create('/api/auth/register', 'POST', [], [], [], ['REMOTE_ADDR' => '203.0.113.9']);
        $this->runSubscriber($subscriber, $register);
        $blocked = $this->runSubscriber($subscriber, $register);
        self::assertNotNull($blocked);
        self::assertSame(429, $blocked->getStatusCode(), 'register limiter must be independent of login');
    }

    public function testOtherRoutesAreNeverLimited(): void
    {
        $loginLimiter = new RateLimiterFactory([
            'id' => 'auth_login_other',
            'policy' => 'fixed_window',
            'limit' => 0,
            'interval' => '10 minutes',
        ], new InMemoryStorage());
        $registerLimiter = new RateLimiterFactory([
            'id' => 'auth_register_other',
            'policy' => 'fixed_window',
            'limit' => 0,
            'interval' => '10 minutes',
        ], new InMemoryStorage());

        $subscriber = new AuthRateLimitSubscriber($loginLimiter, $registerLimiter);

        foreach (['/api/machines', '/api/auth/me', '/api/machines/x/telemetry'] as $path) {
            self::assertNull(
                $this->runSubscriber($subscriber, Request::create($path, 'GET')),
                "{$path} must not touch the auth limiters",
            );
        }
    }

    /**
     * onKernelRequest() is void and signals via $event->setResponse(); this
     * helper runs it on a real event built around a stub kernel so the test
     * can observe whether a response was attached. The stub throws if the
     * subscriber ever tries to delegate to the kernel — it must not.
     */
    private function runSubscriber(AuthRateLimitSubscriber $subscriber, Request $request): ?Response
    {
        $kernel = new class implements HttpKernelInterface {
            public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
            {
                throw new \LogicException('the auth rate limiter must never handle the request itself');
            }
        };

        $event = new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);
        $subscriber->onKernelRequest($event);

        return $event->getResponse();
    }
}
