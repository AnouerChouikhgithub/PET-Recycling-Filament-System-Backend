<?php

declare(strict_types=1);

namespace App\Service\Agent;

use App\Api\Exception\AgentDailyTokenCapException;
use App\Api\Exception\AgentLlmNotAttemptedException;
use App\Api\Exception\AgentLlmUnavailableException;
use App\Api\Exception\ReconciledLlmFailure;
use App\Api\Exception\ResourceNotFoundException;
use App\Entity\AgentConversation;
use App\Entity\AgentMessage;
use App\Entity\User;
use App\Repository\AgentConversationRepository;
use App\Repository\AgentMessageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

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
 * Provider/turn failures: the reservation is reconciled and a controlled
 * AgentLlmUnavailableException (503) / cap exception (429) is rethrown -
 * no client ever sees provider details and no turn is double-billed.
 * Accounting is honest about what actually happened:
 *
 *   - a failure before the first model call (persistence/context) and a
 *     pre-flight provider refusal (nothing was sent) refund the estimate
 *     completely - no floor (BUGS-TO-FIX F4);
 *   - a provider call that failed after a request went out keeps the
 *     conservative MISSING_USAGE_ESTIMATE floor (the provider may have
 *     generated tokens);
 *   - every failure still bills whatever COMPLETED calls really reported.
 *
 * Logging: agent channel, METADATA ONLY (ids, tool names, counts) - never
 * message content or tool payloads.
 */
final class AgentOrchestrator
{
    /** Hard byte bound for ONE tool_result payload. */
    public const TOOL_RESULT_MAX_BYTES = 8000;

    private const FALLBACK_EN = "I couldn't complete that lookup. Please try again.";

    private const FALLBACK_FR = "Je n'ai pas pu terminer cette recherche. Veuillez réessayer.";

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
        $reservedAt = $this->guard->reserveTurn($user); // throws AgentDailyTokenCapException when the cap is full
        $isNew = null === $conversationId;

        // Provider-reported usage of every COMPLETED call in this turn. The
        // failure paths below settle with it, so a turn that never reached
        // the provider is refunded in full instead of being billed the
        // conservative floor (BUGS-TO-FIX F4).
        $spent = ['input' => 0, 'output' => 0];

