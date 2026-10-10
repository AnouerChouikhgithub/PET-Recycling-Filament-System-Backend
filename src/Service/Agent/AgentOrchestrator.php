<?php

declare(strict_types=1);

namespace App\Service\Agent;

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
 *   guards (rate limit + token cap, keyed by USER)
 *     -> load/validate conversation
 *     -> build context (last N messages, oldest first)
 *     -> LLM loop (max N tool rounds; tool results ONLY in tool_result
 *        blocks, JSON-encoded; unknown tool name -> error result, never
 *        executed - T2.2/T4.2)
 *     -> persist user + assistant messages
 *
 * Logging: agent channel, METADATA ONLY (ids, tool names, counts) - never
 * message content or tool payloads (docs/ai-agent-test-plan.md T7.3).
 */
final class AgentOrchestrator
{
    public function __construct(
        private readonly LlmClientInterface $llm,
        private readonly ToolRegistry $registry,
        private readonly AgentConversationRepository $conversations,
        private readonly AgentMessageRepository $messages,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $agentLogger,
        private readonly AgentConfig $config,
    ) {
    }

    /**
     * @return array{conversation: AgentConversation, answer: string, toolCalls: list<array{name: string, summary: string}>, usage: array{input: int, output: int}, toolResults: list<array<string, mixed>>}
     */
    public function turn(User $user, ?string $conversationId, string $message): array
    {
        $conversation = null === $conversationId
            ? new AgentConversation($user)
            : $this->ownedConversation($conversationId, $user);

        $userMessage = new AgentMessage($conversation, AgentMessage::ROLE_USER, $message);
        $this->em->persist($userMessage);
        if (null === $conversationId) {
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
            $response = $this->llm->complete($llmMessages, $this->registry->definitions());
            $usageTotal['input'] += (int) ($response['usage']['input'] ?? 0);
            $usageTotal['output'] += (int) ($response['usage']['output'] ?? 0);

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
            $toolInput = \is_array($toolUse['input'] ?? null) ? $toolUse['input'] : [];
            $llmMessages[] = ['role' => 'assistant', 'content' => $response['content']];

            if (!$this->registry->has($toolName)) {
                // Unknown tool: report back as DATA, never execute (T2.2).
                $result = ['ok' => false, 'code' => 'UNKNOWN_TOOL'];
                $toolCalls[] = ['name' => $toolName, 'summary' => 'unknown tool refused'];
            } else {
                $tool = $this->registry->get($toolName);
                $result = $tool->execute($toolInput);
                $toolCalls[] = ['name' => $toolName, 'summary' => $this->summarize($toolName, $result)];
            }

            $toolResults[] = $result;

            $this->agentLogger->info('agent.tool', [
                'user_id' => $user->getId()->toRfc4122(),
                'conversation_id' => $conversation->getId()->toRfc4122(),
                'tool' => $toolName,
                'round' => $rounds,
                'ok' => (bool) ($result['ok'] ?? false),
            ]);

            $toolUseId = (string) ($toolUse['id'] ?? '');
            $llmMessages[] = MessagesContentNormalizer::toolResult($toolUseId, $toolName, $result);
        }

        $assistantMessage = new AgentMessage($conversation, AgentMessage::ROLE_ASSISTANT, $answer);
        $assistantMessage->setToolCalls($toolCalls);
        $assistantMessage->setUsage($usageTotal['input'], $usageTotal['output']);
        $this->em->persist($assistantMessage);
        $conversation->touch();
        $this->em->flush();

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
            'answer' => $answer,
            'toolCalls' => $toolCalls,
            'usage' => $usageTotal,
            'toolResults' => $toolResults,
        ];
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
