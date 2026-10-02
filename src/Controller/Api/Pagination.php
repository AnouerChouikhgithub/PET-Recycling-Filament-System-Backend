<?php

declare(strict_types=1);

namespace App\Controller\Api;

use Symfony\Component\HttpFoundation\Request;

/**
 * Shared pagination parsing for list endpoints (?limit=&offset=).
 *
 * Hard caps keep any single request bounded: default page 50, never more
 * than 200 per page (per-endpoint repository caps are the same number).
 */
final class Pagination
{
    public const DEFAULT_LIMIT = 50;
    public const MAX_LIMIT = 200;

    /**
     * @return array{0: int, 1: int} [limit, offset]
     */
    public static function fromRequest(Request $request, int $defaultLimit = self::DEFAULT_LIMIT): array
    {
        $limit = filter_var($request->query->get('limit'), FILTER_VALIDATE_INT, [
            'options' => ['default' => $defaultLimit, 'min_range' => 1, 'max_range' => self::MAX_LIMIT],
        ]);

        $offset = filter_var($request->query->get('offset'), FILTER_VALIDATE_INT, [
            'options' => ['default' => 0, 'min_range' => 0],
        ]);

        return [$limit, $offset];
    }
}
