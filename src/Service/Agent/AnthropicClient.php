<?php

declare(strict_types=1);

namespace App\Service\Agent;

use App\Api\Exception\AgentLlmUnavailableException;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * REAL Anthropic Messages API client (session 2). The ONLY class that sees
 * the API key and the network; bound under App\Service\Agent\LlmClientInterface
 * in the default environment. The test environment rebinds that alias to the
 * scripted stub (config/packages/test/services.yaml), so NO automated test
 * ever makes a live request.
 *
 * Wire contract (Anthropic Messages API, POST /v1/messages):
 *   headers : x-api-key, anthropic-version: 2023-06-01, content-type json
 *   body    : model, max_tokens, system (top-level string), messages (roles
 *             user|assistant; tool_result blocks ride in the user message
 *             directly after the assistant tool-use turn), tools (name,
 *             description, input_schema).
 *   response: content[] blocks (text / tool_use), stop_reason, usage
 *             (input_tokens, output_tokens; additional fields tolerated).
 *
 * Orchestration message shape (internal, NOT wire): roles system, user,
 * assistant, tool_result - wireMessages() converts it. Tool RESULTS always
 * travel JSON-encoded inside a tool_result block, never spliced into user or
 * assistant text (docs/ai-agent-risks.md R1); anything the model needs from a
 * tool goes back as DATA on the next model turn only.
 *
 * Failure handling: ONE immediate retry, only for transport failures and
 * provider 5xx (timeouts/overload). Never retried: any 4xx (invalid request,
 * auth, billing, provider 429) - state and billing stay bounded, no retry
 * storm. EVERY failure maps to the single controlled
 * AgentLlmUnavailableException; provider status/type go to the metadata-only
 * agent log channel, never to the client, never into exception text, and the
 * key is never logged or echoed anywhere.
 */
final class AnthropicClient implements LlmClientInterface
{
    /** Required on every request (stable version string per provider docs). */
    private const API_VERSION = '2023-06-01';

    private const ENDPOINT = '/v1/messages';

    /** Upper bound for provider error-type metadata. */
    private const MAX_META_CHARS = 64;

    public function __construct(
        private readonly AgentConfig $config,
        private readonly HttpClientInterface $httpClient,
        /** Injected from the environment in services.yaml; never logged, never returned. */
        private readonly string $apiKey,
        private readonly LoggerInterface $agentLogger,
    ) {
    }

    public function complete(array $messages, array $tools): array
    {
        if ('' === $this->apiKey) {
            // Controlled credential failure: no request, no retry, no detail.
            throw new AgentLlmUnavailableException('The assistant service is not configured.');
        }

        try {
            $response = $this->request($messages, $tools);
        } catch (ProviderRetryableException $first) {
            $this->logFailure('transport', $first->status, 1);
            // ONE immediate retry for transient conditions; 4xx never lands here.
            try {
                $response = $this->request($messages, $tools);
            } catch (ProviderRetryableException $second) {
                $this->logFailure('transport', $second->status, 2);

                throw new AgentLlmUnavailableException();
            }
        }

        return $this->parse($response);
    }

    /**
     * @param list<array<string, mixed>> $messages
     * @param list<array<string, mixed>> $tools
     */
    private function request(array $messages, array $tools): ResponseInterface
    {
        // connect failure / idle stream: bounded by 'timeout'; the WHOLE call
        // (connect + protocol + body) is bounded by 'max_duration' so a hung
        // connection can never run past the budget.
        $totalSeconds = max(2, $this->config->connectTimeoutSec + $this->config->requestTimeoutSec);

        try {
            $response = $this->httpClient->request('POST', $this->endpointUrl(), [
                'headers' => [
                    'x-api-key' => $this->apiKey,
                    'anthropic-version' => self::API_VERSION,
                    'content-type' => 'application/json',
                    'accept' => 'application/json',
                ],
                'body' => $this->body($messages, $tools),
                'timeout' => $this->config->requestTimeoutSec,
                'max_duration' => $totalSeconds,
            ]);
            $status = $response->getStatusCode();
        } catch (TransportExceptionInterface) {
            // connect failure, DNS, timeout, aborted stream -> retryable
            throw new ProviderRetryableException(null);
        }

        if ($status >= 500) {
            throw new ProviderRetryableException($status);
        }
        if ($status >= 400) {
            // invalid request / auth / billing / provider 429: terminal,
            // never retried (metadata only - the body is never relayed).
            $this->logFailure('provider', $status, 1, $this->errorTypeOf($response));

            throw new AgentLlmUnavailableException();
        }

        return $response;
    }

