<?php

declare(strict_types=1);

namespace App\Service\Agent;

use App\Api\Exception\AgentDailyTokenCapException;
use App\Api\Exception\AgentLlmUnavailableException;
use App\Api\Exception\ResourceNotFoundException;
use App\Entity\AgentConversation;
use App\Entity\AgentMessage;
use App\Entity\User;
use App\Repository\AgentConversationRepository;
use App\Repository\AgentMessageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Internal marker: the model call already reconciled the reservation in
 * modelCall(); the outer catch must not bill twice.
 */
final class ReconciledLlmFailure extends AgentLlmUnavailableException
{
}

/**
 * One chat turn:
 *
 *   resolve conversation (foreign/unknown = standard 404, BEFORE any spend)
 *     -> ATOMIC token reserve (whole-turn worst case; over cap -> 429)
 *     -> persist the user message
 *     -> build context (last N messages, oldest first)
 *     -> LLM loop (max N tool rounds; tool input schema-validated BEFORE
 *        execution; tool results ONLY in tool_result blocks, bounded in
 *        size, JSON-encoded; unknown tool name -> error result, never
 *        executed - T2.2/T4.2)
 *     -> persist assistant message + settle the REAL usage in ONE
 *        transaction (or reconcile the conservative floor on failure)
 *
 * Provider/turn failures: the reservation is reconciled (billed at the
 * conservative floor, remainder refunded) and a controlled
 * AgentLlmUnavailableException (503) / cap exception (429) is rethrown -
 * no client ever sees provider details and no turn is double-billed.
 *
 * Logging: agent channel, METADATA ONLY (ids, tool names, counts) - never
 * message content or tool payloads (docs/ai-agent-test-plan.md T7.3).
 */
final class AgentOrchestrator
{
    /** Hard byte bound for ONE tool_result payload. */
    public const TOOL_RESULT_MAX_BYTES = 8000;

    public function __construct(
        private readonly LlmClientInterface $llm,
        private readonly ToolRegistry $registry,
        private readonly AgentConversationRepository $conversations,
        private readonly AgentMessageRepository $messages,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $agentLogger,
        private readonly AgentConfig $config,
        private readonly TurnGuard $guard,
        private readonly ToolInputValidator $validator,
    ) {
    }

    /**
     * @return array{conversation: AgentConversation, assistantMessage: AgentMessage, answer: string, toolCalls: list<array{name: string, summary: string}>, usage: array{input: int, output: int}, toolResults: list<array<string, mixed>>}
     */
    public function turn(User $user, ?string $conversationId, string $message): array
    {
        // Ownership FIRST: a foreign conversation must not even reserve budget.
        $conversation = null === $conversationId
            ? new AgentConversation($user)
            : $this->ownedConversation($conversationId, $user);

        $reserve = $this->config->reserveEstimateTokens();
        $this->guard->reserveTurn($user); // throws AgentDailyTokenCapException when the cap is full
        $isNew = null === $conversationId;

        try {
            return $this->completeTurn($conversation, $user, $message, $reserve, $isNew);
        } catch (\Throwable $exception) {
            if ($exception instanceof AgentDailyTokenCapException
                || $exception instanceof ResourceNotFoundException
                || $exception instanceof ReconciledLlmFailure
            ) {
                throw $exception;
            }

            // Not yet reconciled (pre-model failure, persistence failure):
            // bill at least the conservative floor, refund the estimate rest.
            $this->guard->reconcileFailedTurn($user, $reserve);
            throw $exception;
        }
    }

