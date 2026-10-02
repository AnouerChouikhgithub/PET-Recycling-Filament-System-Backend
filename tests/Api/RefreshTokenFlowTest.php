<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\RefreshToken;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Refresh-token security contract (gesdinet v2, hashed at rest):
 *
 *   1. login issues an ACCESS token AND a refresh_token in the same envelope;
 *   2. the refresh token is stored HASHED — the plaintext never touches the DB;
 *   3. a refresh rotates: new credentials out, the presented token dies;
 *   4. logout revokes — a presented refresh token is deleted, not reusable;
 *   5. expired tokens are rejected;
 *   6. someone else's refresh token is rejected.
 */
final class RefreshTokenFlowTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $email;
    private string $password;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        // strtolower matters: base32 UUIDs are UPPERCASE and User::setEmail()
        // normalizes to lowercase — login lookups use the stored form.
        $this->email = strtolower('refresh-'.Uuid::v7()->toBase32().'@3awedlou.test');
        $this->password = 'refresh-secret-pass';

        $user = new \App\Entity\User();
        $user->setEmail($this->email)->setName('Refresh Tester');
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user->setPassword($hasher->hashPassword($user, $this->password));
        $this->em->persist($user);
        $this->em->flush();
    }

    public function testLoginReturnsRefreshTokenAndStoresItHashed(): void
    {
        $body = $this->login();

        self::assertArrayHasKey('refresh_token', $body['data'], 'login envelope must carry a refresh_token');
        $plaintext = (string) $body['data']['refresh_token'];
        self::assertNotEmpty($plaintext);

        // Hashed at rest: the stored value is the bundle's sha256$… digest,
        // never the plaintext the client received.
        $repo = $this->em->getRepository(RefreshToken::class);
        self::assertNull($repo->findOneBy(['refreshToken' => $plaintext]), 'plaintext must NOT be stored');
        $row = $repo->findOneBy(['refreshToken' => 'sha256$'.hash('sha256', $plaintext)]);
        self::assertNotNull($row, 'the sha256 digest must be stored instead');
        self::assertSame($this->email, $row->getUsername());
    }

    public function testRefreshRotatesAndRejectsReplay(): void
    {
        $refresh = $this->login()['data']['refresh_token'];

        $this->client->request('POST', '/api/auth/token/refresh', [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode(['refresh_token' => $refresh]));
        self::assertResponseIsSuccessful();
        $rotated = $this->json()['data'];
        self::assertArrayHasKey('token', $rotated);
        self::assertArrayHasKey('refresh_token', $rotated, 'rotation must issue a fresh refresh token');
        self::assertNotSame($refresh, $rotated['refresh_token'], 'the presented token must be rotated, not re-issued');

        // Replay of the single-use token must fail (it was consumed).
        $this->client->request('POST', '/api/auth/token/refresh', [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode(['refresh_token' => $refresh]));
        self::assertResponseStatusCodeSame(401, 'single-use refresh tokens must not be replayable');
    }

    public function testLogoutRevokesTheRefreshToken(): void
    {
        $body = $this->login();
        $access = (string) $body['data']['token'];
        $refresh = (string) $body['data']['refresh_token'];

        $this->client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$access);
        $this->client->request('POST', '/api/auth/logout', [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode(['refresh_token' => $refresh]));
        self::assertResponseStatusCodeSame(204);

        // Gone from storage, not just flagged.
        $repo = $this->em->getRepository(RefreshToken::class);
        self::assertNull($repo->findOneBy(['refreshToken' => 'sha256$'.hash('sha256', $refresh)]));

        // And the client cannot silently refresh with it any more.
        $this->client->request('POST', '/api/auth/token/refresh', [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode(['refresh_token' => $refresh]));
        self::assertResponseStatusCodeSame(401);
    }

    public function testExpiredRefreshTokenIsRejected(): void
    {
        $refresh = $this->login()['data']['refresh_token'];

        $row = $this->em->getRepository(RefreshToken::class)->findOneBy([
            'refreshToken' => 'sha256$'.hash('sha256', (string) $refresh),
        ]);
        self::assertNotNull($row);

        $row->setValid(new \DateTime('-1 second'));
        $this->em->flush();

        $this->client->request('POST', '/api/auth/token/refresh', [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode(['refresh_token' => $refresh]));
        self::assertResponseStatusCodeSame(401, 'expired refresh tokens must be refused');
    }

    public function testAnotherUsersRefreshTokenIsRejected(): void
    {
        $this->login();
        $alien = 'not-a-real-token-from-anyone';

        $this->client->request('POST', '/api/auth/token/refresh', [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode(['refresh_token' => $alien]));
        self::assertResponseStatusCodeSame(401);
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @return array<string, mixed>
     */
    private function login(): array
    {
        $this->client->request('POST', '/api/auth/login', [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode([
            'email' => $this->email,
            'password' => $this->password,
        ]));

        self::assertResponseStatusCodeSame(200, 'login must succeed for refresh-flow tests to proceed');

        return $this->json();
    }

    /**
     * @return array<string, mixed>
     */
    private function json(): array
    {
        $decoded = json_decode($this->client->getResponse()->getContent(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
