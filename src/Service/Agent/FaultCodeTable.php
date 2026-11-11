<?php

declare(strict_types=1);

namespace App\Service\Agent;

/**
 * EXACT-MATCH fault-code explanation table (the exact match is the injection barrier: the
 * exact match is the injection barrier - any code not listed here returns
 * known:false and the model must not invent a meaning).
 *
 * DECIDED (session 1): start with the five device-reportable lifecycle
 * states, the derived-offline wording, and the numeric device fault codes
 * already published in the README API contract. Extend only as real firmware
 * fault codes land.
 */
final class FaultCodeTable
{
    private const ENTRIES = [
        'IDLE' => [
            'title' => 'Idle',
            'meaning' => 'The machine is on and reachable but not running a process.',
            'safeUserActions' => ['Start a recycling session from the machine controls.'],
        ],
        'HEATING' => [
            'title' => 'Heating',
            'meaning' => 'The heater is bringing the barrel up to the target temperature.',
            'safeUserActions' => ['Wait for the target temperature before starting to extrude.'],
        ],
        'EXTRUDING' => [
            'title' => 'Extruding',
            'meaning' => 'The machine is actively extruding filament.',
            'safeUserActions' => ['Monitor the dashboard while filament is produced.'],
        ],
        'PAUSED' => [
            'title' => 'Paused',
            'meaning' => 'A running process is paused and can be resumed.',
            'safeUserActions' => ['Resume from the machine controls when ready.'],
        ],
        'ERROR' => [
            'title' => 'Error',
            'meaning' => 'The device reported an error state. Details may exist in recent events.',
            'safeUserActions' => ['Check recent events, then stop the machine from the controls if it does not recover.'],
        ],
        'OFFLINE' => [
            'title' => 'Offline (derived)',
            'meaning' => 'No fresh report has arrived within the offline window; the last state may no longer be valid.',
            'safeUserActions' => ['Check power and the network, then wait until the machine reports again.'],
        ],
        '1' => [
            'title' => 'Sensor fault',
            'meaning' => 'A sensor fault was reported.',
            'safeUserActions' => [],
        ],
        '2' => [
            'title' => 'Hard over-temperature',
            'meaning' => 'A hard over-temperature condition was reported.',
            'safeUserActions' => [],
        ],
        '3' => [
            'title' => 'Heater no-rise',
            'meaning' => 'The heater failed to reach the expected temperature rise.',
            'safeUserActions' => [],
        ],
        '4' => [
            'title' => 'Thermal runaway',
            'meaning' => 'A thermal runaway fault was reported.',
            'safeUserActions' => [],
        ],
        '5' => [
            'title' => 'Cold-extrusion interlock',
            'meaning' => 'A cold-extrusion interlock was reported.',
            'safeUserActions' => [],
        ],
        '6' => [
            'title' => 'Watchdog',
            'meaning' => 'A watchdog fault was reported.',
            'safeUserActions' => [],
        ],
    ];

    /**
     * Exact match, case-insensitive on a strict-charset code. Anything else
     * is unknown - lookups never guess and never free-text-match.
     *
     * @return array<string, mixed> {title,meaning,safeUserActions} or {known:false}
     */
    public static function lookup(string $faultCode): array
    {
        $code = strtoupper(trim($faultCode));

        return self::ENTRIES[$code] ?? ['known' => false];
    }
}
