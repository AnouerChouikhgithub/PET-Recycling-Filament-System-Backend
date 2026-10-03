<?php

declare(strict_types=1);

namespace App\Tests\IoT;

use App\Api\Exception\MqttUnavailableException;
use App\Api\Exception\ValidationException;
use App\Entity\Machine;
use App\Entity\MachineCommandAudit;
use App\Entity\MachineStatus;
use App\Entity\User;
use App\Service\IoT\BufferingMqttPublisher;
use App\Service\IoT\BrokerMqttPublisher;
use App\Service\IoT\MachineCommandGuard;
use App\Service\IoT\MachineCommandService;
use App\Service\IoT\MqttConfig;
use App\Service\IoT\MqttPublisherInterface;
use App\Service\IoT\MqttTopicBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * The command pipeline, driven directly (no HTTP) against a fake broker.
 *
 * Verifies what the API contract promises but a response body cannot show:
 *   * the wire envelope is versioned, correlated to the audit row and expires;
 *   * the safety guard runs BEFORE anything is audited or published;
 *   * a broker failure is recorded and propagated instead of reported as
 *     success;
 *   * MQTT_ENABLED=false keeps the historical buffering behaviour.
 */
final class MqttCommandDispatchTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    private MqttTopicBuilder $topics;

    protected function setUp(): void
    {
        static::bootKernel();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em = $em;

        /** @var MqttTopicBuilder $topics */
        $topics = static::getContainer()->get(MqttTopicBuilder::class);
        $this->topics = $topics;
    }

    public function testPayloadIsVersionedCorrelatedToTheAuditRowAndExpires(): void
    {
        $machine = $this->createOnlineMachine('payload');
        $connection = new FakeMqttConnection();
        $service = $this->service(new BrokerMqttPublisher($connection), new MqttConfig(enabled: true));

        $view = $service->dispatch($machine, ['command' => 'setTargetTemperature', 'value' => 195]);

        self::assertCount(1, $connection->published, 'exactly one message reaches the broker');
        $wire = $connection->published[0];

        self::assertSame($this->topics->commands($machine->getIdentifier()), $wire['topic']);
        self::assertSame(1, $wire['qos'], 'MQTT_QOS default 1');
        self::assertFalse($wire['retain'], 'a retained command would replay on reconnect');

        $payload = $connection->firstPayload();
        self::assertSame(1, $payload['v'], 'the payload is versioned');
        self::assertSame('setTargetTemperature', $payload['type']);
        self::assertSame(195, $payload['value']);
        self::assertArrayHasKey('issuedAt', $payload);
        self::assertArrayHasKey('expiresAt', $payload);

        // commandId === the audit row id, so a future device ack correlates.
        $audits = $this->em->getRepository(MachineCommandAudit::class)->findBy(['machine' => $machine]);
        self::assertCount(1, $audits);
        $audit = $audits[0];
        self::assertSame($audit->getId()->toRfc4122(), $payload['commandId']);
        self::assertSame($audit->getId()->toRfc4122(), $view['commandId']);

        // issuedAt is the audit timestamp; expiresAt = issuedAt + TTL.
        self::assertSame($audit->getCreatedAt()->format(\DateTimeInterface::ATOM), $payload['issuedAt']);
        $ttl = (new \DateTimeImmutable($payload['expiresAt']))->getTimestamp()
            - (new \DateTimeImmutable($payload['issuedAt']))->getTimestamp();
        self::assertSame(30, $ttl, 'expiresAt = issuedAt + MQTT_COMMAND_TTL_SECONDS (default 30)');
        self::assertSame($view['sentAt'], $payload['issuedAt'], 'the API and the wire agree on the issue time');

        self::assertTrue($view['accepted']);
        self::assertFalse($view['deviceAcknowledged'], 'the broker acknowledging is NOT the machine executing');
        self::assertSame('published_to_broker', $view['delivery']);
        self::assertSame('mqtt', $audits[0]->getTransport());
    }

    public function testPayloadCarriesNoUserPii(): void
    {
        $machine = $this->createOnlineMachine('pii');
        $user = $machine->getOwner();
        \assert($user instanceof User);

        /** @var TokenStorageInterface $tokenStorage */
        $tokenStorage = static::getContainer()->get(TokenStorageInterface::class);
        $tokenStorage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));

        $connection = new FakeMqttConnection();
        $this->service(new BrokerMqttPublisher($connection), new MqttConfig(enabled: true))
            ->dispatch($machine, ['command' => 'start']);

        $raw = $connection->published[0]['payload'];
        self::assertStringNotContainsString($user->getEmail(), $raw, 'no e-mail on the wire');
        self::assertStringNotContainsString($user->getId()->toRfc4122(), $raw, 'no user id on the wire');
        self::assertStringNotContainsString($machine->getName(), $raw, 'no account data beyond the topic');

        $tokenStorage->setToken(null);
    }

    public function testGuardBlocksBeforeAnythingIsAuditedOrPublished(): void
    {
        $connection = new FakeMqttConnection();
        $service = $this->service(new BrokerMqttPublisher($connection), new MqttConfig(enabled: true));

        // Never seen -> offline -> refused, before the audit and the publish.
        $offline = $this->createMachine('blocked');
        try {
            $service->dispatch($offline, ['command' => 'start']);
            self::fail('An offline machine must refuse the command.');
        } catch (ValidationException) {
            // expected: 422 VALIDATION_FAILED
        }

        self::assertSame([], $connection->published, 'nothing may be published for a rejected command');
        self::assertSame([], $this->auditsFor($offline), 'a rejected command writes no audit row');

        // Out-of-range values are refused by the same gate.
        $online = $this->createOnlineMachine('blocked-range');
        try {
            $service->dispatch($online, ['command' => 'setTargetTemperature', 'value' => 5000]);
            self::fail('An out-of-range heater target must be refused.');
        } catch (ValidationException) {
            // expected
        }

        self::assertSame([], $connection->published, 'the out-of-range command published nothing either');
        self::assertSame([], $this->auditsFor($online), 'and wrote no audit row');

        // A command the guard accepts IS published — the gate is selective.
        $service->dispatch($online, ['command' => 'start']);
        self::assertCount(1, $connection->published);
        self::assertSame('start', $connection->firstPayload()['type']);
        self::assertCount(1, $this->auditsFor($online));
    }

    public function testBrokerFailureIsRecordedOnTheAuditRowAndNeverReportedAsSuccess(): void
    {
        $machine = $this->createOnlineMachine('down');
        $connection = new FakeMqttConnection();
        $connection->failure = new MqttUnavailableException('The MQTT broker could not be reached.');

        $service = $this->service(new BrokerMqttPublisher($connection), new MqttConfig(enabled: true));

        try {
            $service->dispatch($machine, ['command' => 'start']);
            self::fail('A failed publish must not be reported as accepted.');
        } catch (MqttUnavailableException) {
            // expected — the API maps this to 503 MQTT_UNAVAILABLE
        }

        // The command WAS audited (guard passed) and the failure is on the row.
        $audits = $this->auditsFor($machine);
        self::assertCount(1, $audits, 'the dispatch stays auditable when the broker is down');
        self::assertSame('mqtt-unavailable', $audits[0]->getTransport());
        self::assertSame('start', $audits[0]->getCommand()->value);
    }

    public function testBufferedTransportIsRecordedWhenNoBrokerIsConfigured(): void
    {
        $machine = $this->createOnlineMachine('buffered');

        // MQTT_ENABLED=false (the test default) → BufferingMqttPublisher.
        $service = $this->service(new BufferingMqttPublisher(new NullLogger()), new MqttConfig(enabled: false));
        $view = $service->dispatch($machine, ['command' => 'start']);

        self::assertSame('buffered_not_sent', $view['delivery'], 'the API must never claim a broker publish that did not happen');

        $audits = $this->auditsFor($machine);
        self::assertCount(1, $audits);
        self::assertSame('buffered-log', $audits[0]->getTransport(), 'the audit row tells the same story');
    }

    // ---------------------------------------------------------------- helpers

    private function service(MqttPublisherInterface $publisher, MqttConfig $config): MachineCommandService
    {
        $container = static::getContainer();

        /** @var MachineCommandGuard $guard */
        $guard = $container->get(MachineCommandGuard::class);
        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = $container->get(EventDispatcherInterface::class);
        /** @var TokenStorageInterface $tokenStorage */
        $tokenStorage = $container->get(TokenStorageInterface::class);

        return new MachineCommandService(
            $guard,
            $publisher,
            $this->topics,
            $dispatcher,
            $this->em,
            $tokenStorage,
            $config,
            new NullLogger(),
        );
    }

    /** @return list<MachineCommandAudit> */
    private function auditsFor(Machine $machine): array
    {
        /** @var list<MachineCommandAudit> $rows */
        $rows = $this->em->getRepository(MachineCommandAudit::class)->findBy(['machine' => $machine]);

        return $rows;
    }

    private function createOnlineMachine(string $suffix): Machine
    {
        $machine = $this->createMachine($suffix);
        $machine->setStatus(MachineStatus::Idle)->markAsSeen();
        $this->em->flush();

        return $machine;
    }

    private function createMachine(string $suffix): Machine
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail(sprintf('mqtt-dispatch-%s@3awedlou.test', $suffix))
            ->setName('MQTT Dispatcher');
        $user->setPassword($hasher->hashPassword($user, 'secret-password'));
        $this->em->persist($user);

        $machine = new Machine();
        $machine->setName('Dispatch Unit')
            ->setIdentifier(sprintf('dispatch-%s-%s', $suffix, substr(Uuid::v7()->toBase32(), 0, 6)))
            ->setStatus(MachineStatus::Offline)
            ->setOwner($user);
        $this->em->persist($machine);
        $this->em->flush();

        return $machine;
    }
}