    /**
     * @return array{conversation: AgentConversation, assistantMessage: AgentMessage, answer: string, toolCalls: list<array{name: string, summary: string}>, usage: array{input: int, output: int}, toolResults: list<array<string, mixed>>}
     */
    private function completeTurn(AgentConversation $conversation, User $user, string $message, int $reserve, bool $isNewConversation): array
    {
        $userMessage = new AgentMessage($conversation, AgentMessage::ROLE_USER, $message);
        $this->em->persist($userMessage);
        if ($isNewConversation) {
            $this->em->persist($conversation);
            $conversation->setTitle($this->deriveTitle($message));
        }
        $this->em->flush();

        $context = $this->buildContext($conversation);

        $toolCalls = [];
        $toolResults = [];
        $usageTotal = ['input' => 0, 'output' => 0];

        $llmMessages = [];
        $llmMessages[] = ['role' => 'system', 'content' => SystemPrompt::TEXT];
        foreach ($context as $ctxMessage) {
            $llmMessages[] = AgentMessage::ROLE_USER === $ctxMessage->getRole()
                ? MessagesContentNormalizer::userText($ctxMessage->getContent())
                : MessagesContentNormalizer::assistantText($ctxMessage->getContent());
        }
        // The JUST-flushed user message is also the newest context row -
        // appending it again would duplicate it in the model payload.
        if ($context === [] || end($context)->getId()->toRfc4122() !== $userMessage->getId()->toRfc4122()) {
            $llmMessages[] = MessagesContentNormalizer::userText($message);
        }

        $answer = '';
        $rounds = 0;

        while (true) {
            $response = $this->modelCall($llmMessages, $this->registry->definitions(), $usageTotal, $user, $reserve);

            $toolUse = null;
            $textOut = '';
            foreach ($response['content'] as $block) {
                if ('text' === ($block['type'] ?? null)) {
                    $textOut .= (string) ($block['text'] ?? '');
                } elseif ('tool_use' === ($block['type'] ?? null)) {
                    $toolUse = $block;
                }
            }

            if (null === $toolUse) {
                $answer = $textOut;
                $llmMessages[] = ['role' => 'assistant', 'content' => $response['content']];
                break;
            }

            ++$rounds;
            if ($rounds > $this->config->maxToolRounds) {
                // Round cap: force a text answer from what is known. The
                // tool call is NEVER executed beyond the budget.
                $answer = $textOut;
                $llmMessages[] = ['role' => 'assistant', 'content' => $response['content']];
                break;
            }

            $toolName = (string) ($toolUse['name'] ?? '');
            $toolUseId = (string) ($toolUse['id'] ?? '');
            if ('' === $toolUseId) {
                // Never crash the loop on a provider id gap: refuse as DATA.
                $toolUseId = 'malformed';
            }

            $llmMessages[] = ['role' => 'assistant', 'content' => $response['content']];

            if (!$this->registry->has($toolName)) {
                // Unknown tool: report back as DATA, never execute (T2.2).
                $result = ['ok' => false, 'code' => 'UNKNOWN_TOOL'];
                $toolCalls[] = ['name' => $toolName, 'summary' => 'unknown tool refused'];
            } else {
                $result = $this->runTool($toolName, \is_array($toolUse['input'] ?? null) ? $toolUse['input'] : []);
                $toolCalls[] = ['name' => $toolName, 'summary' => $this->summarize($toolName, $result)];
            }

            $result = $this->clampResult($result);
            $toolResults[] = $result;

            $this->agentLogger->info('agent.tool', [
                'user_id' => $user->getId()->toRfc4122(),
                'conversation_id' => $conversation->getId()->toRfc4122(),
                'tool' => $toolName,
                'round' => $rounds,
                'ok' => (bool) ($result['ok'] ?? false),
            ]);

            $llmMessages[] = MessagesContentNormalizer::toolResult($toolUseId, $toolName, $result);
        }

        // Assistant message + REAL usage settle commit atomically: the client
        // either gets the answer AND correct accounting, or an error with no
        // completed assistant answer (no silently-inconsistent state).
        $assistantMessage = $this->em->wrapInTransaction(
            function () use ($conversation, $answer, $toolCalls, $usageTotal, $user, $reserve): AgentMessage {
                $assistantMessage = new AgentMessage($conversation, AgentMessage::ROLE_ASSISTANT, $answer);
                $assistantMessage->setToolCalls($toolCalls);
                $assistantMessage->setUsage($usageTotal['input'], $usageTotal['output']);
                $this->em->persist($assistantMessage);
                $conversation->touch();
                $this->em->flush();

                $this->guard->settleTurn($user, $usageTotal, $reserve);

                return $assistantMessage;
            },
        );

        $this->agentLogger->info('agent.turn', [
            'user_id' => $user->getId()->toRfc4122(),
            'conversation_id' => $conversation->getId()->toRfc4122(),
            'message_id' => $assistantMessage->getId()->toRfc4122(),
            'tool_rounds' => \count($toolCalls),
            'usage_input' => $usageTotal['input'],
            'usage_output' => $usageTotal['output'],
        ]);

        return [
            'conversation' => $conversation,
            'assistantMessage' => $assistantMessage,
            'answer' => $answer,
            'toolCalls' => $toolCalls,
            'usage' => $usageTotal,
            'toolResults' => $toolResults,
        ];
    }

