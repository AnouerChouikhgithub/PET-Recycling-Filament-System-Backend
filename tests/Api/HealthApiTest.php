<?php

declare(strict_types=1);

namespace App\Tests\Api;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HealthApiTest extends WebTestCase
{
    public function testHealthIsPublicAndReturnsOnlyStatus(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/health');

        self::assertResponseIsSuccessful();
        self::assertSame(['status' => 'ok'], json_decode((string) $client->getResponse()->getContent(), true));
        self::assertNull($client->getResponse()->headers->get('Server'));
        self::assertNull($client->getResponse()->headers->get('X-Powered-By'));
    }
}
