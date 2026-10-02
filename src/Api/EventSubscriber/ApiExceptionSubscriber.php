<?php

declare(strict_types=1);

namespace App\Api\EventSubscriber;

use App\Api\ApiResponse;
use App\Api\Exception\ResourceNotFoundException;
use App\Api\Exception\ValidationException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Validator\Exception\ValidationFailedException;

/**
 * Renders every exception reaching the API as the consistent JSON envelope:
 *
 *   { "success": false, "error": { "code": ..., "message": ..., "details"? } }
 *
 * Internal errors (500) are NEVER exposed — clients only receive a generic
 * message; the real error stays in the logs / profiler.
 *
 * Applied only to /api/ routes so developer HTML error pages (profiler) and
 * the Twig UI keep working in dev.
 */
final class ApiExceptionSubscriber implements EventSubscriberInterface
{
    /**
     * Domain exceptions → stable machine-readable codes + HTTP status.
     * Configured in config/packages/services.yaml so new mappings need no code change.
     *
     * @param array<class-string, array{status: int, code: string}> $exceptionMap
     */
    public function __construct(
        private readonly TokenStorageInterface $tokenStorage,
        private readonly array $exceptionMap = [],
        private readonly bool $debug = false,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // run early, before the standard exception handling converts to HTML
        return [
            KernelEvents::EXCEPTION => ['onKernelException', 10],
        ];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        if (!str_starts_with($request->getPathInfo(), '/api/')) {
            return;
        }

        $throwable = $event->getThrowable();
        $response = $this->createErrorResponse($throwable);
        $event->setResponse($response);
    }

    private function createErrorResponse(\Throwable $throwable): Response
    {
        // ---- domain exceptions -------------------------------------------------
        if ($throwable instanceof ResourceNotFoundException) {
            return ApiResponse::error($throwable->errorCode, $throwable->getMessage(), Response::HTTP_NOT_FOUND);
        }

        if ($throwable instanceof ValidationException) {
            return ApiResponse::error('VALIDATION_FAILED', $throwable->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY, $throwable->violations);
        }

        // ---- Symfony internal exceptions we want to wrap consistently ----------
        if ($throwable instanceof ValidationFailedException) {
            return ApiResponse::error('VALIDATION_FAILED', 'The provided data is invalid.', Response::HTTP_UNPROCESSABLE_ENTITY, $this->violationDetails($throwable));
        }

        if ($throwable instanceof AccessDeniedException || $throwable instanceof AccessDeniedHttpException) {
            $token = $this->tokenStorage->getToken();

            // No credentials at all → 401 (log in). Authenticated but
            // insufficient role → 403 (forbidden).
            if (null === $token || $token instanceof NullToken) {
                return ApiResponse::error('UNAUTHORIZED', 'Authentication required. Provide a Bearer token.', Response::HTTP_UNAUTHORIZED);
            }

            return ApiResponse::error('FORBIDDEN', 'You are not allowed to access this resource.', Response::HTTP_FORBIDDEN);
        }

        foreach ($this->exceptionMap as $class => $config) {
            if ($throwable instanceof $class) {
                return ApiResponse::error($config['code'], $throwable->getMessage(), $config['status']);
            }
        }

        if ($throwable instanceof HttpExceptionInterface) {
            // #[MapRequestPayload] wraps ValidationFailedException in a
            // HttpException — unwrap it so clients get per-field details + 422.
            $previous = $throwable->getPrevious();
            if ($previous instanceof ValidationFailedException) {
                return ApiResponse::error('VALIDATION_FAILED', 'The provided data is invalid.', Response::HTTP_UNPROCESSABLE_ENTITY, $this->violationDetails($previous));
            }

            return ApiResponse::error(
                $this->errorCodeFor($throwable),
                $throwable->getMessage() ?: (Response::$statusTexts[$throwable->getStatusCode()] ?? 'Unexpected error.'),
                $throwable->getStatusCode(),
            );
        }

        // ---- fallback: never leak internals ------------------------------------
        if ($this->debug) {
            // In dev the profiler still shows the real exception; the envelope
            // keeps the API contract stable for the frontends.
            return ApiResponse::error('INTERNAL_ERROR', sprintf('[dev] %s', $throwable->getMessage()), Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return ApiResponse::error('INTERNAL_ERROR', 'An unexpected error occurred.', Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    /**
     * @return array<string, list<string>>
     */
    private function violationDetails(ValidationFailedException $exception): array
    {
        $details = [];
        foreach ($exception->getViolations() as $violation) {
            $details[$violation->getPropertyPath()][] = $violation->getMessage();
        }

        return $details;
    }

    private function errorCodeFor(HttpExceptionInterface $exception): string
    {
        $fallback = match ($exception->getStatusCode()) {
            400 => 'BAD_REQUEST',
            401 => 'UNAUTHORIZED',
            402 => 'PAYMENT_REQUIRED',
            404 => 'NOT_FOUND',
            405 => 'METHOD_NOT_ALLOWED',
            409 => 'CONFLICT',
            429 => 'TOO_MANY_REQUESTS',
            default => 'HTTP_ERROR_' . $exception->getStatusCode(),
        };

        return $fallback;
    }
}
