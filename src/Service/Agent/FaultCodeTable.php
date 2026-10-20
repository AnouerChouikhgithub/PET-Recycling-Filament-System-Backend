<?php

declare(strict_types=1);

namespace App\Service\Agent;

/**
 * EXACT-MATCH fault-code explanation table (docs/ai-agent-risks.md R1: the
 * exact match is the injection barrier - any code not listed here returns
 * known:false and the model must not invent a meaning).
 *
 * DECIDED (session 1): start minimal - only the five device-reportable
 * lifecycle states plus the derived-offline wording out of
 * docs/api-contract.md. Extended only as real firmware fault codes land.
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
