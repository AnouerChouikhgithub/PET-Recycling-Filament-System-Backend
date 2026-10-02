<?php

declare(strict_types=1);

namespace App\Security;

use App\Api\ApiResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * Entry point for the /api firewall: invoked when a protected route is hit
 * without any authentication token. Returns the consistent 401 envelope
 * (invalid/expired tokens are handled by JwtFailureFormatSubscriber instead).
 */
final class ApiAuthenticationEntryPoint implements AuthenticationEntryPointInterface
{
    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return ApiResponse::error(
            'UNAUTHORIZED',
            'Authentication required. Provide a Bearer token.',
            Response::HTTP_UNAUTHORIZED,
        );
    }
}
