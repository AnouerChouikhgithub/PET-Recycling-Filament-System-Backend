<?php

declare(strict_types=1);

namespace App\Tests\Agent;

use App\Api\Exception\AgentLlmUnavailableException;
use App\Service\Agent\AgentConfig;
use App\Service\Agent\AnthropicClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * Provider contract tests for the REAL Anthropic client. Every request
 * answers from a MockHttpClient / test double - NO LIVE NETWORK, no real
 * key ("sk-test-fake-key-never-real" is a fake placeholder; it must never
 * reach any log).
 */
final class AnthropicClientTest extends TestCase
{
    private const TEST_KEY = 'sk-test-fake-key-never-real';

    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    private array $requests = [];

    // ------------------------------------------------------- wire contract

    public function testRequestCarriesRequiredHeadersEndpointAndEnvConfig(): void
    {
        $client = $this->client([$this->textMessage('Hello!')]);

        $client->complete($this->messageTurn(), $this->tools());

        self::assertCount(1, $this->requests);
        $request = $this->requests[0];
        self::assertSame('POST', $request['method']);
        self::assertSame('https://api.anthropic.com/v1/messages', $request['url']);

        $headers = $this->headerList($request);
        self::assertContains('x-api-key: '.self::TEST_KEY, $headers, 'the key rides only in the header');
        self::assertContains('anthropic-version: 2023-06-01', $headers);
        self::assertContains('content-type: application/json', $headers);

        $body = $this->bodyOf($request);
        self::assertSame('claude-sonnet-4-5', $body['model']);
        self::assertSame(512, $body['max_tokens'], 'bounded max_tokens comes from env config');
    }

