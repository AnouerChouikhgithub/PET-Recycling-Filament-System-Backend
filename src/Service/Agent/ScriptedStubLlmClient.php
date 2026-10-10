<?php

declare(strict_types=1);

namespace App\Service\Agent;

/**
 * SCRIPTED STUB - the only LlmClientInterface implementation in phase 1.
 *
 * Two modes:
 *   1. echo (default): summarize the last tool_result / user turn as text.
 *      Deterministic, no network, no key. Better than a canned string
 *      because tests can assert the tool DATA reached the model layer.
 *   2. scripted: tests push turns via addResponse(); each call pops one.
 *      A turn may be a text reply or a tool_use block - this is how the
 *      tool loop, unknown-tool errors and injection tests are driven.
 *
 * It counts every complete() call (test T3 asserts limits are checked
 * BEFORE any call) and never reads secrets of any kind.
 */
final class ScriptedStubLlmClient implements LlmClientInterface
{
    /** @var list<array<string, mixed>> */
    private array $script = [];

    /** @var list<array<string, mixed>> */
    public array $receivedMessages = [];

    public int $calls = 0;

    /**
     * Push a scripted response (a full content-blocks turn).
     *
     * @param list<array<string, mixed>> $contentBlocks
     */
    public function addResponse(array $contentBlocks): void
    {
        $this->script[] = ['content' => $contentBlocks];
    }

    public function reset(): void
    {
        $this->script = [];
        $this->receivedMessages = [];
        $this->calls = 0;
    }

    public function complete(array $messages, array $tools): array
    {
        ++$this->calls;
        $this->receivedMessages = $messages;

        if ([] !== $this->script) {
            return array_shift($this->script) + ['role' => 'assistant', 'usage' => ['input' => 10, 'output' => 10]];
        }

        return [
            'role' => 'assistant',
            'content' => [
                ['type' => 'text', 'text' => $this->echoReply($messages)],
            ],
            'usage' => ['input' => 10, 'output' => 10],
        ];
    }

    /**
     * Echo mode: produce a short, faithful summary of what the conversation
     * last contained (user text or the newest tool_result data).
     *
     * @param list<array<string, mixed>> $messages
     */
    private function echoReply(array $messages): string
    {
        for ($i = \count($messages) - 1; $i >= 0; --$i) {
            $role = $messages[$i]['role'];
            $blocks = $messages[$i]['content'];

            if ('tool_result' === $role && \is_array($blocks)) {
                foreach ($blocks as $block) {
                    if (\is_array($block) && 'tool_result' === ($block['type'] ?? null)) {
                        $json = (string) json_encode($block['content'] ?? null, JSON_UNESCAPED_SLASHES);

                        return 'TOOL_RESULT '.$json;
                    }
                }
            }

            if ('user' === $role && \is_array($blocks)) {
                foreach ($blocks as $block) {
                    if (\is_array($block) && 'text' === ($block['type'] ?? null)) {
                        return 'ECHO '.(string) ($block['text'] ?? '');
                    }
                }
            }
        }

        return 'OK';
    }
}
