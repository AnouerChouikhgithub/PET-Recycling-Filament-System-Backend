<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Api\ApiResponse;
use App\Api\Exception\AgentDailyTokenCapException;
use App\Api\Exception\AgentLlmUnavailableException;
use App\Api\Exception\ResourceNotFoundException;
use App\Entity\User;
use App\Repository\AgentConversationRepository;
use App\Service\Agent\AgentConfig;
use App\Service\Agent\AgentOrchestrator;
use App\Service\Agent\TurnGuard;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Agent HTTP surface (all non-streaming in this session).
 *
 *   POST   /api/agent/chat                -> 200 turn result
 *   DELETE /api/agent/conversations/{id}  -> 200 (soft delete, owner-scoped)
 *   DELETE /api/agent/conversations       -> 200 (soft delete all, owner)
 *
 * AGENT_ENABLED=false makes EVERY route here answer 404 AGENT_DISABLED
 * (never 401/403 - the surface pretends not to exist; T4.4).
 */
final class AgentController
{
    public function __construct(
        private readonly AgentConfig $config,
        private readonly AgentOrchestrator $orchestrator,
        private readonly TurnGuard $guard,
        private readonly AgentConversationRepository $conversations,
        private readonly EntityManagerInterface $em,
        private readonly TokenStorageInterface $tokenStorage,
    ) {
    }

    #[Route('/api/agent/chat', name: 'api_agent_chat', methods: ['POST'])]
    public function chat(Request $request): JsonResponse
    {
        $user = $this->requireUser();
        $this->assertEnabled();

        $payload = $this->decodeBody($request);

        $conversationId = $payload['conversationId'] ?? null;
        if (null !== $conversationId && !\is_string($conversationId)) {
            return ApiResponse::error('VALIDATION_FAILED', 'conversationId, when present, must be a string.', 422);
        }

        $message = $payload['message'] ?? null;
        if (!\is_string($message)) {
            return ApiResponse::error('VALIDATION_FAILED', 'A "message" string is required.', 422, ['message' => ['A message string is required.']]);
        }

        $message = trim($message);
        $length = mb_strlen($message);
        if ($length < AgentConfig::MIN_MESSAGE_CHARS || $length > $this->config->msgMaxChars) {
            return ApiResponse::error('VALIDATION_FAILED', sprintf('The message must be between %d and %d characters.', AgentConfig::MIN_MESSAGE_CHARS, $this->config->msgMaxChars), 422, ['message' => [sprintf('Length must be between %d and %d.', AgentConfig::MIN_MESSAGE_CHARS, $this->config->msgMaxChars)]]);
        }

        // Guards BEFORE anything expensive (T3.1): per-user rate limit.
        $retryAfter = $this->guard->reserveMessage($user);
        if (null !== $retryAfter) {
            $response = ApiResponse::error('AGENT_RATE_LIMITED', 'Too many agent messages. Try again later.', 429);
            $response->headers->set('Retry-After', (string) $retryAfter);

            return $response;
        }

        // NOTE (phase D): the daily token cap is enforced by the orchestrator
        // with an ATOMIC per-user reserve BEFORE the LLM call (and before
        // anything is persisted) - two parallel turns can never both spend
        // the same remaining budget.
        try {
            $turn = $this->orchestrator->turn($user, $conversationId, $message);
        } catch (ResourceNotFoundException $exception) {
            return ApiResponse::error($exception->errorCode, $exception->getMessage(), 404);
        } catch (AgentDailyTokenCapException) {
            $response = ApiResponse::error('AGENT_DAILY_TOKEN_CAP', 'Daily AI usage limit reached. Resets at 00:00 UTC.', 429);
            $response->headers->set('Retry-After', '60');

            return $response;
        } catch (AgentLlmUnavailableException) {
            $response = ApiResponse::error('AGENT_LLM_UNAVAILABLE', 'The assistant service is temporarily unavailable. Please try again shortly.', 503);
            $response->headers->set('Retry-After', '5');

            return $response;
        }

        return ApiResponse::success([
            'conversationId' => $turn['conversation']->getId()->toRfc4122(),
            'message' => [
                'id' => $turn['assistantMessage']->getId()->toRfc4122(),
                'role' => 'assistant',
                'content' => $turn['answer'],
                'toolCalls' => $turn['toolCalls'],
                'createdAt' => $turn['assistantMessage']->getCreatedAt()->format(\DateTimeInterface::ATOM),
            ],
        ], 200, ['usage' => $turn['usage']]);
    }

    #[Route('/api/agent/conversations/{id}', name: 'api_agent_conversation_delete', methods: ['DELETE'])]
    public function deleteConversation(string $id): JsonResponse
    {
        $user = $this->requireUser();
        $this->assertEnabled();

        $conversation = $this->conversations->findOwnedOrExcluded($id, $user);
        if (null === $conversation) {
            // Same 404 envelope for unknown AND foreign conversations (T1.7).
            return ApiResponse::error('AGENT_NOT_FOUND', 'Conversation not found.', 404);
        }

        $conversation->softDelete();
        $this->em->flush();

        return ApiResponse::success(['deleted' => true, 'conversationId' => $id]);
    }

    #[Route('/api/agent/conversations', name: 'api_agent_conversations_delete_all', methods: ['DELETE'])]
    public function deleteAllConversations(): JsonResponse
    {
        $user = $this->requireUser();
        $this->assertEnabled();

        $count = $this->conversations->softDeleteAllFor($user);
        $this->em->flush();

        return ApiResponse::success(['deleted' => true, 'count' => $count]);
    }

    /** Flag off = 404 AGENT_DISABLED for the whole surface. */
    private function assertEnabled(): void
    {
        if (!$this->config->enabled) {
            throw new NotFoundHttpException('AGENT_DISABLED');
        }
    }

    private function requireUser(): User
    {
        $user = $this->tokenStorage->getToken()?->getUser();
        if (!$user instanceof User) {
            throw new NotFoundHttpException('AGENT_DISABLED');
        }

        return $user;
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
