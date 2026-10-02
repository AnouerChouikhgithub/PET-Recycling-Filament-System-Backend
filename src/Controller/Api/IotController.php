<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Api\ApiResponse;
use App\Service\IoT\MachineCommandService;
use App\Service\IoT\TelemetryProcessor;
use App\Service\Machine\MachineQueryService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * IoT-facing write API (phase 2 foundation).
 *
 * - POST /api/machines/{id}/telemetry  → shared TelemetryProcessor pipeline
 * - POST /api/machines/{id}/commands   → guarded remote control via MQTT
 *
 * Both endpoints stay thin: validation and business rules live in the
 * services, per the Controller → Service → Repository architecture.
 */
final class IotController
{
    public function __construct(
        private readonly MachineQueryService $queryService,
        private readonly TelemetryProcessor $telemetryProcessor,
        private readonly MachineCommandService $commandService,
    ) {
    }

    /**
     * POST /api/machines/{id}/telemetry — ingest one sample.
     *
     * Intended for the ESP32 (directly, or via the future MQTT consumer which
     * calls the same TelemetryProcessor — no duplicated ingestion logic).
     */
    #[Route('/api/machines/{id}/telemetry', name: 'api_machine_telemetry_ingest', methods: ['POST'])]
    public function ingestTelemetry(string $id, Request $request): JsonResponse
    {
        $machine = $this->queryService->getMachineForIngest($id);
        $payload = $this->decodeBody($request);

        $telemetry = $this->telemetryProcessor->process($machine, $payload);

        return ApiResponse::success(
            $this->telemetryProcessor->telemetryView($telemetry),
            201,
            ['machineId' => $machine->getId()->toRfc4122()],
        );
    }

    /**
     * POST /api/machines/{id}/commands — dispatch a guarded remote command.
     *
     * Acceptance ≠ execution: the command is validated against the machine's
     * current state and safe parameter ranges, then published to
     * `3awedlou/machines/{identifier}/commands` for the ESP32 to act on.
     */
    #[Route('/api/machines/{id}/commands', name: 'api_machine_commands', methods: ['POST'])]
    public function commands(string $id, Request $request): JsonResponse
    {
        $machine = $this->queryService->getMachine($id);
        $payload = $this->decodeBody($request);

        return ApiResponse::success($this->commandService->dispatch($machine, $payload), 202);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeBody(Request $request): array
    {
        $content = $request->getContent();
        if ('' === trim($content)) {
            throw new BadRequestHttpException('Request body must be a JSON object.');
        }

        try {
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new BadRequestHttpException('Request body is not valid JSON.');
        }

        return \is_array($decoded) ? $decoded : throw new BadRequestHttpException('Request body must be a JSON object.');
    }
}
