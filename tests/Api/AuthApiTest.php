<?php

declare(strict_types=1);

namespace App\Tests\Api;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Functional tests for the authentication endpoints.
 */
final class AuthApiTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testLoginWithValidCredentialsReturnsTokenAndUser(): void
    {
        $email = strtolower('auth-' . Uuid::v7()->toBase32() . '@3awedlou.test');
        $this->registerUser($email, 'password123', 'Auth Tester');

        $this->client->request('POST', '/api/auth/login', [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode([
            'email' => $email,
            'password' => 'password123',
        ]));

        self::assertResponseIsSuccessful();
        $body = $this->json();
        self::assertTrue($body['success']);
        self::assertNotEmpty($body['data']['token']);
        self::assertArrayHasKey('refresh_token', $body['data'], 'login must issue a refresh token alongside the access token');
        self::assertSame('Bearer', $body['data']['tokenType']);
        self::assertSame($email, $body['data']['user']['email']);
        self::assertArrayNotHasKey('password', $body['data']['user']);
    }

    public function testLoginWithWrongPasswordReturnsEnvelopeError(): void
    {
        $email = strtolower('auth-' . Uuid::v7()->toBase32() . '@3awedlou.test');
        $this->registerUser($email, 'password123', 'Auth Tester');

        $this->client->request('POST', '/api/auth/login', [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode([
            'email' => $email,
            'password' => 'wrong-password',
        ]));

        self::assertResponseStatusCodeSame(401);
        $body = $this->json();
        self::assertFalse($body['success']);
        self::assertSame('INVALID_CREDENTIALS', $body['error']['code']);
    }

    public function testRegisterCreatesUserAndReturnsToken(): void
    {
        $email = strtolower('register-' . Uuid::v7()->toBase32() . '@3awedlou.test');

        $this->client->request('POST', '/api/auth/register', [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode([
            'email' => $email,
            'password' => 'password123',
            'name' => 'New User',
        ]));

        self::assertResponseStatusCodeSame(201);
        $body = $this->json();
        self::assertTrue($body['success']);
        self::assertNotEmpty($body['data']['token']);
        self::assertSame($email, $body['data']['user']['email']);
        self::assertSame(['ROLE_USER'], $body['data']['user']['roles']);
    }

    public function testRegisterRejectsDuplicateEmail(): void
    {
        $email = strtolower('dup-' . Uuid::v7()->toBase32() . '@3awedlou.test');
        $this->registerUser($email, 'password123', 'First');

        $this->client->request('POST', '/api/auth/register', [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode([
            'email' => $email,
            'password' => 'password123',
            'name' => 'Second',
        ]));

        self::assertResponseStatusCodeSame(422);
        $body = $this->json();
        self::assertSame('VALIDATION_FAILED', $body['error']['code']);
        self::assertArrayHasKey('email', $body['error']['details']);
    }

    public function testRegisterRejectsWeakPassword(): void
    {
        $this->client->request('POST', '/api/auth/register', [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode([
            'email' => strtolower('weak-' . Uuid::v7()->toBase32() . '@3awedlou.test'),
            'password' => 'abc',
            'name' => 'Weak',
        ]));

        self::assertResponseStatusCodeSame(422);
        $body = $this->json();
        self::assertSame('VALIDATION_FAILED', $body['error']['code']);
        self::assertArrayHasKey('password', $body['error']['details']);
    }

    public function testMeReturnsAuthenticatedUser(): void
    {
        $email = strtolower('me-' . Uuid::v7()->toBase32() . '@3awedlou.test');
        $token = $this->registerUser($email, 'password123', 'Me Tester');

        $this->client->request('GET', '/api/me', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);

        self::assertResponseIsSuccessful();
        $data = $this->json()['data'];
        self::assertSame($email, $data['email']);
        self::assertSame('Me Tester', $data['name']);
    }

    public function testMeWithoutTokenIsUnauthorized(): void
    {
        $this->client->request('GET', '/api/me');

        self::assertResponseStatusCodeSame(401);
        self::assertSame('UNAUTHORIZED', $this->json()['error']['code']);
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @return array<string, mixed>
     */
    private function json(): array
    {
        $decoded = json_decode($this->client->getResponse()->getContent(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /** @return string the freshly issued JWT */
    private function registerUser(string $email, string $password, string $name): string
    {
        $this->client->request('POST', '/api/auth/register', [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode([
            'email' => $email,
            'password' => $password,
            'name' => $name,
        ]));

        self::assertResponseStatusCodeSame(201, 'User registration must succeed for the test to proceed.');

        return $this->json()['data']['token'];
    }
}