    /**
     * Parses the provider response into the internal turn shape:
     * role, content blocks (text / tool_use), stop_reason, usage{input,output}.
     *
     * @return array{role: 'assistant', content: list<array<string, mixed>>, stop_reason: string|null, usage: array{input: int, output: int}}
     */
    private function parse(ResponseInterface $response): array
    {
        try {
            $raw = $response->getContent();
        } catch (TransportExceptionInterface) {
            // Headers came back OK but the body died: likely billed server-side.
            // Deliberately NOT retried - a second call risks double billing.
            $this->logFailure('body', null, 1);

            throw new AgentLlmUnavailableException();
        }

        $decoded = json_decode((string) $raw, true);
        if (!\is_array($decoded) || 'message' !== ($decoded['type'] ?? null)) {
            $this->logFailure('malformed-response', null, 1);

            throw new AgentLlmUnavailableException();
        }

        $blocks = [];
        foreach (\is_array($decoded['content'] ?? null) ? $decoded['content'] : [] as $block) {
            if (!\is_array($block)) {
                continue;
            }
            $type = $block['type'] ?? null;
            if ('text' === $type) {
                $blocks[] = ['type' => 'text', 'text' => (string) ($block['text'] ?? '')];
            } elseif ('tool_use' === $type) {
                $id = (string) ($block['id'] ?? '');
                $name = (string) ($block['name'] ?? '');
                $input = \is_array($block['input'] ?? null) ? $block['input'] : [];
                if ('' === $id || '' === $name) {
                    // Malformed tool call: refusal WITHOUT execution.
                    $this->logFailure('malformed-tool-use', null, 1);

                    throw new AgentLlmUnavailableException();
                }
                $blocks[] = ['type' => 'tool_use', 'id' => $id, 'name' => $name, 'input' => $input];
            }
            // Any other/unknown block type is tolerated but ignored (the
            // provider may add fields; ignoring is the safe default).
        }

        if ([] === $blocks) {
            $this->logFailure('empty-response', null, 1);

            throw new AgentLlmUnavailableException();
        }

        $usage = \is_array($decoded['usage'] ?? null) ? $decoded['usage'] : [];
        $stopReason = $decoded['stop_reason'] ?? null;

        return [
            'role' => 'assistant',
            'content' => $blocks,
            'stop_reason' => \is_string($stopReason) ? $stopReason : null,
            'usage' => [
                'input' => max(0, (int) ($usage['input_tokens'] ?? 0)),
                'output' => max(0, (int) ($usage['output_tokens'] ?? 0)),
            ],
        ];
    }

    /**
     * The JSON request body. tool definitions pass through unchanged
     * (name/description/input_schema already match the provider schema).
     *
     * @param list<array<string, mixed>> $messages
     * @param list<array<string, mixed>> $tools
     */
    private function body(array $messages, array $tools): string
    {
        $payload = [
            'model' => '' !== $this->config->model ? $this->config->model : AgentConfig::DEFAULT_MODEL,
            'max_tokens' => max(1, $this->config->maxTokens),
            'messages' => $this->wireMessages($messages),
        ];

        $system = $this->systemFrom($messages);
        if ('' !== $system) {
            $payload['system'] = $system;
        }
        if ([] !== $tools) {
            $payload['tools'] = $tools;
        }

        return (string) json_encode($payload, JSON_UNESCAPED_SLASHES);
    }

    /**
     * System text becomes the top-level `system` parameter (the provider has
     * NO system message role).
     *
     * @param list<array<string, mixed>> $messages
     */
    private function systemFrom(array $messages): string
    {
        $parts = [];
        foreach ($messages as $message) {
            if ('system' !== ($message['role'] ?? null)) {
                continue;
            }
            $rawContent = $message['content'] ?? '';
            $text = \is_string($rawContent) ? $rawContent : MessagesContentNormalizer::textOf(['content' => \is_array($rawContent) ? $rawContent : []]);
            if ('' !== $text) {
                $parts[] = $text;
            }
        }

        return implode("\n\n", $parts);
    }

