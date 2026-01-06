<?php

declare(strict_types=1);

namespace WordPress\AiClient\Tests\unit\ProviderImplementations\Anthropic;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\ProviderImplementations\Anthropic\AnthropicTextGenerationModel;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface;
use WordPress\AiClient\Providers\Http\Contracts\RequestAuthenticationInterface;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Exception\ClientException;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Results\Enums\FinishReasonEnum;
use WordPress\AiClient\Tools\DTO\FunctionCall;
use WordPress\AiClient\Tools\DTO\FunctionDeclaration;
use WordPress\AiClient\Tools\DTO\FunctionResponse;

/**
 * @covers \WordPress\AiClient\ProviderImplementations\Anthropic\AnthropicTextGenerationModel
 */
class AnthropicTextGenerationModelTest extends TestCase
{
    /**
     * @var ModelMetadata&\PHPUnit\Framework\MockObject\MockObject
     */
    private $modelMetadata;

    /**
     * @var ProviderMetadata&\PHPUnit\Framework\MockObject\MockObject
     */
    private $providerMetadata;

    /**
     * @var HttpTransporterInterface&\PHPUnit\Framework\MockObject\MockObject
     */
    private $mockHttpTransporter;

    /**
     * @var RequestAuthenticationInterface&\PHPUnit\Framework\MockObject\MockObject
     */
    private $mockRequestAuthentication;

    protected function setUp(): void
    {
        parent::setUp();

        $this->modelMetadata = $this->createStub(ModelMetadata::class);
        $this->modelMetadata->method('getId')->willReturn('claude-3-haiku-20240307');
        $this->providerMetadata = $this->createStub(ProviderMetadata::class);
        $this->providerMetadata->method('getName')->willReturn('Anthropic');
        $this->mockHttpTransporter = $this->createMock(HttpTransporterInterface::class);
        $this->mockRequestAuthentication = $this->createMock(RequestAuthenticationInterface::class);
    }

    /**
     * Creates a model instance with mocked dependencies.
     *
     * @param ModelConfig|null $modelConfig
     * @return AnthropicTextGenerationModel
     */
    private function createModel(?ModelConfig $modelConfig = null): AnthropicTextGenerationModel
    {
        $model = new AnthropicTextGenerationModel($this->modelMetadata, $this->providerMetadata);
        $model->setHttpTransporter($this->mockHttpTransporter);
        $model->setRequestAuthentication($this->mockRequestAuthentication);
        if ($modelConfig) {
            $model->setConfig($modelConfig);
        }
        return $model;
    }

