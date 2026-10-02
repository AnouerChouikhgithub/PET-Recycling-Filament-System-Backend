<?php

declare(strict_types=1);

namespace App\Api;

use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Consistent JSON envelope for every API response.
 *
 * Success: { "success": true,  "data": ... }
 * Error:   { "success": false, "error": { "code": ..., "message": ..., "details"? ... } }
 */
final class ApiResponse
{
    /**
     * @param array<string, mixed> $meta optional pagination/extra info next to "data"
     */
    public static function success(mixed $data, int $status = 200, array $meta = []): JsonResponse
    {
        $body = ['success' => true, 'data' => $data];
        if ([] !== $meta) {
            $body['meta'] = $meta;
        }

        return self::json($body, $status);
    }

    /**
     * @param array<string, mixed> $details optional machine-readable details (e.g. field violations)
     */
    public static function error(string $code, string $message, int $status, array $details = []): JsonResponse
    {
        $error = ['code' => $code, 'message' => $message];
        if ([] !== $details) {
            $error['details'] = $details;
        }

        return self::json(['success' => false, 'error' => $error], $status);
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function json(array $body, int $status): JsonResponse
    {
        $response = new JsonResponse(status: $status);

        // JSON_PRESERVE_ZERO_FRACTION keeps floats as floats (192.0 not 192)
        // so web/mobile clients always receive consistent numeric types.
        $response->setEncodingOptions($response->getEncodingOptions() | JSON_PRESERVE_ZERO_FRACTION);

        // setData() encodes with the options set above.
        return $response->setData($body);
    }
}