        try {
            return $this->completeTurn($conversation, $user, $message, $reserve, $reservedAt, $isNew, $spent);
        } catch (\Throwable $exception) {
            if ($exception instanceof AgentDailyTokenCapException
                || $exception instanceof ResourceNotFoundException
                || $exception instanceof ReconciledLlmFailure
            ) {
                throw $exception;
            }

            // Not yet reconciled: a pre-model failure (persistence, context) or
            // a post-model persistence failure. Bill exactly what the provider
            // reported - 0 when nothing was sent - and refund the estimate
            // rest. No floor here: the turn produced nothing to charge for.
            $this->guard->refundFailedTurn($user, $reserve, $spent['input'] + $spent['output'], $reservedAt);
            throw $exception;
        }
    }

    /**
     * @param array{input: int, output: int} $usageTotal accumulated by-ref so the
     *                                                  caller can settle a failed
     *                                                  turn with the REAL spend
     *
     * @return array{conversation: AgentConversation, assistantMessage: AgentMessage, answer: string, toolCalls: list<array{name: string, summary: string}>, usage: array{input: int, output: int}, toolResults: list<array<string, mixed>>}
     */
    private function completeTurn(AgentConversation $conversation, User $user, string $message, int $reserve, \DateTimeImmutable $reservedAt, bool $isNewConversation, array &$usageTotal): array
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
            $response = $this->modelCall($llmMessages, $this->registry->definitions(), $usageTotal, $user, $reserve, $reservedAt);

            $toolUses = [];
            $textOut = '';
            foreach ($response['content'] as $block) {
                if ('text' === ($block['type'] ?? null)) {
                    $textOut .= (string) ($block['text'] ?? '');
                } elseif ('tool_use' === ($block['type'] ?? null)) {
                    $toolUses[] = $block;
                }
            }

            if ([] === $toolUses) {
                $answer = $textOut;
                $llmMessages[] = ['role' => 'assistant', 'content' => $response['content']];
                break;
            }

            $llmMessages[] = ['role' => 'assistant', 'content' => $response['content']];
            $resultBlocks = [];
            $toolRoundLimitReached = false;

            // One model response is one tool round, regardless of how many
            // tools it asks for. Every call in the same response shares the
            // same budget decision.
            ++$rounds;

            foreach ($toolUses as $toolUse) {
                $toolName = (string) ($toolUse['name'] ?? '');
                $toolUseId = (string) ($toolUse['id'] ?? '');
                if ('' === $toolUseId) {
                    // Never crash the loop on a provider id gap: refuse as DATA.
                    $toolUseId = 'malformed';
                }

                if ($rounds > $this->config->maxToolRounds) {
                    // Every provider call still gets a result, but calls beyond
                    // the budget are never executed.
                    $toolRoundLimitReached = true;
                    $result = ['ok' => false, 'code' => 'TOOL_ROUND_LIMIT'];
                    $summary = 'tool round limit reached';
                } elseif (!$this->registry->has($toolName)) {
                    // Unknown tool: report back as DATA, never execute (T2.2).
                    $result = ['ok' => false, 'code' => 'UNKNOWN_TOOL'];
                    $summary = 'unknown tool refused';
                } else {
                    $result = $this->runTool($toolName, \is_array($toolUse['input'] ?? null) ? $toolUse['input'] : []);
                    $summary = $this->summarize($toolName, $result);
                }

                $result = $this->clampResult($result);
                $toolCalls[] = ['name' => $toolName, 'summary' => $summary];
                $toolResults[] = $result;
                $resultBlocks[] = ['id' => $toolUseId, 'name' => $toolName, 'result' => $result];

                $this->agentLogger->info('agent.tool', [
                    'user_id' => $user->getId()->toRfc4122(),
                    'conversation_id' => $conversation->getId()->toRfc4122(),
                    'tool' => $toolName,
                    'round' => $rounds,
                    'ok' => (bool) ($result['ok'] ?? false),
                ]);
            }

            $llmMessages[] = MessagesContentNormalizer::toolResults($resultBlocks);
            if ($toolRoundLimitReached) {
                $answer = $textOut;
                break;
            }
        }

        if ('' === $answer) {
            $answer = $this->fallbackFor($message);
        }

        // Assistant message + REAL usage settle commit atomically: the client
        // either gets the answer AND correct accounting, or an error with no
        // completed assistant answer (no silently-inconsistent state).
        $assistantMessage = $this->em->wrapInTransaction(
            function () use ($conversation, $answer, $toolCalls, $usageTotal, $user, $reserve, $reservedAt): AgentMessage {
                $assistantMessage = new AgentMessage($conversation, AgentMessage::ROLE_ASSISTANT, $answer);
                $assistantMessage->setToolCalls($toolCalls);
                $assistantMessage->setUsage($usageTotal['input'], $usageTotal['output']);
                $this->em->persist($assistantMessage);
                $conversation->touch();
                $this->em->flush();

                $this->guard->settleTurn($user, $usageTotal, $reserve, $reservedAt);

                return $assistantMessage;
            },
        );

        $this->agentLogger->info('agent.turn', [
            'user_id' => $user->getId()->toRfc4122(),
            'conversation_id' => $conversation->getId()->toRfc4122(),
            'message_id' => $assistantMessage->getId()->toRfc4122(),
            'tool_calls' => \count($toolCalls),
            'tool_rounds' => $rounds,
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
     * One provider call. On failure: settle the reservation and rethrow as
     * the controlled 503 exception - the raw provider reason never escapes
     * here. A "not attempted" failure (nothing was sent) refunds the turn;
     * any other provider failure keeps the conservative floor because the
     * provider may already have generated tokens (BUGS-TO-FIX F4).
     *
     * @param list<array<string, mixed>> $llmMessages
     * @param list<array<string, mixed>> $tools
     * @param array{input: int, output: int} $usageTotal
     *
     * @return array{role: 'assistant', content: list<array<string, mixed>>, usage: array{input: int, output: int}}
     */
    private function modelCall(array $llmMessages, array $tools, array &$usageTotal, User $user, int $reserve, \DateTimeImmutable $reservedAt): array
    {
        try {
            $response = $this->llm->complete($llmMessages, $tools);
        } catch (\Throwable $failure) {
            // Reconcile ONCE here (never also in the outer catch): settle the
            // completed-provider-call usage plus, when a request actually went
            // out, the conservative floor for the failed call - and refund the
            // rest of the estimate.
            $actual = $usageTotal['input'] + $usageTotal['output'];
            if ($failure instanceof AgentLlmNotAttemptedException) {
                $this->guard->refundFailedTurn($user, $reserve, $actual, $reservedAt);
            } else {
                $this->guard->reconcileFailedTurn($user, $reserve, $actual, $reservedAt);
            }

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

        try {
            return $tool->execute($input);
        } catch (\Throwable $exception) {
            $this->agentLogger->warning('agent.tool_failed', [
                'tool' => $toolName,
                'exception_class' => $exception::class,
            ]);

            return ['ok' => false, 'code' => 'TOOL_ERROR'];
        }
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
                // Array union preserves the original `data` key, which made
                // every retry re-measure the untruncated payload and silently
                // fall through to TOOL_RESULT_TOO_LARGE. Replace the field.
                $candidate = array_replace($result, ['data' => $data, 'truncatedBySizeLimit' => true]);
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
     * The empty-answer fallback follows the language of the user's message:
     * the assistant supports French and English, and the model may fail
     * before it can answer in the requested language.
     */
    private function fallbackFor(string $message): string
    {
        // Accents are decisive. Without them a single short word is too weak
        // ("comment" is also English), so require either one strong marker or
        // two distinct common French words before claiming the language.
        if (preg_match('/[àâäçéèêëîïôöùûüÿœæ]/iu', $message)) {
            return self::FALLBACK_FR;
        }

        if (preg_match('/\b(?:je|nous|vous|pourquoi|bonjour|merci|quel|quelle|sont|avec)\b/iu', $message)) {
            return self::FALLBACK_FR;
        }

        preg_match_all('/\b(?:mon|ma|mes|le|la|les|un|une|des|du|est|pas|pour|dans)\b/iu', $message, $matches);
        $distinct = \count(array_unique(array_map('mb_strtolower', $matches[0])));

        return $distinct >= 2 ? self::FALLBACK_FR : self::FALLBACK_EN;
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
        if (\is_array($data) && array_is_list($data)) {
            return $toolName.': '.\count($data).' row(s)';
        }

        return $toolName.': ok';
    }
}
