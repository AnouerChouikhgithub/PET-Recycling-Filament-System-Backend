<?php

declare(strict_types=1);

namespace App\Tests\IoT;

use App\Entity\Machine;
use App\Entity\MachineStatus;
use App\Repository\MachineRepository;
use App\Service\IoT\MqttConfig;
use App\Service\IoT\MqttMessageHandler;
use App\Service\IoT\MqttTopicBuilder;
use App\Service\Machine\MachineConnectivityService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Behaviour of the MQTT consumer's message handler.
 *
 * Everything runs against the real handler + real TelemetryProcessor +
 * real database, with no broker: the socket is one seam away
 * (MqttSubscriberInterface) and the console command only wires the two.
 */
final class MqttMessageHandlerTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    private MqttMessageHandler $handler;

    private MqttTopicBuilder $topics;

    private MachineRepository $machines;

    private MachineConnectivityService $connectivity;

    private string $prefix;

    protected function setUp(): void
    {
        static::bootKernel();

        $container = static::getContainer();

        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        $this->em = $em;

        /** @var MqttMessageHandler $handler */
        $handler = $container->get(MqttMessageHandler::class);
        $this->handler = $handler;

        /** @var MqttTopicBuilder $topics */
        $topics = $container->get(MqttTopicBuilder::class);
        $this->topics = $topics;

        /** @var MachineRepository $machines */
        $machines = $container->get(MachineRepository::class);
        $this->machines = $machines;

        /** @var MachineConnectivityService $connectivity */
        $connectivity = $container->get(MachineConnectivityService::class);
        $this->connectivity = $connectivity;

        /** @var MqttConfig $config */
        $config = $container->get(MqttConfig::class);
        $this->prefix = $config->getPrefix();
    }

    public function testValidTelemetryIsStoredThroughTheSharedProcessor(): void
    {
        $machine = $this->createMachine('telemetry');
        $before = $this->totalTelemetryRows();

        $this->handler->handle(
            $this->topics->telemetry($machine->getIdentifier()),
            (string) json_encode([
                'temperature' => 192.4,
                'targetTemperature' => 245.0,
                'motorSpeed' => 42,
                'status' => 'extruding',
                'recordedAt' => '2026-01-01T00:00:00+00:00',
            ]),
        );

        self::assertSame($before + 1, $this->totalTelemetryRows(), 'the sample reached machine_telemetry');

        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT machine_id, temperature, motor_speed FROM machine_telemetry ORDER BY recorded_at DESC LIMIT 1',
        );
        self::assertIsArray($row);
        self::assertSame($machine->getId()->toRfc4122(), (string) $row['machine_id']);
        self::assertSame(192.4, (float) $row['temperature']);
        self::assertSame(42.0, (float) $row['motor_speed']);

        // Same pipeline as HTTP: liveness + reported status refreshed too.
        $reloaded = $this->reload($machine);
        self::assertNotNull($reloaded->getLastSeenAt(), 'ingestion marks the machine as seen');
        self::assertSame(MachineStatus::Extruding, $reloaded->getStatus());
    }

    public function testUnknownMachineIsDroppedAndNeverCreated(): void
    {
        $before = $this->totalTelemetryRows();
        $unknown = 'ghost-'.substr(Uuid::v7()->toBase32(), 0, 8);

        $this->handler->handle($this->topics->telemetry($unknown), '{"temperature":100.0}');

        self::assertSame($before, $this->totalTelemetryRows());
        self::assertNull(
            $this->machines->findByIdentifier($unknown),
            'MQTT must never create machines — an unknown identifier is dropped.',
        );
    }

    public function testOversizePayloadIsDropped(): void
    {
        $machine = $this->createMachine('oversize');
        $before = $this->totalTelemetryRows();

        $payload = (string) json_encode([
            'temperature' => 100.0,
            'extra' => ['blob' => str_repeat('x', 6000)],
        ]);
        self::assertGreaterThan(4096, strlen($payload), 'the fixture really is over MQTT_MAX_PAYLOAD_BYTES');

        $this->handler->handle($this->topics->telemetry($machine->getIdentifier()), $payload);

        self::assertSame($before, $this->totalTelemetryRows());
        self::assertArrayNotHasKey('failed', $this->handler->counters(), 'the loop never blew up');
    }

    public function testMalformedAndNonObjectPayloadsAreDropped(): void
    {
        $machine = $this->createMachine('malformed');
        $topic = $this->topics->telemetry($machine->getIdentifier());
        $before = $this->totalTelemetryRows();

        // Truncated JSON, a JSON array, and a bare scalar: none is an object.
        $this->handler->handle($topic, '{"temperature": 100.0');
        $this->handler->handle($topic, '[1, 2, 3]');
        $this->handler->handle($topic, '"just a string"');
        $this->handler->handle($topic, '');

        self::assertSame($before, $this->totalTelemetryRows(), 'malformed payloads are dropped, not crash-looped');
        self::assertArrayNotHasKey('failed', $this->handler->counters(), 'the loop survives every message');
    }

    public function testTopicOfMachineACannotUpdateMachineB(): void
    {
        $alpha = $this->createMachine('alpha');
        $beta = $this->createMachine('beta');
        $betaBefore = $this->totalTelemetryRowsFor($beta);

        // A payload that *names* machine B but arrives on machine A's topic is
        // refused outright: identity comes from the topic, and "machineId" is
        // not a telemetry channel.
        $this->handler->handle(
            $this->topics->telemetry($alpha->getIdentifier()),
            (string) json_encode(['machineId' => $beta->getId()->toRfc4122(), 'temperature' => 100.0]),
        );

        self::assertSame(0, $this->totalTelemetryRowsFor($alpha), 'unknown field rejected');
        self::assertSame($betaBefore, $this->totalTelemetryRowsFor($beta), 'machine B is untouched');

        // A well-formed message on A's topic only ever reaches A.
        $this->handler->handle($this->topics->telemetry($alpha->getIdentifier()), '{"temperature":100.0}');

        self::assertSame(1, $this->totalTelemetryRowsFor($alpha));
        self::assertSame($betaBefore, $this->totalTelemetryRowsFor($beta));
        self::assertNull($this->reload($beta)->getLastSeenAt(), 'B is not even marked as seen');
    }

    public function testStatusOnlineAndOfflineTransitions(): void
    {
        $machine = $this->createMachine('status', MachineStatus::Idle);
        $identifier = $machine->getIdentifier();
        $topic = $this->topics->status($identifier);

        self::assertTrue($this->connectivity->isOffline($this->reload($machine)), 'never seen -> offline');

        $this->handler->handle($topic, '{"online":true}');
        $online = $this->reload($machine);
        self::assertNotNull($online->getLastSeenAt(), 'online=true refreshes lastSeenAt');
        self::assertTrue($this->connectivity->isOnline($online));

        // Only {"online": ...} is accepted — extra fields are refused.
        $seenBeforeBad = $this->formatDate($online->getLastSeenAt());
        $this->handler->handle($topic, '{"online":false,"reason":"wifi"}');
        self::assertSame(
            $seenBeforeBad,
            $this->formatDate($this->reload($machine)->getLastSeenAt()),
            'a malformed status payload changes nothing',
        );

        // Last Will: online=false makes the DERIVED offline state effective
        // at once, without waiting MACHINE_OFFLINE_AFTER_MINUTES.
        $this->handler->handle($topic, '{"online":false}');
        $offline = $this->reload($machine);
        self::assertTrue($this->connectivity->isOffline($offline), 'online=false -> offline immediately');

        // offline is derived, never stored: the lifecycle status is untouched.
        self::assertSame(
            MachineStatus::Idle,
            $offline->getStatus(),
            'the Last Will must not write "offline" into the status column',
        );
    }

    public function testRateGuardIgnoresABurstOfTelemetry(): void
    {
        $machine = $this->createMachine('burst');
        $topic = $this->topics->telemetry($machine->getIdentifier());
        $before = $this->totalTelemetryRows();

        // MQTT_TELEMETRY_MAX_PER_SECOND defaults to 2: at most two messages
        // per rolling second, so a burst of five collapses to two rows.
        for ($i = 0; $i < 5; ++$i) {
            $this->handler->handle($topic, (string) json_encode(['temperature' => 100.0 + $i]));
        }

        self::assertSame($before + 2, $this->totalTelemetryRows(), 'the burst was rate-limited to the per-second budget');
        self::assertSame(3, $this->handler->counters()['rate_limited'] ?? 0);
    }

    public function testEntityManagerIsClearedBetweenMessages(): void
    {
        $machine = $this->createMachine('clear');
        self::assertTrue($this->em->contains($machine), 'the entity is managed before the message');

        $topic = $this->topics->telemetry($machine->getIdentifier());
        $this->handler->handle($topic, '{"temperature":100.0}');

        self::assertFalse(
            $this->em->contains($machine),
            'the EntityManager must be cleared after every message, or a long-running worker leaks',
        );

        // …and the next message still works from a clean slate.
        $this->handler->handle($topic, '{"temperature":101.0}');
        self::assertSame(2, $this->totalTelemetryRowsFor($machine));
    }

    public function testUnknownTopicBranchAndForeignPrefixAreDropped(): void
    {
        $machine = $this->createMachine('branches');
        $before = $this->totalTelemetryRows();

        $this->handler->handle($this->prefix.'/machines/'.$machine->getIdentifier().'/commands', '{"type":"start"}');
        $this->handler->handle('other-prefix/machines/'.$machine->getIdentifier().'/telemetry', '{"temperature":100.0}');
        $this->handler->handle($this->prefix.'/machines/'.$machine->getIdentifier(), '{"temperature":100.0}');

        self::assertSame($before, $this->totalTelemetryRows());
        self::assertArrayNotHasKey('failed', $this->handler->counters());
    }

    // ---------------------------------------------------------------- helpers

    private function createMachine(string $suffix, MachineStatus $status = MachineStatus::Offline): Machine
    {
        $machine = new Machine();
        $machine->setName('Handler Unit')
            ->setIdentifier(sprintf('handler-%s-%s', $suffix, substr(Uuid::v7()->toBase32(), 0, 6)))
            ->setStatus($status);
        $this->em->persist($machine);
        $this->em->flush();

        return $machine;
    }

    /**
     * Fresh copy from the database — the handler clears the EntityManager
     * after every message, so entity references go stale by design.
     */
    private function reload(Machine $machine): Machine
    {
        $reloaded = $this->em->find(Machine::class, $machine->getId());
        if (!$reloaded instanceof Machine) {
            throw new \LogicException('Machine disappeared from the database.');
        }

        return $reloaded;
    }

    private function totalTelemetryRows(): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM machine_telemetry');
    }

    private function totalTelemetryRowsFor(Machine $machine): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM machine_telemetry WHERE machine_id = :id',
            ['id' => $machine->getId()->toRfc4122()],
        );
    }

    private function formatDate(?\DateTimeImmutable $value): ?string
    {
        return $value instanceof \DateTimeImmutable ? $value->format(\DateTimeInterface::ATOM) : null;
    }
}