    public function testInternalMessageShapeIsTranslatedToWireFormat(): void
    {
        $client = $this->client([$this->textMessage('ok')]);

        $messages = [
            ['role' => 'system', 'content' => 'SYSTEM RULES'],
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'what is up?']]],
            ['role' => 'assistant', 'content' => [['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'list_my_machines', 'input' => []]]],
            ['role' => 'tool_result', 'content' => [
                ['type' => 'tool_result', 'tool_use_id' => 'toolu_1', 'name' => 'list_my_machines',
                 'content' => ['ok' => true, 'data' => [['id' => 'm1']]]],
            ]],
        ];
        $client->complete($messages, $this->tools());

        $body = $this->bodyOf($this->requests[0]);

        self::assertSame('SYSTEM RULES', $body['system'], 'system becomes the top-level parameter, never a message role');
        self::assertNotContains('system', array_column($body['messages'], 'role'), 'no system role among messages');

        // Tool result rides in the FOLLOWING user turn, content JSON-ENCODED.
        $resultTurn = array_slice($body['messages'], -1)[0];
        self::assertSame('user', $resultTurn['role']);
        $block = $resultTurn['content'][0];
        self::assertSame('tool_result', $block['type']);
        self::assertSame('toolu_1', $block['tool_use_id']);
        self::assertFalse($block['is_error']);
        self::assertSame(
            json_encode(['ok' => true, 'data' => [['id' => 'm1']]], JSON_UNESCAPED_SLASHES),
            $block['content'],
            'untrusted tool DATA travels JSON-encoded inside the tool_result block',
        );

        // Tool definitions pass through unchanged.
        self::assertSame('list_my_machines', $body['tools'][0]['name']);
        self::assertSame('object', $body['tools'][0]['input_schema']['type']);
    }

    public function testFailedToolResultIsMarkedAsErrorData(): void
    {
        $client = $this->client([$this->textMessage('ok')]);

        $messages = [
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'x']]],
            ['role' => 'assistant', 'content' => [['type' => 'tool_use', 'id' => 'toolu_9', 'name' => 'get_audit_log', 'input' => []]]],
            ['role' => 'tool_result', 'content' => [
                ['type' => 'tool_result', 'tool_use_id' => 'toolu_9', 'name' => 'get_audit_log',
                 'content' => ['ok' => false, 'code' => 'MACHINE_NOT_FOUND']],
            ]],
        ];
        $client->complete($messages, $this->tools());

        $block = array_slice($this->bodyOf($this->requests[0])['messages'], -1)[0]['content'][0];
        self::assertTrue($block['is_error'], 'refused tool results are is_error DATA for the provider');
    }

    // ------------------------------------------------------------ parsing

    public function testPlainTextResponseIsParsedWithReportedUsage(): void
    {
        $client = $this->client([$this->textMessage('Hello!', usage: [301, 42])]);

        $response = $client->complete($this->messageTurn(), $this->tools());

        self::assertSame([['type' => 'text', 'text' => 'Hello!']], $response['content']);
        self::assertSame(['input' => 301, 'output' => 42], $response['usage']);
        self::assertSame('end_turn', $response['stop_reason']);
    }

    public function testToolUseResponseIsParsedForTheLoop(): void
    {
        $client = $this->client([$this->toolUseMessage('toolu_1', 'get_latest_telemetry', ['machineId' => 'm-1'])]);

        $response = $client->complete($this->messageTurn(), $this->tools());

        self::assertSame([
            ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'get_latest_telemetry', 'input' => ['machineId' => 'm-1']],
        ], $response['content']);
    }

    public function testUnknownContentBlockTypesAreToleratedAndIgnored(): void
    {
        $client = $this->client([new MockResponse((string) json_encode([
            'id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'model' => 'm',
            'content' => [['type' => 'thinking', 'text' => 'internal'], ['type' => 'text', 'text' => 'fine']],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ]))]);

        $response = $client->complete($this->messageTurn(), $this->tools());

        self::assertSame([['type' => 'text', 'text' => 'fine']], $response['content']);
    }

    // ------------------------------------------------- failure + retry map

    public function testProviderFiveHundredIsRetriedOnceThenControlled503(): void
    {
        $client = $this->client([
            new MockResponse('', ['http_code' => 500]),
            new MockResponse('', ['http_code' => 500]),
        ]);

        try {
            $client->complete($this->messageTurn(), $this->tools());
            self::fail('expected AgentLlmUnavailableException');
        } catch (AgentLlmUnavailableException) {
        }

        self::assertCount(2, $this->requests, 'exactly ONE retry on provider 5xx, then give up');
    }

    public function testProviderFiveHundredSucceedsOnTheSingleRetry(): void
    {
        $client = $this->client([
            new MockResponse('', ['http_code' => 529]),
            $this->textMessage('recovered'),
        ]);

        $response = $client->complete($this->messageTurn(), $this->tools());

        self::assertSame('recovered', $response['content'][0]['text']);
        self::assertCount(2, $this->requests);
    }

    public function testProviderRateLimitIsNeverRetried(): void
    {
        $client = $this->client([$this->errorResponse(429, 'rate_limit_error')]);

        try {
            $client->complete($this->messageTurn(), $this->tools());
            self::fail('expected AgentLlmUnavailableException');
        } catch (AgentLlmUnavailableException) {
        }

        self::assertCount(1, $this->requests, '4xx (incl. provider rate limit) must never be retried');
    }

    public function testProviderBadRequestIsNeverRetried(): void
    {
        $client = $this->client([$this->errorResponse(400, 'invalid_request_error')]);

        try {
            $client->complete($this->messageTurn(), $this->tools());
            self::fail('expected AgentLlmUnavailableException');
        } catch (AgentLlmUnavailableException) {
        }

        self::assertCount(1, $this->requests);
    }

    public function testConnectionTimeoutIsRetriedOnceThenControlled503(): void
    {
        $record = [];
        $httpClient = new ThrowingHttpClient($record, 2);
        $client = $this->clientWithKey(self::TEST_KEY, $httpClient);


        try {
            $client->complete($this->messageTurn(), $this->tools());
            self::fail('expected AgentLlmUnavailableException');
        } catch (AgentLlmUnavailableException) {
        }

        self::assertCount(2, $record, 'ONE retry after a transport timeout, then controlled failure');
        self::assertSame(2, $httpClient->count());
    }

    public function testMalformedResponseBodyIsControlled503WithoutRetry(): void
    {
        $client = $this->client([new MockResponse('not-json')]);

        try {
            $client->complete($this->messageTurn(), $this->tools());
            self::fail('expected AgentLlmUnavailableException');
        } catch (AgentLlmUnavailableException) {
        }

        self::assertCount(1, $this->requests);
    }

    public function testEmptyContentBlockListIsControlled503(): void
    {
        $client = $this->client([new MockResponse((string) json_encode([
            'id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'model' => 'm',
            'content' => [], 'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ]))]);

        $this->expectException(AgentLlmUnavailableException::class);
        $client->complete($this->messageTurn(), $this->tools());
    }

    public function testMalformedToolUseWithoutIdIsRefusedWithoutExecution(): void
    {
        $client = $this->client([new MockResponse((string) json_encode([
            'id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'model' => 'm',
            'content' => [['type' => 'tool_use', 'id' => '', 'name' => 'list_my_machines', 'input' => []]],
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ]))]);

        $this->expectException(AgentLlmUnavailableException::class);
        $client->complete($this->messageTurn(), $this->tools());
    }

    public function testResponseWithoutUsageReportsZeroAndIsNotAnError(): void
    {
        $client = $this->client([new MockResponse((string) json_encode([
            'id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'model' => 'm',
            'content' => [['type' => 'text', 'text' => 'no usage field']],
        ]))]);

        $response = $client->complete($this->messageTurn(), $this->tools());

        self::assertSame(['input' => 0, 'output' => 0], $response['usage'], 'the client reports zeros; the orchestrator bills the conservative floor');
        self::assertSame('no usage field', $response['content'][0]['text']);
    }

    public function testMissingCredentialsFailWithoutAnyRequest(): void
    {
        $record = [];
        $httpClient = new ThrowingHttpClient($record, PHP_INT_MAX);
        $client = $this->clientWithKey('', $httpClient);

        try {
            $client->complete($this->messageTurn(), $this->tools());
            self::fail('expected AgentLlmUnavailableException for missing credentials');
        } catch (AgentLlmUnavailableException) {
            self::assertCount(0, $record, 'an empty key must never attempt a request, no retry loop');
        }
    }

    // ------------------------------------------------------------- helpers

    /**
     * @param list<MockResponse> $responses
     */
    private function client(array $responses, string $key = self::TEST_KEY): AnthropicClient
    {
        $next = 0;
        $requests = &$this->requests;
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use ($responses, &$next, &$requests): MockResponse {
            $requests[] = ['method' => $method, 'url' => $url, 'options' => $options];

            return $responses[$next++] ?? new MockResponse('{}');
        });

        return $this->clientWithKey($key, $httpClient);
    }

    private function clientWithKey(string $key, HttpClientInterface $httpClient): AnthropicClient
    {
        return new AnthropicClient(
            new AgentConfig(
                enabled: true,
                maxTokens: 512,
                maxToolRounds: 4,
                contextMessages: 12,
                rateLimitMessages: 20,
                rateLimitIntervalSec: 300,
                dailyTokenCap: 200000,
                msgMaxChars: 4000,
            ),
            $httpClient,
            $key,
            new NullLogger(),
        );
    }

    /**
     * @param array<int, int> $usage
     */
    private function textMessage(string $text, array $usage = ['0' => 10, '1' => 10], string $stopReason = 'end_turn'): MockResponse
    {
        return new MockResponse((string) json_encode([
            'id' => 'msg_01abc',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-4-5',
            'content' => [['type' => 'text', 'text' => $text]],
            'stop_reason' => $stopReason,
            'usage' => ['input_tokens' => $usage[0], 'output_tokens' => $usage[1]],
        ]));
    }

    /**
     * @param array<string, mixed> $input
     * @param array<int, int>      $usage
     */
    private function toolUseMessage(string $id, string $name, array $input = [], array $usage = ['0' => 10, '1' => 10]): MockResponse
    {
        return new MockResponse((string) json_encode([
            'id' => 'msg_01abc',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-4-5',
            'content' => [['type' => 'tool_use', 'id' => $id, 'name' => $name, 'input' => $input]],
            'stop_reason' => 'tool_use',
            'usage' => ['input_tokens' => $usage[0], 'output_tokens' => $usage[1]],
        ]));
    }

    private function errorResponse(int $status, string $type): MockResponse
    {
        return new MockResponse(
            (string) json_encode(['type' => 'error', 'error' => ['type' => $type, 'message' => 'internal detail never relayed']]),
            ['http_code' => $status],
        );
    }

    /**
     * @return list<array<string, mixed>> one plain user turn
     */
    private function messageTurn(): array
    {
        return [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'hello']]]];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function tools(): array
    {
        return [
            [
                'name' => 'list_my_machines',
                'description' => 'List machines.',
                'input_schema' => ['type' => 'object', 'properties' => [], 'additionalProperties' => false],
            ],
        ];
    }

    /**
     * @param array{options: array<string, mixed>} $request
     *
     * @return list<string> normalized "key: value" header lines
     */
    private function headerList(array $request): array
    {
        // Symfony normalizes request headers to a list of "name: value" strings.
        $raw = $request['options']['headers'] ?? [];

        return array_values(array_filter($raw, fn ($line): bool => \is_string($line)));
    }

    /**
     * @return array<string, mixed> decoded JSON body of the recorded request
     */
    /**
     * @param array{options: array<string, mixed>} $request
     *
     * @return array<string, mixed> decoded JSON body of the recorded request
     */
    private function bodyOf(array $request): array
    {
        $body = $request['options']['body'] ?? '';
        self::assertIsString($body);
        $decoded = json_decode($body, true);
        self::assertIsArray($decoded);

        return $decoded;
    }
}

/**
 * Test double: every request() throws a transport exception (timeout class),
 * recording what it received. Proves retry policy around transport failures.
 */
final class ThrowingHttpClient implements HttpClientInterface
{
    /** @param array<int, array{method: string, url: string}> $record */
    public function __construct(
        private array &$record,
        private int $failures,
    ) {
    }

    public function count(): int
    {
        return \count($this->record);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $this->record[] = ['method' => $method, 'url' => $url];

        if ($this->failures-- > 0) {
            throw new TransportException('simulated transport timeout');
        }

        return new MockResponse('{}');
    }

    public function stream($responses, float $timeout = null): ResponseStreamInterface
    {
        throw new \LogicException('not used');
    }

    /**
     * @param array<string, mixed> $options
     */
    public function withOptions(array $options): static
    {
        return $this;
    }
}
