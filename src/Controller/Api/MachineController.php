<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Api\ApiResponse;
use App\Dto\Transformer\MachineViewFactory;
use App\Service\Machine\MachineQueryService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Machine read API (phase 1 foundation).
 *
 * POST /api/machines/{id}/commands is deliberately NOT implemented — IoT
 * command execution arrives in phase 2 (MQTT). See README.
 */
final class MachineController
{
    public function __construct(
        private readonly MachineQueryService $queryService,
        private readonly MachineViewFactory $views,
    ) {
    }

    /**
     * GET /api/machines — all machines.
     */
    #[Route('/api/machines', name: 'api_machine_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $machines = $this->queryService->listMachines();

        return ApiResponse::success(
            array_map($this->views->create(...), $machines),
            meta: ['count' => \count($machines)],
        );
    }

    /**
     * GET /api/machines/{id} — detail incl. latest telemetry + active session.
     */
    #[Route('/api/machines/{id}', name: 'api_machine_detail', methods: ['GET'])]
    public function detail(string $id): JsonResponse
    {
        $machine = $this->queryService->getMachine($id);

        return ApiResponse::success($this->views->createDetailed(
            $machine,
            $this->queryService->latestTelemetry($machine),
            $this->queryService->activeSession($machine),
        ));
    }

    /**
     * GET /api/machines/{id}/status — lightweight status poll (mobile app).
     *
     * Returns the effective status (offline derived from lastSeenAt) plus the
     * raw reported status, so clients can show "heating, but unreachable".
     */
    #[Route('/api/machines/{id}/status', name: 'api_machine_status', methods: ['GET'])]
    public function status(string $id): JsonResponse
    {
        $machine = $this->queryService->getMachine($id);
        $view = $this->views->create($machine);

        return ApiResponse::success([
            'machineId' => $view['id'],
            'identifier' => $view['identifier'],
            'name' => $view['name'],
            'status' => $view['status'],
            'reportedStatus' => $view['reportedStatus'],
            'lastSeenAt' => $view['lastSeenAt'],
            'secondsSinceLastSeen' => $view['secondsSinceLastSeen'],
        ]);
    }

    /**
     * GET /api/machines/{id}/dashboard — one round trip for the home/machine
     * screens: machine + effective status + latest & recent telemetry + active
     * session + recycling totals. Keeps web and mobile rendering identical data.
     */
    #[Route('/api/machines/{id}/dashboard', name: 'api_machine_dashboard', methods: ['GET'])]
    public function dashboard(string $id): JsonResponse
    {
        $machine = $this->queryService->getMachine($id);
        $dashboard = $this->queryService->buildDashboard($machine);

        $view = $this->views->create($machine);
        $view['telemetry'] = null !== $dashboard->latestTelemetry
            ? $this->views->telemetry($dashboard->latestTelemetry)
            : null;
        $view['activeSessionId'] = $dashboard->activeSession?->getId()->toRfc4122();
        $view['activeSession'] = null !== $dashboard->activeSession
            ? $this->views->session($dashboard->activeSession)
            : null;
        $view['recentTelemetry'] = array_map($this->views->telemetry(...), $dashboard->recentTelemetry);
        $view['recyclingTotals'] = $dashboard->recyclingTotals;

        return ApiResponse::success($view);
    }

    /**
     * GET /api/machines/{id}/telemetry — telemetry history, newest first.
     * Query params: ?limit=50&offset=0&since=ISO8601 (limit ≤ 200, hard cap).
     * Served as a DTO projection — no per-row machine hydration.
     */
    #[Route('/api/machines/{id}/telemetry', name: 'api_machine_telemetry', methods: ['GET'])]
    public function telemetry(string $id, Request $request): JsonResponse
    {
        $machine = $this->queryService->getMachine($id);
        [$limit, $offset] = Pagination::fromRequest($request);

        $sinceParam = $request->query->get('since');
        $since = \is_string($sinceParam) && '' !== $sinceParam
            ? new \DateTimeImmutable($sinceParam)
            : null;

        $rows = $this->queryService->telemetryHistoryRows($machine, $limit, $offset, $since);

        return ApiResponse::success(
            array_map($this->views->telemetryRow(...), $rows),
            meta: ['limit' => $limit, 'offset' => $offset, 'count' => \count($rows)],
        );
    }

    /**
     * GET /api/machines/{id}/sessions — session history, newest first.
     * Query params: ?limit=50&offset=0&status=in_progress|paused|completed|failed
     */
    #[Route('/api/machines/{id}/sessions', name: 'api_machine_sessions', methods: ['GET'])]
    public function sessions(string $id, Request $request): JsonResponse
    {
        $machine = $this->queryService->getMachine($id);
        [$limit, $offset] = Pagination::fromRequest($request);
        $status = $request->query->get('status');

        $rows = $this->queryService->sessionHistory(
            $machine,
            $limit,
            $offset,
            \is_string($status) && '' !== $status ? $status : null,
        );

        return ApiResponse::success(
            array_map($this->views->session(...), $rows),
            meta: ['limit' => $limit, 'offset' => $offset, 'count' => \count($rows)],
        );
    }

    /**
     * GET /api/machines/{id}/production — filament production records.
     * Query params: ?limit=50&offset=0
     */
    #[Route('/api/machines/{id}/production', name: 'api_machine_production', methods: ['GET'])]
    public function production(string $id, Request $request): JsonResponse
    {
        $machine = $this->queryService->getMachine($id);
        [$limit, $offset] = Pagination::fromRequest($request);

        $rows = $this->queryService->productionHistory($machine, $limit, $offset);

        return ApiResponse::success(
            array_map($this->views->production(...), $rows),
            meta: ['limit' => $limit, 'offset' => $offset, 'count' => \count($rows)],
        );
    }

    /**
     * GET /api/machines/{id}/recycling — recycling records + impact totals.
     * Query params: ?limit=50&offset=0
     */
    #[Route('/api/machines/{id}/recycling', name: 'api_machine_recycling', methods: ['GET'])]
    public function recycling(string $id, Request $request): JsonResponse
    {
        $machine = $this->queryService->getMachine($id);
        [$limit, $offset] = Pagination::fromRequest($request);

        $rows = $this->queryService->recyclingHistory($machine, $limit, $offset);
        $totals = $this->queryService->recyclingTotals($machine);

        return ApiResponse::success(
            [
                'records' => array_map($this->views->recycling(...), $rows),
                'totals' => [
                    'records' => $totals['records'],
                    'inputGrams' => $totals['inputGrams'],
                    'outputGrams' => $totals['outputGrams'],
                ],
            ],
            meta: ['limit' => $limit, 'offset' => $offset, 'count' => \count($rows)],
        );
    }
}
