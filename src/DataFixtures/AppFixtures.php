<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\FilamentProduction;
use App\Entity\FilamentQuality;
use App\Entity\Machine;
use App\Entity\MachineSession;
use App\Entity\MachineStatus;
use App\Entity\MachineTelemetry;
use App\Entity\RecyclingSession;
use App\Entity\SessionStatus;
use App\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * DEVELOPMENT fixtures only — clearly-labelled demo data using REALISTIC PET
 * values from the hardware's real firmware (PID target 245 °C).
 *
 * HONESTY RULES (machine contract, see the hardware repo README):
 *   - the Arduino firmware MEASURES: temperature (thermistor), drives the
 *     heater (PWM) and the stepper (µs-delay speed). So fixtures fill
 *     temperature + targetTemperature (245) + motor presence + speed;
 *   - the firmware has NO fan, NO diameter sensor (FUTURE), NO energy meter →
 *     those channels stay NULL. Clients render "not reported" for them.
 *
 * Load with:  php bin/console doctrine:fixtures:load
 * (never run against a production database)
 */
final class AppFixtures extends Fixture
{
    /** Documented dev password — ONLY valid on a local development database. */
    public const DEV_PASSWORD = '3awedlou-dev';

    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $now = new \DateTimeImmutable();

        // ---- users ---------------------------------------------------------
        $anouer = $this->createUser($manager, 'anouer@3awedlou.app', 'Anouer');
        $sara = $this->createUser($manager, 'sara@3awedlou.app', 'Sara');

        // ---- machines ------------------------------------------------------
        $machine1 = new Machine();
        $machine1->setName('3awedlou Prototype 001')
            ->setIdentifier('3awedlou-001')
            ->setStatus(MachineStatus::Extruding)
            ->setOwner($anouer)
            ->markAsSeen($now->modify('-2 minutes'));
        $manager->persist($machine1);

        $machine2 = new Machine();
        $machine2->setName('Lab Unit 002')
            ->setIdentifier('3awedlou-002')
            ->setStatus(MachineStatus::Idle)
            ->setOwner($anouer)
            ->markAsSeen($now->modify('-3 hours'));
        $manager->persist($machine2);

        // Sara's machine — ownership-isolation tests and demos rely on
        // Anouer NOT seeing this one.
        $machine3 = new Machine();
        $machine3->setName('Sara Workshop Unit')
            ->setIdentifier('3awedlou-003')
            ->setStatus(MachineStatus::Idle)
            ->setOwner($sara)
            ->markAsSeen($now->modify('-35 minutes'));
        $manager->persist($machine3);

        // ---- telemetry: recent history for machine 1 -----------------------
        $this->addTelemetryHistory($manager, $machine1, $now, 24, 5, true);
        // a few stale samples for machine 2 (offline since 3h)
        $this->addTelemetryHistory($manager, $machine2, $now->modify('-3 hours'), 6, 10, false);
        $this->addTelemetryHistory($manager, $machine3, $now->modify('-35 minutes'), 4, 5, false);

        // ---- sessions: history mirroring the frontends' mock data ----------
        // #128 — currently running
        $s128 = $this->createSession($manager, $machine1, $anouer, $now->modify('-84 minutes'), null, SessionStatus::InProgress, 550.0, null, 'Mixed clear bottles');
        // #127 — last completed run
        $s127 = $this->createSession($manager, $machine1, $anouer, $now->modify('-2 hours'), $now->modify('-25 minutes'), SessionStatus::Completed, 610.0, 505.0, 'Green bottles batch');
        // #126 — completed run by Sara
        $s126 = $this->createSession($manager, $machine1, $sara, $now->modify('-27 hours'), $now->modify('-25 hours -42 minutes'), SessionStatus::Completed, 480.0, 400.0, 'First run after nozzle cleaning');
        // #120 — failed run
        $s120 = $this->createSession($manager, $machine1, $anouer, $now->modify('-98 hours'), $now->modify('-97 hours -38 minutes'), SessionStatus::Failed, 300.0, 0.0, 'Stopped early — moisture in the feed.');

        // ---- filament production records -----------------------------------
        $this->createProduction($manager, $s127, 'F-081', 1.75, 1.752, [1.74, 1.75, 1.76, 1.75], 505.0, 168.0, 95.0, 'Forest Green', '#166534', FilamentQuality::Excellent, $now->modify('-30 minutes'));
        $this->createProduction($manager, $s126, 'F-080', 1.75, 1.747, [1.74, 1.75, 1.74, 1.75], 400.0, 133.0, 78.0, 'Natural', '#e8e4da', FilamentQuality::Good, $now->modify('-26 hours'));
        $this->createProduction($manager, $s120, 'F-077', 1.75, 1.61, [1.58, 1.60, 1.65], 0.0, 12.0, 22.0, 'Crimson Red', '#dc2626', FilamentQuality::Poor, $now->modify('-97 hours -35 minutes'));