    /**
     * Internal roles -> provider wire message list:
     *   - 'system' is dropped (handled top-level);
     *   - 'tool_result' becomes a user message whose content is the
     *     tool_result block (consecutive result messages merge into ONE
     *     user turn);
     *   - 'user'/'assistant' pass through as block arrays.
     *
     * @param list<array<string, mixed>> $messages
     *
     * @return list<array{role: 'user'|'assistant', content: list<array<string, mixed>>}>
     */
    private function wireMessages(array $messages): array
    {
        $wire = [];
        foreach ($messages as $message) {
            $role = (string) ($message['role'] ?? 'user');
            $content = $message['content'] ?? '';

            if ('system' === $role) {
                continue;
            }

            if ('tool_result' === $role) {
                $wireBlock = $this->wireToolResult($content);
                $last = \count($wire) - 1;
                if ([] !== $wire && 'user' === $wire[$last]['role']) {
                    $wire[$last]['content'][] = $wireBlock;
                } else {
                    $wire[] = ['role' => 'user', 'content' => [$wireBlock]];
                }

                continue;
            }

            $wire[] = [
                'role' => 'user' === $role ? 'user' : 'assistant',
                'content' => $this->wireBlocks($content),
            ];
        }

        return $wire;
    }

    /**
     * @param mixed $content the internal tool_result-role message content
     *
     * @return array<string, mixed>
     */
    private function wireToolResult(mixed $content): array
    {
        $block = \is_array($content) ? ($content[0] ?? null) : null;
        $result = \is_array($block) && \is_array($block['content'] ?? null) ? $block['content'] : [];

        // The payload is JSON-ENCODED string data inside the block: it stays
        // data for the provider, is never executed, and keeps any embedded
        // quotes/newlines from being interpreted as structure.
        return [
            'type' => 'tool_result',
            'tool_use_id' => (string) (\is_array($block) ? ($block['tool_use_id'] ?? '') : ''),
            'content' => (string) json_encode($result, JSON_UNESCAPED_SLASHES),
            'is_error' => !(bool) ($result['ok'] ?? false),
        ];
    }

    /**
     * @param mixed $content string (plain) or a list of content blocks
     *
     * @return list<array<string, mixed>>
     */
    private function wireBlocks(mixed $content): array
    {
        if (\is_string($content)) {
            return [['type' => 'text', 'text' => $content]];
        }
        if (\is_array($content)) {
            return $content;
        }

        return [['type' => 'text', 'text' => '']];
    }

    private function endpointUrl(): string
    {
        $base = '' !== $this->config->baseUrl ? $this->config->baseUrl : AgentConfig::DEFAULT_BASE_URL;

        return rtrim($base, '/').self::ENDPOINT;
    }

    private function logFailure(string $phase, ?int $status, int $attempt, ?string $providerErrorType = null): void
    {
        // METADATA ONLY: phase, status, attempt, bounded error type. Never the
        // request/response body, never the key (docs/ai-agent-risks.md R5/R6).
        $this->agentLogger->warning('agent.llm.failure', [
            'phase' => $phase,
            'status' => $status,
            'attempt' => $attempt,
            'provider_error_type' => $providerErrorType,
        ]);
    }

    private function errorTypeOf(ResponseInterface $response): ?string
    {
        try {
            $raw = $response->getContent(false);
        } catch (TransportExceptionInterface) {
            return null;
        }

        $decoded = json_decode((string) $raw, true);
        $type = \is_array($decoded) && \is_array($decoded['error'] ?? null) ? ($decoded['error']['type'] ?? null) : null;
        if (!\is_string($type) || '' === $type) {
            return null;
        }

        return mb_substr($type, 0, self::MAX_META_CHARS);
    }
}

/**
 * Internal carrier for conditions the policy allows ONE immediate retry for:
 * transport failures (no provider status) and provider 5xx responses.
 */
final class ProviderRetryableException extends \RuntimeException
{
    public function __construct(
        public readonly ?int $status,
    ) {
        parent::__construct('provider call retryable');
    }
}