    /**
     * One provider call. On failure: reconcile the reservation (conservative
     * floor for what was spent, remainder refunded) and rethrow as the
     * controlled 503 exception - the raw provider reason never escapes here.
     *
     * @param list<array<string, mixed>> $llmMessages
     * @param list<array<string, mixed>> $tools
     * @param array{input: int, output: int} $usageTotal
     *
     * @return array{role: 'assistant', content: list<array<string, mixed>>, usage: array{input: int, output: int}}
     */
    private function modelCall(array $llmMessages, array $tools, array &$usageTotal, User $user, int $reserve): array
    {
        try {
            $response = $this->llm->complete($llmMessages, $tools);
        } catch (\Throwable) {
            // Reconcile ONCE here (never also in the outer catch): bill the
            // completed-provider-call usage plus the conservative floor for
            // the failed call, refund the rest of the estimate.
            $this->guard->reconcileFailedTurn($user, $reserve, $usageTotal['input'] + $usageTotal['output']);

            throw new ReconciledLlmFailure();
        }

        $usageTotal['input'] += max(0, (int) ($response['usage']['input'] ?? 0));
        $usageTotal['output'] += max(0, (int) ($response['usage']['output'] ?? 0));

        /** @var array{role: 'assistant', content: list<array<string, mixed>>, usage: array{input: int, output: int}} $response */
        return $response;
    }

    /**
     * Validates the tool input against the registry schema and ONLY THEN
     * executes. Validation refusal is DATA, not an uncaught failure.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function runTool(string $toolName, array $input): array
    {
        $tool = $this->registry->get($toolName);
        if (null === $tool) {
            return ['ok' => false, 'code' => 'UNKNOWN_TOOL'];
        }

        $errors = $this->validator->validate($tool->inputSchema(), $input);
        if ([] !== $errors) {
            return ['ok' => false, 'code' => 'VALIDATION_FAILED', 'reasons' => $errors];
        }

        return $tool->execute($input);
    }

    /**
     * Binds ONE tool_result payload so a huge machine list can never blow
     * the context budget: list payloads are truncated row-wise; any other
     * oversized payload is refused as DATA.
     *
     * @param array<string, mixed> $result
     *
     * @return array<string, mixed>
     */
    private function clampResult(array $result): array
    {
        $length = \strlen((string) json_encode($result, JSON_UNESCAPED_SLASHES));
        if ($length <= self::TOOL_RESULT_MAX_BYTES) {
            return $result;
        }

        $data = $result['data'] ?? null;
        if (\is_array($data) && array_is_list($data)) {
            while (\count($data) > 1) {
                array_pop($data);
                $candidate = $result + ['data' => $data, 'truncatedBySizeLimit' => true];
                $length = \strlen((string) json_encode($candidate, JSON_UNESCAPED_SLASHES));
                if ($length <= self::TOOL_RESULT_MAX_BYTES) {
                    return $candidate;
                }
            }
        }

        return ['ok' => true, 'data' => ['omitted' => 'result payload exceeded the size limit'], 'dataCode' => 'TOOL_RESULT_TOO_LARGE'];
    }

    /** Ownership gate: foreign or unknown conversation = standard 404 (T1.7). */
    private function ownedConversation(string $id, User $user): AgentConversation
    {
        $conversation = $this->conversations->findOwnedOrExcluded($id, $user);
        if (null === $conversation) {
            throw new ResourceNotFoundException('Conversation not found.', 'AGENT_NOT_FOUND');
        }

        return $conversation;
    }

    /**
     * @return list<AgentMessage>
     */
    private function buildContext(AgentConversation $conversation): array
    {
        return $this->messages->findContext($conversation, $this->config->contextMessages);
    }

    private function deriveTitle(string $message): string
    {
        $clamped = function_exists('mb_substr') ? mb_substr($message, 0, 80) : substr($message, 0, 80);

        return trim($clamped);
    }

    /**
     * @param array<string, mixed> $result
     */
    private function summarize(string $toolName, array $result): string
    {
        $ok = (bool) ($result['ok'] ?? false);
        if (!$ok) {
            return $toolName.': '.(string) ($result['code'] ?? 'error');
        }

        $data = $result['data'] ?? null;
        if (\is_array($data)) {
            return $toolName.': '.\count($data).' row(s)';
        }

        return $toolName.': ok';
    }
}