        // ---- recycling records ---------------------------------------------
        $this->createRecycling($manager, $s128, $machine1, 550.0, null, null, 192.0, $now->modify('-84 minutes'), 'Session still running');
        $this->createRecycling($manager, $s127, $machine1, 610.0, 505.0, 95.0, 194.0, $now->modify('-2 hours'), null);
        $this->createRecycling($manager, $s126, $machine1, 480.0, 400.0, 78.0, 191.0, $now->modify('-27 hours'), null);
        $this->createRecycling($manager, $s120, $machine1, 300.0, 0.0, 22.0, 188.0, $now->modify('-98 hours'), 'Moisture bubbles detected in filament.');

        $manager->flush();
    }

    private function createUser(ObjectManager $manager, string $email, string $name): User
    {
        $user = new User();
        $user->setEmail($email)
            ->setName($name)
            ->setPassword($this->passwordHasher->hashPassword($user, self::DEV_PASSWORD));
        $manager->persist($user);

        return $user;
    }

    /**
     * Generates a plausible telemetry history around the firmware's REAL
     * PET setpoint (245 °C). Channels the hardware cannot measure (fan,
     * diameter, energy) stay null — no invented measurements.
     */
    private function addTelemetryHistory(ObjectManager $manager, Machine $machine, \DateTimeImmutable $end, int $samples, int $stepMinutes, bool $extruding): void
    {
        $temperature = $extruding ? 243.0 : 24.0;

        for ($i = $samples; $i > 0; --$i) {
            $recordedAt = $end->modify(sprintf('-%d minutes', ($i - 1) * $stepMinutes));

            $t = new MachineTelemetry();
            $t->setMachine($machine)
                ->setRecordedAt($recordedAt);

            if ($extruding) {
                $temperature += random_int(-15, 15) / 10.0;
                $t->setTemperature(round($temperature, 1))
                    // The firmware's real PID target (hardware repo, code.ino).
                    ->setTargetTemperature(245.0)
                    ->setHeaterState(true)
                    ->setMotorState(true)
                    // Stepper speed setting on the firmware's 0-100 scale.
                    ->setMotorSpeed(40 + random_int(0, 5));
                    // fanState / filamentSpeed / filamentDiameter /
                    // energyConsumption: NO hardware source → stay null.
            } else {
                $t->setTemperature(round($temperature + random_int(0, 5) / 10.0, 1))
                    ->setHeaterState(false)
                    ->setMotorState(false)
                    ->setMotorSpeed(0);
            }

            $manager->persist($t);
        }
    }

    private function createSession(
        ObjectManager $manager,
        Machine $machine,
        User $operator,
        \DateTimeImmutable $startedAt,
        ?\DateTimeImmutable $endedAt,
        SessionStatus $status,
        ?float $materialInput,
        ?float $materialOutput,
        ?string $notes,
    ): MachineSession {
        $session = new MachineSession();
        $session->setMachine($machine)
            ->setOperator($operator)
            ->setStartedAt($startedAt)
            ->setEndedAt($endedAt)
            ->setStatus($status)
            ->setMaterialInput($materialInput)
            ->setMaterialOutput($materialOutput)
            ->setNotes($notes);
        $manager->persist($session);

        return $session;
    }

    /**
     * @param list<float> $samples
     */
    private function createProduction(
        ObjectManager $manager,
        MachineSession $session,
        string $batchCode,
        float $diameterTarget,
        float $diameterActual,
        array $samples,
        float $weightGrams,
        float $lengthMeters,
        float $durationMinutes,
        string $colorName,
        string $colorHex,
        FilamentQuality $quality,
        \DateTimeImmutable $producedAt,
    ): FilamentProduction {
        $production = new FilamentProduction();
        $production->setSession($session)
            ->setBatchCode($batchCode)
            ->setDiameterTarget($diameterTarget)
            ->setDiameterActual($diameterActual)
            ->setDiameterSamples($samples)
            ->setWeightGrams($weightGrams)
            ->setLengthMeters($lengthMeters)
            ->setDurationMinutes($durationMinutes)
            ->setMaterial('rPET')
            ->setColorName($colorName)
            ->setColorHex($colorHex)
            ->setQuality($quality)
            ->setProducedAt($producedAt);
        $manager->persist($production);

        return $production;
    }

    private function createRecycling(
        ObjectManager $manager,
        MachineSession $session,
        Machine $machine,
        ?float $inputGrams,
        ?float $outputGrams,
        ?float $durationMinutes,
        ?float $avgTemperature,
        \DateTimeImmutable $recycledAt,
        ?string $notes,
    ): RecyclingSession {
        $recycling = new RecyclingSession();
        $recycling->setSession($session)
            ->setMachine($machine)
            ->setInputMaterial('PET')
            ->setInputMassGrams($inputGrams)
            ->setOutputMaterial(null !== $outputGrams ? 'rPET filament' : null)
            ->setOutputMassGrams($outputGrams)
            ->setDurationMinutes($durationMinutes)
            ->setAvgTemperature($avgTemperature)
            ->setRecycledAt($recycledAt)
            ->setNotes($notes);
        $manager->persist($recycling);

        return $recycling;
    }
}