    /**
     * Tests generateTextResult() with a successful basic text response.
     *
     * @return void
     */
    public function testGenerateTextResultSuccess(): void
    {
        $prompt = [new Message(MessageRoleEnum::user(), [new MessagePart('Hello')])];
        $response = new Response(
            200,
            [],
            json_encode([
                'id' => 'msg_123',
                'type' => 'message',
                'role' => 'assistant',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => 'Hello!',
                    ],
                ],
                'stop_reason' => 'end_turn',
                'usage' => [
                    'input_tokens' => 10,
                    'output_tokens' => 5,
                ],
            ])
        );

        $this->mockRequestAuthentication
            ->expects($this->once())
            ->method('authenticateRequest')
            ->willReturnArgument(0);

        $this->mockHttpTransporter
            ->expects($this->once())
            ->method('send')
            ->willReturn($response);

        $model = $this->createModel();
        $result = $model->generateTextResult($prompt);

        $this->assertInstanceOf(GenerativeAiResult::class, $result);
        $this->assertEquals('msg_123', $result->getId());
        $this->assertCount(1, $result->getCandidates());
        $this->assertEquals('Hello!', $result->getCandidates()[0]->getMessage()->getParts()[0]->getText());
        $this->assertEquals(FinishReasonEnum::stop(), $result->getCandidates()[0]->getFinishReason());
        $this->assertEquals(10, $result->getTokenUsage()->getPromptTokens());
        $this->assertEquals(5, $result->getTokenUsage()->getCompletionTokens());
        $this->assertEquals(15, $result->getTokenUsage()->getTotalTokens());
    }

    /**
     * Tests generateTextResult() with a tool use response.
     *
     * @return void
     */
    public function testGenerateTextResultWithToolUse(): void
    {
        $prompt = [new Message(MessageRoleEnum::user(), [new MessagePart('What is the weather in Paris?')])];
        $response = new Response(
            200,
            [],
            json_encode([
                'id' => 'msg_123',
                'type' => 'message',
                'role' => 'assistant',
                'content' => [
                    [
                        'type' => 'tool_use',
                        'id' => 'toolu_123',
                        'name' => 'get_weather',
                        'input' => [
                            'location' => 'Paris',
                        ],
                    ],
                ],
                'stop_reason' => 'tool_use',
                'usage' => [
                    'input_tokens' => 331,
                    'output_tokens' => 53,
                ],
            ])
        );

        $this->mockRequestAuthentication
            ->expects($this->once())
            ->method('authenticateRequest')
            ->willReturnArgument(0);

        $this->mockHttpTransporter
            ->expects($this->once())
            ->method('send')
            ->willReturn($response);

        $model = $this->createModel();
        $result = $model->generateTextResult($prompt);

        $this->assertInstanceOf(GenerativeAiResult::class, $result);
        $this->assertEquals(FinishReasonEnum::toolCalls(), $result->getCandidates()[0]->getFinishReason());

        $parts = $result->getCandidates()[0]->getMessage()->getParts();
        $this->assertCount(1, $parts);

        $functionCall = $parts[0]->getFunctionCall();
        $this->assertInstanceOf(FunctionCall::class, $functionCall);
        $this->assertEquals('toolu_123', $functionCall->getId());
        $this->assertEquals('get_weather', $functionCall->getName());
        $this->assertEquals(['location' => 'Paris'], $functionCall->getArgs());
    }

    /**
     * Tests generateTextResult() with max_tokens stop reason.
     *
     * @return void
     */
    public function testGenerateTextResultWithMaxTokensStopReason(): void
    {
        $prompt = [new Message(MessageRoleEnum::user(), [new MessagePart('Write a long story')])];
        $response = new Response(
            200,
            [],
            json_encode([
                'id' => 'msg_123',
                'type' => 'message',
                'role' => 'assistant',
                'content' => [
                    ['type' => 'text', 'text' => 'Once upon a time...'],
                ],
                'stop_reason' => 'max_tokens',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 100],
            ])
        );

        $this->mockRequestAuthentication->method('authenticateRequest')->willReturnArgument(0);
        $this->mockHttpTransporter->method('send')->willReturn($response);

        $model = $this->createModel();
        $result = $model->generateTextResult($prompt);

        $this->assertEquals(FinishReasonEnum::length(), $result->getCandidates()[0]->getFinishReason());
    }

    /**
     * Tests generateTextResult() with stop_sequence stop reason.
     *
     * @return void
     */
    public function testGenerateTextResultWithStopSequenceStopReason(): void
    {
        $prompt = [new Message(MessageRoleEnum::user(), [new MessagePart('Say hello')])];
        $response = new Response(
            200,
            [],
            json_encode([
                'id' => 'msg_123',
                'type' => 'message',
                'role' => 'assistant',
                'content' => [
                    ['type' => 'text', 'text' => 'Hello'],
                ],
                'stop_reason' => 'stop_sequence',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ])
        );

        $this->mockRequestAuthentication->method('authenticateRequest')->willReturnArgument(0);
        $this->mockHttpTransporter->method('send')->willReturn($response);

        $model = $this->createModel();
        $result = $model->generateTextResult($prompt);

        $this->assertEquals(FinishReasonEnum::stop(), $result->getCandidates()[0]->getFinishReason());
    }

    /**
     * Tests generateTextResult() with pause_turn stop reason.
     *
     * @return void
     */
    public function testGenerateTextResultWithPauseTurnStopReason(): void
    {
        $prompt = [new Message(MessageRoleEnum::user(), [new MessagePart('Say hello')])];
        $response = new Response(
            200,
            [],
            json_encode([
                'id' => 'msg_123',
                'type' => 'message',
                'role' => 'assistant',
                'content' => [
                    ['type' => 'text', 'text' => 'Hello'],
                ],
                'stop_reason' => 'pause_turn',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ])
        );

        $this->mockRequestAuthentication->method('authenticateRequest')->willReturnArgument(0);
        $this->mockHttpTransporter->method('send')->willReturn($response);

        $model = $this->createModel();
        $result = $model->generateTextResult($prompt);

        $this->assertEquals(FinishReasonEnum::stop(), $result->getCandidates()[0]->getFinishReason());
    }

    /**
     * Tests generateTextResult() with refusal stop reason.
     *
     * @return void
     */
    public function testGenerateTextResultWithRefusalStopReason(): void
    {
        $prompt = [new Message(MessageRoleEnum::user(), [new MessagePart('Say hello')])];
        $response = new Response(
            200,
            [],
            json_encode([
                'id' => 'msg_123',
                'type' => 'message',
                'role' => 'assistant',
                'content' => [
                    ['type' => 'text', 'text' => 'I cannot help with that.'],
                ],
                'stop_reason' => 'refusal',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ])
        );

        $this->mockRequestAuthentication->method('authenticateRequest')->willReturnArgument(0);
        $this->mockHttpTransporter->method('send')->willReturn($response);

        $model = $this->createModel();
        $result = $model->generateTextResult($prompt);

        $this->assertEquals(FinishReasonEnum::contentFilter(), $result->getCandidates()[0]->getFinishReason());
    }

    /**
     * Tests generateTextResult() with model_context_window_exceeded stop reason.
     *
     * @return void
     */
    public function testGenerateTextResultWithContextWindowExceededStopReason(): void
    {
        $prompt = [new Message(MessageRoleEnum::user(), [new MessagePart('Say hello')])];
        $response = new Response(
            200,
            [],
            json_encode([
                'id' => 'msg_123',
                'type' => 'message',
                'role' => 'assistant',
                'content' => [
                    ['type' => 'text', 'text' => 'Hello'],
                ],
                'stop_reason' => 'model_context_window_exceeded',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ])
        );

        $this->mockRequestAuthentication->method('authenticateRequest')->willReturnArgument(0);
        $this->mockHttpTransporter->method('send')->willReturn($response);

        $model = $this->createModel();
        $result = $model->generateTextResult($prompt);

        $this->assertEquals(FinishReasonEnum::length(), $result->getCandidates()[0]->getFinishReason());
    }

    /**
     * Tests generateTextResult() on API failure.
     *
     * @return void
     */
    public function testGenerateTextResultApiFailure(): void
    {
        $prompt = [new Message(MessageRoleEnum::user(), [new MessagePart('Hello')])];
        $response = new Response(
            400,
            [],
            json_encode([
                'type' => 'error',
                'error' => [
                    'type' => 'invalid_request_error',
                    'message' => 'Invalid parameter.',
                ],
            ])
        );

        $this->mockRequestAuthentication
            ->expects($this->once())
            ->method('authenticateRequest')
            ->willReturnArgument(0);

        $this->mockHttpTransporter
            ->expects($this->once())
            ->method('send')
            ->willReturn($response);

        $model = $this->createModel();

        $this->expectException(ClientException::class);

        $model->generateTextResult($prompt);
    }

    /**
     * Tests streamGenerateTextResult() throws RuntimeException.
     *
     * @return void
     */
    public function testStreamGenerateTextResultThrowsException(): void
    {
        $prompt = [new Message(MessageRoleEnum::user(), [new MessagePart('Hello')])];
        $model = $this->createModel();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Streaming is not yet implemented.');

        $generator = $model->streamGenerateTextResult($prompt);
        $generator->current();
    }

    /**
     * Tests that the request includes system instruction as top-level parameter.
     *
     * @return void
     */
    public function testSystemInstructionIncludedInRequest(): void
    {
        $prompt = [new Message(MessageRoleEnum::user(), [new MessagePart('Hello')])];
        $modelConfig = ModelConfig::fromArray(['systemInstruction' => 'You are a helpful assistant.']);
        $response = new Response(
            200,
            [],
            json_encode([
                'id' => 'msg_123',
                'content' => [['type' => 'text', 'text' => 'Hi!']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ])
        );

        /** @var Request|null $capturedRequest */
        $capturedRequest = null;
        $this->mockRequestAuthentication->method('authenticateRequest')->willReturnCallback(
            function (Request $request) use (&$capturedRequest): Request {
                $capturedRequest = $request;
                return $request;
            }
        );
        $this->mockHttpTransporter->method('send')->willReturn($response);

        $model = $this->createModel($modelConfig);
        $model->generateTextResult($prompt);

        $this->assertNotNull($capturedRequest);
        $requestData = $capturedRequest->getData();
        $this->assertArrayHasKey('system', $requestData);
        $this->assertEquals('You are a helpful assistant.', $requestData['system']);
    }

    /**
     * Tests that stop sequences are included as stop_sequences parameter.
     *
     * @return void
     */
    public function testStopSequencesIncludedInRequest(): void
    {
        $prompt = [new Message(MessageRoleEnum::user(), [new MessagePart('Hello')])];
        $modelConfig = ModelConfig::fromArray(['stopSequences' => ['STOP', 'END']]);
        $response = new Response(
            200,
            [],
            json_encode([
                'id' => 'msg_123',
                'content' => [['type' => 'text', 'text' => 'Hi!']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ])
        );

        /** @var Request|null $capturedRequest */
        $capturedRequest = null;
        $this->mockRequestAuthentication->method('authenticateRequest')->willReturnCallback(
            function (Request $request) use (&$capturedRequest): Request {
                $capturedRequest = $request;
                return $request;
            }
        );
        $this->mockHttpTransporter->method('send')->willReturn($response);

        $model = $this->createModel($modelConfig);
        $model->generateTextResult($prompt);

        $this->assertNotNull($capturedRequest);
        $requestData = $capturedRequest->getData();
        $this->assertArrayHasKey('stop_sequences', $requestData);
        $this->assertEquals(['STOP', 'END'], $requestData['stop_sequences']);
    }

    /**
     * Tests that function declarations are formatted with input_schema.
     *
     * @return void
     */
    public function testFunctionDeclarationsFormattedCorrectly(): void
    {
        $prompt = [new Message(MessageRoleEnum::user(), [new MessagePart('What is the weather?')])];
        $functionDeclaration = new FunctionDeclaration(
            'get_weather',
            'Get the weather for a location',
            [
                'type' => 'object',
                'properties' => [
                    'location' => ['type' => 'string'],
                ],
                'required' => ['location'],
            ]
        );
        $modelConfig = ModelConfig::fromArray([
            'functionDeclarations' => [$functionDeclaration->toArray()],
        ]);
        $response = new Response(
            200,
            [],
            json_encode([
                'id' => 'msg_123',
                'content' => [['type' => 'text', 'text' => 'I can help with that.']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ])
        );

        /** @var Request|null $capturedRequest */
        $capturedRequest = null;
        $this->mockRequestAuthentication->method('authenticateRequest')->willReturnCallback(
            function (Request $request) use (&$capturedRequest): Request {
                $capturedRequest = $request;
                return $request;
            }
        );
        $this->mockHttpTransporter->method('send')->willReturn($response);

        $model = $this->createModel($modelConfig);
        $model->generateTextResult($prompt);

        $this->assertNotNull($capturedRequest);
        $requestData = $capturedRequest->getData();
        $this->assertArrayHasKey('tools', $requestData);
        $this->assertCount(1, $requestData['tools']);
        $this->assertEquals('get_weather', $requestData['tools'][0]['name']);
        $this->assertEquals('Get the weather for a location', $requestData['tools'][0]['description']);
        $this->assertArrayHasKey('input_schema', $requestData['tools'][0]);
    }

    /**
     * Tests that unsupported parameters are excluded from the API request.
     *
     * @dataProvider unsupportedParamsProvider
     * @param string $configKey The config option name.
     * @param mixed $configValue The config option value.
     * @param string $apiParamKey The potential API parameter name to check is absent.
     * @return void
     */
    public function testUnsupportedParamsExcludedFromRequest(
        string $configKey,
        $configValue,
        string $apiParamKey
    ): void {
        $prompt = [new Message(MessageRoleEnum::user(), [new MessagePart('Hello')])];
        $modelConfig = ModelConfig::fromArray([$configKey => $configValue]);
        $response = new Response(
            200,
            [],
            json_encode([
                'id' => 'msg_123',
                'content' => [['type' => 'text', 'text' => 'Hi!']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ])
        );

        /** @var Request|null $capturedRequest */
        $capturedRequest = null;
        $this->mockRequestAuthentication->method('authenticateRequest')->willReturnCallback(
            function (Request $request) use (&$capturedRequest): Request {
                $capturedRequest = $request;
                return $request;
            }
        );
        $this->mockHttpTransporter->method('send')->willReturn($response);

        $model = $this->createModel($modelConfig);
        $model->generateTextResult($prompt);

        $this->assertNotNull($capturedRequest);
        $requestData = $capturedRequest->getData();
        $this->assertArrayNotHasKey(
            $apiParamKey,
            $requestData,
            "Unsupported param '{$configKey}' should not appear as '{$apiParamKey}' in request"
        );
    }

    /**
     * Provides unsupported config options and their potential API parameter names.
     *
     * @return array<string, array<mixed>>
     */
    public function unsupportedParamsProvider(): array
    {
        return [
            'presencePenalty' => ['presencePenalty', 0.5, 'presence_penalty'],
            'frequencyPenalty' => ['frequencyPenalty', 0.5, 'frequency_penalty'],
            'logprobs' => ['logprobs', true, 'logprobs'],
            'topLogprobs' => ['topLogprobs', 5, 'top_logprobs'],
            'candidateCount > 1' => ['candidateCount', 3, 'n'],
        ];
    }

    /**
     * Tests that JSON output with schema includes json_schema type.
     *
     * @return void
     */
    public function testJsonOutputWithSchemaIncludesSchema(): void
    {
        $prompt = [new Message(MessageRoleEnum::user(), [new MessagePart('Generate a person')])];
        $schema = [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string'],
                'age' => ['type' => 'integer'],
            ],
            'required' => ['name', 'age'],
            'additionalProperties' => false,
        ];
        $modelConfig = ModelConfig::fromArray([
            'outputMimeType' => 'application/json',
            'outputSchema' => $schema,
        ]);
        $response = new Response(
            200,
            [],
            json_encode([
                'id' => 'msg_123',
                'content' => [['type' => 'text', 'text' => '{"name": "Alice", "age": 30}']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ])
        );

        /** @var Request|null $capturedRequest */
        $capturedRequest = null;
        $this->mockRequestAuthentication->method('authenticateRequest')->willReturnCallback(
            function (Request $request) use (&$capturedRequest): Request {
                $capturedRequest = $request;
                return $request;
            }
        );
        $this->mockHttpTransporter->method('send')->willReturn($response);

        $model = $this->createModel($modelConfig);
        $model->generateTextResult($prompt);

        $this->assertNotNull($capturedRequest);
        $requestData = $capturedRequest->getData();
        $this->assertArrayHasKey('output_format', $requestData);
        $this->assertEquals('json_schema', $requestData['output_format']['type']);
        $this->assertEquals($schema, $requestData['output_format']['schema']);
    }

    /**
     * Tests multi-turn conversation formatting.
     *
     * @return void
     */
    public function testMultiTurnConversationFormatting(): void
    {
        $prompt = [
            new Message(MessageRoleEnum::user(), [new MessagePart('My name is Alice.')]),
            new Message(MessageRoleEnum::model(), [new MessagePart('Hello Alice!')]),
            new Message(MessageRoleEnum::user(), [new MessagePart('What is my name?')]),
        ];
        $response = new Response(
            200,
            [],
            json_encode([
                'id' => 'msg_123',
                'content' => [['type' => 'text', 'text' => 'Your name is Alice.']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 30, 'output_tokens' => 10],
            ])
        );

        /** @var Request|null $capturedRequest */
        $capturedRequest = null;
        $this->mockRequestAuthentication->method('authenticateRequest')->willReturnCallback(
            function (Request $request) use (&$capturedRequest): Request {
                $capturedRequest = $request;
                return $request;
            }
        );
        $this->mockHttpTransporter->method('send')->willReturn($response);

        $model = $this->createModel();
        $model->generateTextResult($prompt);

        $this->assertNotNull($capturedRequest);
        $requestData = $capturedRequest->getData();
        $this->assertArrayHasKey('messages', $requestData);
        $this->assertCount(3, $requestData['messages']);
        $this->assertEquals('user', $requestData['messages'][0]['role']);
        $this->assertEquals('assistant', $requestData['messages'][1]['role']);
        $this->assertEquals('user', $requestData['messages'][2]['role']);
    }

    /**
     * Tests that tool result is formatted correctly.
     *
     * @return void
     */
    public function testToolResultFormatting(): void
    {
        $functionResponse = new FunctionResponse(
            'toolu_123',
            'get_weather',
            ['temperature' => 72, 'condition' => 'sunny']
        );
        $prompt = [
            new Message(MessageRoleEnum::user(), [new MessagePart($functionResponse)]),
        ];
        $response = new Response(
            200,
            [],
            json_encode([
                'id' => 'msg_123',
                'content' => [['type' => 'text', 'text' => 'The weather is 72 degrees and sunny.']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 30, 'output_tokens' => 10],
            ])
        );

        /** @var Request|null $capturedRequest */
        $capturedRequest = null;
        $this->mockRequestAuthentication->method('authenticateRequest')->willReturnCallback(
            function (Request $request) use (&$capturedRequest): Request {
                $capturedRequest = $request;
                return $request;
            }
        );
        $this->mockHttpTransporter->method('send')->willReturn($response);

        $model = $this->createModel();
        $model->generateTextResult($prompt);

        $this->assertNotNull($capturedRequest);
        $requestData = $capturedRequest->getData();
        $this->assertArrayHasKey('messages', $requestData);
        $this->assertCount(1, $requestData['messages']);
        $this->assertEquals('user', $requestData['messages'][0]['role']);

        $content = $requestData['messages'][0]['content'];
        $this->assertCount(1, $content);
        $this->assertEquals('tool_result', $content[0]['type']);
        $this->assertEquals('toolu_123', $content[0]['tool_use_id']);
    }

    /**
     * Tests that default max_tokens is included.
     *
     * @return void
     */
    public function testDefaultMaxTokensIncluded(): void
    {
        $prompt = [new Message(MessageRoleEnum::user(), [new MessagePart('Hello')])];
        $response = new Response(
            200,
            [],
            json_encode([
                'id' => 'msg_123',
                'content' => [['type' => 'text', 'text' => 'Hi!']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ])
        );

        /** @var Request|null $capturedRequest */
        $capturedRequest = null;
        $this->mockRequestAuthentication->method('authenticateRequest')->willReturnCallback(
            function (Request $request) use (&$capturedRequest): Request {
                $capturedRequest = $request;
                return $request;
            }
        );
        $this->mockHttpTransporter->method('send')->willReturn($response);

        $model = $this->createModel();
        $model->generateTextResult($prompt);

        $this->assertNotNull($capturedRequest);
        $requestData = $capturedRequest->getData();
        $this->assertArrayHasKey('max_tokens', $requestData);
        $this->assertEquals(4096, $requestData['max_tokens']);
    }

    /**
     * Tests that custom max_tokens is used when provided.
     *
     * @return void
     */
    public function testCustomMaxTokensUsed(): void
    {
        $prompt = [new Message(MessageRoleEnum::user(), [new MessagePart('Hello')])];
        $modelConfig = ModelConfig::fromArray(['maxTokens' => 100]);
        $response = new Response(
            200,
            [],
            json_encode([
                'id' => 'msg_123',
                'content' => [['type' => 'text', 'text' => 'Hi!']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ])
        );

        /** @var Request|null $capturedRequest */
        $capturedRequest = null;
        $this->mockRequestAuthentication->method('authenticateRequest')->willReturnCallback(
            function (Request $request) use (&$capturedRequest): Request {
                $capturedRequest = $request;
                return $request;
            }
        );
        $this->mockHttpTransporter->method('send')->willReturn($response);

        $model = $this->createModel($modelConfig);
        $model->generateTextResult($prompt);

        $this->assertNotNull($capturedRequest);
        $requestData = $capturedRequest->getData();
        $this->assertArrayHasKey('max_tokens', $requestData);
        $this->assertEquals(100, $requestData['max_tokens']);
    }

    /**
     * Tests that temperature is included when provided.
     *
     * @return void
     */
    public function testTemperatureIncludedInRequest(): void
    {
        $prompt = [new Message(MessageRoleEnum::user(), [new MessagePart('Hello')])];
        $modelConfig = ModelConfig::fromArray(['temperature' => 0.7]);
        $response = new Response(
            200,
            [],
            json_encode([
                'id' => 'msg_123',
                'content' => [['type' => 'text', 'text' => 'Hi!']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ])
        );

        /** @var Request|null $capturedRequest */
        $capturedRequest = null;
        $this->mockRequestAuthentication->method('authenticateRequest')->willReturnCallback(
            function (Request $request) use (&$capturedRequest): Request {
                $capturedRequest = $request;
                return $request;
            }
        );
        $this->mockHttpTransporter->method('send')->willReturn($response);

        $model = $this->createModel($modelConfig);
        $model->generateTextResult($prompt);

        $this->assertNotNull($capturedRequest);
        $requestData = $capturedRequest->getData();
        $this->assertArrayHasKey('temperature', $requestData);
        $this->assertEquals(0.7, $requestData['temperature']);
    }

    /**
     * Tests that top_p is included when provided.
     *
     * @return void
     */
    public function testTopPIncludedInRequest(): void
    {
        $prompt = [new Message(MessageRoleEnum::user(), [new MessagePart('Hello')])];
        $modelConfig = ModelConfig::fromArray(['topP' => 0.9]);
        $response = new Response(
            200,
            [],
            json_encode([
                'id' => 'msg_123',
                'content' => [['type' => 'text', 'text' => 'Hi!']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ])
        );

        /** @var Request|null $capturedRequest */
        $capturedRequest = null;
        $this->mockRequestAuthentication->method('authenticateRequest')->willReturnCallback(
            function (Request $request) use (&$capturedRequest): Request {
                $capturedRequest = $request;
                return $request;
            }
        );
        $this->mockHttpTransporter->method('send')->willReturn($response);

        $model = $this->createModel($modelConfig);
        $model->generateTextResult($prompt);

        $this->assertNotNull($capturedRequest);
        $requestData = $capturedRequest->getData();
        $this->assertArrayHasKey('top_p', $requestData);
        $this->assertEquals(0.9, $requestData['top_p']);
    }

    /**
     * Tests that top_k is included when provided.
     *
     * @return void
     */
    public function testTopKIncludedInRequest(): void
    {
        $prompt = [new Message(MessageRoleEnum::user(), [new MessagePart('Hello')])];
        $modelConfig = ModelConfig::fromArray(['topK' => 40]);
        $response = new Response(
            200,
            [],
            json_encode([
                'id' => 'msg_123',
                'content' => [['type' => 'text', 'text' => 'Hi!']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ])
        );

        /** @var Request|null $capturedRequest */
        $capturedRequest = null;
        $this->mockRequestAuthentication->method('authenticateRequest')->willReturnCallback(
            function (Request $request) use (&$capturedRequest): Request {
                $capturedRequest = $request;
                return $request;
            }
        );
        $this->mockHttpTransporter->method('send')->willReturn($response);

        $model = $this->createModel($modelConfig);
        $model->generateTextResult($prompt);

        $this->assertNotNull($capturedRequest);
        $requestData = $capturedRequest->getData();
        $this->assertArrayHasKey('top_k', $requestData);
        $this->assertEquals(40, $requestData['top_k']);
    }

    /**
     * Tests that custom options are included in request.
     *
     * @return void
     */
    public function testCustomOptionsIncludedInRequest(): void
    {
        $prompt = [new Message(MessageRoleEnum::user(), [new MessagePart('Hello')])];
        $modelConfig = ModelConfig::fromArray(['customOptions' => ['custom_key' => 'custom_value']]);
        $response = new Response(
            200,
            [],
            json_encode([
                'id' => 'msg_123',
                'content' => [['type' => 'text', 'text' => 'Hi!']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ])
        );

        /** @var Request|null $capturedRequest */
        $capturedRequest = null;
        $this->mockRequestAuthentication->method('authenticateRequest')->willReturnCallback(
            function (Request $request) use (&$capturedRequest): Request {
                $capturedRequest = $request;
                return $request;
            }
        );
        $this->mockHttpTransporter->method('send')->willReturn($response);

        $model = $this->createModel($modelConfig);
        $model->generateTextResult($prompt);

        $this->assertNotNull($capturedRequest);
        $requestData = $capturedRequest->getData();
        $this->assertArrayHasKey('custom_key', $requestData);
        $this->assertEquals('custom_value', $requestData['custom_key']);
    }

    /**
     * Tests that conflicting custom options throw exception.
     *
     * @return void
     */
    public function testConflictingCustomOptionThrowsException(): void
    {
        $prompt = [new Message(MessageRoleEnum::user(), [new MessagePart('Hello')])];
        $modelConfig = ModelConfig::fromArray(['customOptions' => ['model' => 'different-model']]);
        $model = $this->createModel($modelConfig);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The custom option "model" conflicts with an existing parameter.');

        $model->generateTextResult($prompt);
    }

    /**
     * Tests that candidateCount of 1 is allowed.
     *
     * @return void
     */
    public function testCandidateCountOneIsAllowed(): void
    {
        $prompt = [new Message(MessageRoleEnum::user(), [new MessagePart('Hello')])];
        $modelConfig = ModelConfig::fromArray(['candidateCount' => 1]);
        $response = new Response(
            200,
            [],
            json_encode([
                'id' => 'msg_123',
                'content' => [['type' => 'text', 'text' => 'Hi!']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ])
        );

        $this->mockRequestAuthentication->method('authenticateRequest')->willReturnArgument(0);
        $this->mockHttpTransporter->method('send')->willReturn($response);

        $model = $this->createModel($modelConfig);
        $result = $model->generateTextResult($prompt);

        $this->assertInstanceOf(GenerativeAiResult::class, $result);
    }

    /**
     * Tests that candidateCount of 0 is allowed.
     *
     * @return void
     */
    public function testCandidateCountZeroIsAllowed(): void
    {
        $prompt = [new Message(MessageRoleEnum::user(), [new MessagePart('Hello')])];
        $modelConfig = ModelConfig::fromArray(['candidateCount' => 0]);
        $response = new Response(
            200,
            [],
            json_encode([
                'id' => 'msg_123',
                'content' => [['type' => 'text', 'text' => 'Hi!']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ])
        );

        $this->mockRequestAuthentication->method('authenticateRequest')->willReturnArgument(0);
        $this->mockHttpTransporter->method('send')->willReturn($response);

        $model = $this->createModel($modelConfig);
        $result = $model->generateTextResult($prompt);

        $this->assertInstanceOf(GenerativeAiResult::class, $result);
    }

    /**
     * Tests that anthropic-beta header is included when JSON schema output is configured.
     *
     * @return void
     */
    public function testAnthropicBetaHeaderIncludedWithJsonSchema(): void
    {
        $prompt = [new Message(MessageRoleEnum::user(), [new MessagePart('Generate JSON')])];
        $schema = [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string'],
            ],
            'required' => ['name'],
            'additionalProperties' => false,
        ];
        $modelConfig = ModelConfig::fromArray([
            'outputMimeType' => 'application/json',
            'outputSchema' => $schema,
        ]);
        $response = new Response(
            200,
            [],
            json_encode([
                'id' => 'msg_123',
                'content' => [['type' => 'text', 'text' => '{"name": "test"}']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ])
        );

        /** @var Request|null $capturedRequest */
        $capturedRequest = null;
        $this->mockRequestAuthentication->method('authenticateRequest')->willReturnCallback(
            function (Request $request) use (&$capturedRequest): Request {
                $capturedRequest = $request;
                return $request;
            }
        );
        $this->mockHttpTransporter->method('send')->willReturn($response);

        $model = $this->createModel($modelConfig);
        $model->generateTextResult($prompt);

        $this->assertNotNull($capturedRequest);
        $headers = $capturedRequest->getHeaders();
        $this->assertArrayHasKey('anthropic-beta', $headers);
        $this->assertContains('structured-outputs-2025-11-13', $headers['anthropic-beta']);
    }

    /**
     * Tests that anthropic-beta header is NOT included when only outputMimeType is set without schema.
     *
     * @return void
     */
    public function testAnthropicBetaHeaderNotIncludedWithoutSchema(): void
    {
        $prompt = [new Message(MessageRoleEnum::user(), [new MessagePart('Hello')])];
        $modelConfig = ModelConfig::fromArray([
            'outputMimeType' => 'application/json',
        ]);
        $response = new Response(
            200,
            [],
            json_encode([
                'id' => 'msg_123',
                'content' => [['type' => 'text', 'text' => '{"greeting": "hello"}']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ])
        );

        /** @var Request|null $capturedRequest */
        $capturedRequest = null;
        $this->mockRequestAuthentication->method('authenticateRequest')->willReturnCallback(
            function (Request $request) use (&$capturedRequest): Request {
                $capturedRequest = $request;
                return $request;
            }
        );
        $this->mockHttpTransporter->method('send')->willReturn($response);

        $model = $this->createModel($modelConfig);
        $model->generateTextResult($prompt);

        $this->assertNotNull($capturedRequest);
        $headers = $capturedRequest->getHeaders();
        $this->assertArrayNotHasKey('anthropic-beta', $headers);
    }
}
