<?php

declare(strict_types=1);

namespace App\Tests\IoT;

use App\Api\Exception\MqttUnavailableException;
use App\Entity\Machine;
use App\Entity\MachineCommandAudit;
use App\Entity\MachineStatus;
use App\Entity\User;
use App\Service\IoT\SelectedMqttPublisher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The command endpoint's two honest outcomes, over real HTTP:
 *
 *   202 + delivery=buffered_not_sent   the transport accepted the message
 *                                      (MQTT_ENABLED=false in tests);
 *   503 + error.code=MQTT_UNAVAILABLE  the transport FAILED — the command is
 *                                      still audited, never "accepted".
 *
 * The transport is swapped through SelectedMqttPublisher, which is public
 * exactly so a test can replace the ONE place the transport is chosen without
 * touching the controller, the guard or the audit trail. The kernel must not
 * reboot between requests, or the replacement would be discarded with the
 * container.
 */
final class MqttCommandApiTest extends WebTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $em;

    private User $user;

    private string $email;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        // Keep the same container across login + the request under test, so a
        // service replaced in the test is still there when the call happens.
        $this->client->disableReboot();

        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->email = strtolower(sprintf('mqtt-api-%s@3awedlou.test', Uuid::v7()->toBase32()));

        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail($this->email)->setName('MQTT API Tester');
        $user->setPassword($hasher->hashPassword($user, 'secret-password'));
        $this->em->persist($user);
        $this->em->flush();
        $this->user = $user;

        $this->login();
    }

    public function testAcceptedCommandIsPublishedOnceAndReportedHonestly(): void
    {
        $transport = new FakeMqttPublisher();
        static::getContainer()->set(SelectedMqttPublisher::class, $transport);

        $machine = $this->createOnlineMachine();

        $this->client->request(
            'POST',
            '/api/machines/'.$machine->getId()->toRfc4122().'/commands',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            (string) json_encode(['command' => 'start']),
        );

        self::assertResponseStatusCodeSame(202);
        $data = $this->json()['data'];

        self::assertTrue($data['accepted']);
        self::assertSame(
            'buffered_not_sent',
            $data['delivery'],
            'with MQTT_ENABLED=false the API must NOT claim a broker publish',
        );
        self::assertFalse($data['deviceAcknowledged'], 'no device acknowledgement exists yet');
        self::assertArrayHasKey('commandId', $data);
        self::assertArrayHasKey('expiresAt', $data);

        // Exactly one message reached the transport, on the documented topic.
        self::assertCount(1, $transport->published);
        self::assertSame($data['topic'], $transport->published[0]['topic']);

        $audits = $this->auditsFor($machine);
        self::assertCount(1, $audits);
        self::assertSame('buffered-log', $audits[0]->getTransport());
        self::assertSame($data['commandId'], $audits[0]->getId()->toRfc4122());
    }

    public function testBrokerFailureReturns503MqttUnavailableAndAuditsTheFailure(): void
    {
        $transport = new FakeMqttPublisher();
        $transport->failure = new MqttUnavailableException('The MQTT broker could not be reached.');
        static::getContainer()->set(SelectedMqttPublisher::class, $transport);

        $machine = $this->createOnlineMachine();

        $this->client->request(
            'POST',
            '/api/machines/'.$machine->getId()->toRfc4122().'/commands',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            (string) json_encode(['command' => 'start']),
        );

        self::assertResponseStatusCodeSame(503, 'a command that never left the process cannot be accepted');
        $body = $this->json();
        self::assertFalse($body['success']);
        self::assertSame('MQTT_UNAVAILABLE', $body['error']['code']);
        self::assertStringNotContainsString('"data"', (string) $this->client->getResponse()->getContent());
        self::assertSame([], $transport->published, 'nothing reached the transport');

        // …but the attempt IS auditable, with the failure recorded on it.
        $audits = $this->auditsFor($machine);
        self::assertCount(1, $audits, 'the dispatch is recorded even though the publish failed');
        self::assertSame('mqtt-unavailable', $audits[0]->getTransport());
        self::assertSame('start', $audits[0]->getCommand()->value);
    }

    // ---------------------------------------------------------------- helpers

    /** @return array<string, mixed> */
    private function json(): array
    {
        $decoded = json_decode($this->client->getResponse()->getContent(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function login(): void
    {
        $this->client->request('POST', '/api/auth/login', [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode([
            'email' => $this->email,
            'password' => 'secret-password',
        ]));

        self::assertResponseStatusCodeSame(200, 'Login must succeed for the API tests to proceed.');

        $token = $this->json()['data']['token'] ?? null;
        self::assertNotEmpty($token);

        $this->client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$token);
    }

    private function createOnlineMachine(): Machine
    {
        $machine = new Machine();
        $machine->setName('MQTT API Unit')
            ->setIdentifier('mqtt-api-'.substr(Uuid::v7()->toBase32(), 0, 8))
            ->setStatus(MachineStatus::Idle)
            ->setOwner($this->user);
        $machine->markAsSeen();
        $this->em->persist($machine);
        $this->em->flush();

        return $machine;
    }

    /** @return list<MachineCommandAudit> */
    private function auditsFor(Machine $machine): array
    {
        /** @var list<MachineCommandAudit> $rows */
        $rows = $this->em->getRepository(MachineCommandAudit::class)->findBy(['machine' => $machine]);

        return $rows;
    }
}
