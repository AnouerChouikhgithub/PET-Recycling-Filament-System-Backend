<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Api\EventSubscriber\ApiExceptionSubscriber;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Hardening contract for HTTP responses:
 *
 *   1. security headers are present on /api responses (nosniff, DENY,
 *      no-referrer; no-store on auth endpoints and non-GET requests);
 *   2. in PROD mode (debug=false) no exception — authentication failure or
 *      a genuine internal error — ever exposes stack-trace details to the
 *      client (exception class, SQL, file paths).
 *
 * The prod-mode checks boot the PROD kernel ONCE per process as a separate
 * Kernel object: the process APP_ENV/APP_DEBUG are never mutated, so no
 * other test can be poisoned. A prod KernelBrowser is impossible
 * (framework.test is test-env only), so handling a real Request through
 * Kernel::handle() is the honest way to exercise the real exception path.
 *
 * The real-path request is deliberately UNAUTHENTICATED: with no bearer
 * token the firewall refuses the request before any database access, and
 * the process env carries the base-name DATABASE_URL that only the test
 * kernel augments with the _test suffix — an authenticated prod-kernel
 * request would silently read the DEV database. The machine-not-found
 * envelope on that same URL is covered in the test kernel (see
 * OwnershipAndAuditTest) and was verified manually in prod mode; the
 * genuine-500 shape is asserted deterministically below by driving the
 * subscriber with debug=false.
 */
final class SecurityHeadersAndProdModeTest extends WebTestCase
{
    private static ?KernelInterface $prodKernel = null;

    public function testSecurityHeadersOnApiResponses(): void
    {
        $client = static::createClient();

        // Unauthenticated GET → 401 envelope; headers must still be applied.
        $client->request('GET', '/api/machines');
        self::assertResponseStatusCodeSame(401);

        $headers = $client->getResponse()->headers;
        self::assertSame('nosniff', $headers->get('X-Content-Type-Options'));
        self::assertSame('DENY', $headers->get('X-Frame-Options'));
        self::assertSame('no-referrer', $headers->get('Referrer-Policy'));
        self::assertNull($headers->get('X-Powered-By'), 'must not reveal the stack behind the API');

        // POST (sensitive) → no-store, so proxies never cache credential flows.
        $client->request('POST', '/api/auth/login', [], [], ['CONTENT_TYPE' => 'application/json'], '{}');
        $headers = $client->getResponse()->headers;
        self::assertStringContainsString('no-store', (string) $headers->get('Cache-Control'));
        self::assertSame('nosniff', $headers->get('X-Content-Type-Options'));
    }

    public function testProdModeAuthFailureIsCleanJsonEnvelope(): void
    {
        $kernel = $this->bootProdKernel();

        $request = Request::create('/api/machines/not-a-valid-uuid', 'GET');
        $request->headers->set('Accept', 'application/json');

        /** @var Response $response */
        $response = $kernel->handle($request);

        if ($kernel instanceof \Symfony\Component\HttpKernel\Kernel) {
            $kernel->terminate($request, $response);
        }

        $content = (string) $response->getContent();
        self::assertSame(401, $response->getStatusCode(), 'unauthenticated request must be refused, not crash. BODY: '.$content);

        $body = json_decode($content, true);
        self::assertIsArray($body, 'prod failures must be the JSON envelope, never an HTML error page');
        self::assertFalse($body['success']);
        self::assertNotEmpty($body['error']['code'] ?? null);

        // Whatever the auth failure is, it must not carry internals.
        foreach (['SQLSTATE', 'Doctrine', 'Exception', 'Trace', '/vendor/', '/src/', '.php'] as $needle) {
            self::assertStringNotContainsString($needle, $content, "prod failure must not leak: {$needle}");
        }

        // Even failures keep the security headers.
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
    }

    public function testProdModeInternalErrorsAreGeneric(): void
    {
        // The real subscriber exactly as the PROD container builds it
        // (debug=false): a genuine unexpected exception must render the
        // generic INTERNAL_ERROR envelope with none of the throwable's
        // message, class, or file details.
        $request = Request::create('/api/machines', 'GET');
        $event = new ExceptionEvent(
            $this->bootProdKernel(),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            new \RuntimeException('SQLSTATE[23505]: trace at /vendor/doctrine/dbal/Exception.php line 123'),
        );

        $subscriber = new ApiExceptionSubscriber(
            static::getContainer()->get(TokenStorageInterface::class),
            [],
            false,
        );
        $subscriber->onKernelException($event);

        /** @var Response|null $response */
        $response = $event->getResponse();
        self::assertInstanceOf(Response::class, $response, 'the subscriber must render internal errors');

        $content = (string) $response->getContent();
        self::assertSame(500, $response->getStatusCode());

        $body = json_decode($content, true);
        self::assertIsArray($body);
        self::assertFalse($body['success']);
        self::assertSame('INTERNAL_ERROR', $body['error']['code']);
        self::assertSame('An unexpected error occurred.', $body['error']['message']);

        foreach (['SQLSTATE', 'RuntimeException', 'Trace', '/vendor/', 'doctrine', '.php', '123'] as $needle) {
            self::assertStringNotContainsString($needle, $content, "prod 500 must not leak: {$needle}");
        }

        self::assertSame('application/json', $response->headers->get('Content-Type'));
    }

    private function bootProdKernel(): KernelInterface
    {
        if (null === self::$prodKernel) {
            $kernel = new \App\Kernel('prod', false);
            $kernel->boot();
            self::$prodKernel = $kernel;
        }

        return self::$prodKernel;
    }
}
