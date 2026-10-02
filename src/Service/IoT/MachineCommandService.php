<?php

declare(strict_types=1);

namespace App\Service\IoT;

use App\Api\Exception\ValidationException;
use App\Entity\Machine;
use App\Entity\MachineCommandAudit;
use App\Entity\MachineCommandType;
use App\Repository\MachineCommandAuditRepository;
use App\Security\DeviceUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Machine command pipeline:
 *
 *     POST /api/machines/{id}/commands
 *        → MachineCommandService::dispatch()
 *        → MachineCommandGuard (safety rules, config-driven ranges)
 *        → MachineCommandAudit row (WHO sent WHAT, WHEN)
 *        → MachineCommandEvent (realtime/fan-out seam)
 *        → MqttPublisherInterface (3awedlou/machines/{id}/commands)
 *
 * HONEST STATUS: the default publisher buffers + logs — NOTHING reaches a
 * device yet. Acceptance means "validated, audited and handed to the
 * transport", never "executed"; the API response carries
 * `delivery: buffered-log` so clients can say so truthfully.
 */
final class MachineCommandService
{
    public const EVENT_NAME = 'app.machine_command_dispatched';

    public function __construct(
        private readonly MachineCommandGuard $guard,
        private readonly MqttPublisherInterface $mqtt,
        private readonly MqttTopicBuilder $topics,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly EntityManagerInterface $em,
        private readonly TokenStorageInterface $tokenStorage,
        /** Human-readable transport name recorded in the audit trail. */
        private readonly string $transportName = 'buffered-log',
    ) {
    }

    /**
     * @param array<string,mixed> $payload command + optional value/params
     *
     * @throws ValidationException unsafe/invalid command
     *
     * @return array<string, mixed> accepted-command view (API response data)
     */
    public function dispatch(Machine $machine, array $payload): array
    {
        if (!\array_key_exists('command', $payload) || !\is_string($payload['command'])) {
            throw new ValidationException('Command rejected.', [
                'command' => ['A command name is required.'],
            ]);
        }

        $type = MachineCommandType::tryFrom($payload['command']);
        if (null === $type) {
            throw new ValidationException('Command rejected.', [
                'command' => ['Unknown command. Accepted: '.implode(', ', MachineCommandType::values()).'.'],
            ]);
        }

        $value = $this->guard->guard($machine, $type, $payload);

        $sentAt = new \DateTimeImmutable();
        $message = [
            'command' => $type->value,
            'sentAt' => $sentAt->format(\DateTimeInterface::ATOM),
        ];
        if (null !== $value) {
            $message['value'] = $value;
        }
        if (\array_key_exists('params', $payload) && \is_array($payload['params'])) {
            $message['params'] = $payload['params'];
        }

        $topic = $this->topics->commands($machine->getIdentifier());
        $this->mqtt->publish($topic, (string) json_encode($message));

        // Audit trail: who sent which command to which machine, when, and
        // through which transport (buffered-log until a real broker exists).
        $caller = null;
        $tokenUser = $this->tokenStorage->getToken()?->getUser();
        if ($tokenUser instanceof \App\Entity\User) {
            $caller = $tokenUser;
        }
        $this->em->persist(new MachineCommandAudit($machine, $caller, $type, null === $value ? null : (float) $value));
        $this->em->flush();

        $view = [
            'machineId' => $machine->getId()->toRfc4122(),
            'identifier' => $machine->getIdentifier(),
            'command' => $type->value,
            'value' => $value,
            'topic' => $topic,
            'accepted' => true,
            // Honesty marker: clients must NOT treat this as machine execution.
            'delivery' => $this->transportName,
            'deviceAcknowledged' => false,
            'sentAt' => $message['sentAt'],
        ];

        $this->dispatcher->dispatch(new MachineCommandEvent($machine, $type, $message, $view), self::EVENT_NAME);

        return $view;
    }
}
