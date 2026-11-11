<?php

declare(strict_types=1);

namespace App\Service\Agent\Tools;

use App\Service\Agent\AgentTool;
use App\Service\Agent\FaultCodeTable;

/**
 * Exact-match fault code lookup. The code arrives as UNTRUSTED data: it
 * must pass the strict charset pattern and match the static table exactly
 * (docs/ai-agent-risks.md R1). Unknown -> known:false - the model is then
 * expected to say the code is unknown instead of inventing a meaning.
 */
final class ExplainFaultCodeTool implements AgentTool
{
    /** Strict ASCII charset for any code this tool will touch. */
    private const CODE_PATTERN = '/^[A-Za-z0-9_-]{1,32}$/';

    public function name(): string
    {
        return 'explain_fault_code';
    }

    public function description(): string
    {
        return 'Look up a machine state/fault code in a fixed table of known codes and explanation data.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'faultCode' => [
                    'type' => 'string',
                    'maxLength' => 32,
                    'pattern' => '^[A-Za-z0-9_-]+$',
                ],
                'machineId' => ['type' => 'string'],
            ],
            'required' => ['faultCode'],
            'additionalProperties' => false,
        ];
    }

    public function execute(array $input): array
    {
        $faultCode = $input['faultCode'] ?? null;
        if (!\is_string($faultCode)) {
            return ['ok' => false, 'code' => 'VALIDATION_FAILED'];
        }

        if (1 !== preg_match(self::CODE_PATTERN, $faultCode)) {
            // Charset violation: the raw text never leaves this method.
            return ['ok' => true, 'data' => ['faultCode' => $faultCode, 'known' => false]];
        }

        $entry = FaultCodeTable::lookup($faultCode);
        if (isset($entry['known'])) {
            return ['ok' => true, 'data' => ['faultCode' => $faultCode, 'known' => false]];
        }

        return ['ok' => true, 'data' => [
            'faultCode' => strtoupper(trim($faultCode)),
            'known' => true,
            'title' => $entry['title'],
            'meaning' => $entry['meaning'],
            'safeUserActions' => $entry['safeUserActions'],
        ]];
    }
}
