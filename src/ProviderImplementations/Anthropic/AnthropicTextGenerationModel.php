<?php

declare(strict_types=1);

namespace WordPress\AiClient\ProviderImplementations\Anthropic;

use Generator;
use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Common\Exception\RuntimeException;
use WordPress\AiClient\Files\DTO\File;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiBasedModel;
use WordPress\AiClient\Providers\Http\Contracts\RequestAuthenticationInterface;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Http\Util\ResponseUtil;
use WordPress\AiClient\Providers\Models\TextGeneration\Contracts\TextGenerationModelInterface;
use WordPress\AiClient\Results\DTO\Candidate;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Results\DTO\TokenUsage;
use WordPress\AiClient\Results\Enums\FinishReasonEnum;
use WordPress\AiClient\Tools\DTO\FunctionCall;
use WordPress\AiClient\Tools\DTO\FunctionDeclaration;
use WordPress\AiClient\Tools\DTO\FunctionResponse;

/**
 * Text generation model implementation for Anthropic's native Messages API.
 *
 * @since 0.1.0
 *
 * @phpstan-type ContentBlock array{
 *     type: string,
 *     text?: string,
 *     id?: string,
 *     name?: string,
 *     input?: array<string, mixed>
 * }
 * @phpstan-type UsageData array{input_tokens?: int, output_tokens?: int}
 * @phpstan-type ResponseData array{
 *     id?: string,
 *     model?: string,
 *     content?: list<ContentBlock>,
 *     stop_reason?: string,
 *     usage?: UsageData
 * }
 */
class AnthropicTextGenerationModel extends AbstractApiBasedModel implements TextGenerationModelInterface
{
    /**
     * Beta header value for structured outputs feature.
     *
     * @var string
     */
    private const ANTHROPIC_BETA_STRUCTURED_OUTPUTS = 'structured-outputs-2025-11-13';

    /**
     * Default max tokens when not specified in config.
     *
     * Anthropic requires max_tokens to be specified, unlike OpenAI.
     *
     * @var int
     */
    private const DEFAULT_MAX_TOKENS = 4096;

    /**
     * {@inheritDoc}
     *
     * @since n.e.x.t
     */
    public function getRequestAuthentication(): RequestAuthenticationInterface
    {
        $requestAuthentication = parent::getRequestAuthentication();
        if (!$requestAuthentication instanceof ApiKeyRequestAuthentication) {
            return $requestAuthentication;
        }
        return new AnthropicApiKeyRequestAuthentication($requestAuthentication->getApiKey());
    }

    /**
     * {@inheritDoc}
     *
     * @since 0.1.0
     */
    public function generateTextResult(array $prompt): GenerativeAiResult
    {
        $httpTransporter = $this->getHttpTransporter();

        $params = $this->prepareGenerateTextParams($prompt);
        $headers = $this->prepareRequestHeaders();

        $request = $this->createRequest(
            HttpMethodEnum::POST(),
            'messages',
            $headers,
            $params
        );

        // Add authentication credentials to the request.
        $request = $this->getRequestAuthentication()->authenticateRequest($request);

        // Send and process the request.
        $response = $httpTransporter->send($request);
        $this->throwIfNotSuccessful($response);
        return $this->parseResponseToGenerativeAiResult($response);
    }

    /**
     * {@inheritDoc}
     *
     * @since 0.1.0
     */
    public function streamGenerateTextResult(array $prompt): Generator
    {
        throw new RuntimeException(
            'Streaming is not yet implemented for Anthropic provider.'
        );
    }

    /**
     * Prepares the request headers for the Anthropic API.
     *
     * @since n.e.x.t
     *
     * @return array<string, string> The request headers.
     */
    private function prepareRequestHeaders(): array
    {
        $headers = [
            'Content-Type' => 'application/json',
        ];

        // Add beta header for structured outputs if JSON output is requested.
        $config = $this->getConfig();
        if ('application/json' === $config->getOutputMimeType()) {
            $headers['anthropic-beta'] = self::ANTHROPIC_BETA_STRUCTURED_OUTPUTS;
        }

        return $headers;
    }

    /**
     * Prepares the request parameters for the Anthropic Messages API.
     *
     * @since n.e.x.t
     *
     * @param list<Message> $prompt The prompt messages.
     * @return array<string, mixed> The API request parameters.
     */
    private function prepareGenerateTextParams(array $prompt): array
    {
        $config = $this->getConfig();

        $this->validateUnsupportedParams();

        $params = [
            'model' => $this->metadata()->getId(),
            'messages' => $this->prepareMessagesParam($prompt),
            'max_tokens' => $config->getMaxTokens() ?? self::DEFAULT_MAX_TOKENS,
        ];

        $systemInstruction = $config->getSystemInstruction();
        if ($systemInstruction !== null) {
            $params['system'] = $systemInstruction;
        }

        $temperature = $config->getTemperature();
        if ($temperature !== null) {
            $params['temperature'] = $temperature;
        }

        $topP = $config->getTopP();
        if ($topP !== null) {
            $params['top_p'] = $topP;
        }

        $topK = $config->getTopK();
        if ($topK !== null) {
            $params['top_k'] = $topK;
        }

        $stopSequences = $config->getStopSequences();
        if (is_array($stopSequences)) {
            $params['stop_sequences'] = $stopSequences;
        }

        $functionDeclarations = $config->getFunctionDeclarations();
        if (is_array($functionDeclarations)) {
            $params['tools'] = $this->prepareToolsParam($functionDeclarations);
        }

        $outputMimeType = $config->getOutputMimeType();
        if ('application/json' === $outputMimeType) {
            $params['output_format'] = $this->prepareOutputFormatParam($config->getOutputSchema());
        }

        $customOptions = $config->getCustomOptions();
        foreach ($customOptions as $key => $value) {
            if (isset($params[$key])) {
                throw new InvalidArgumentException(
                    sprintf('The custom option "%s" conflicts with an existing parameter.', $key)
                );
            }
            $params[$key] = $value;
        }

        return $params;
    }

    /**
     * Validates that no unsupported parameters are used.
     *
     * @since n.e.x.t
     *
     * @throws InvalidArgumentException If an unsupported parameter is used.
     */
    private function validateUnsupportedParams(): void
    {
        $config = $this->getConfig();

        $candidateCount = $config->getCandidateCount();
        if ($candidateCount !== null && $candidateCount > 1) {
            throw new InvalidArgumentException(
                'Unsupported parameter "candidateCount" for Anthropic provider. '
                . 'Anthropic does not support generating multiple candidates.'
            );
        }

        if ($config->getPresencePenalty() !== null) {
            throw new InvalidArgumentException(
                'Unsupported parameter "presencePenalty" for Anthropic provider.'
            );
        }

        if ($config->getFrequencyPenalty() !== null) {
            throw new InvalidArgumentException(
                'Unsupported parameter "frequencyPenalty" for Anthropic provider.'
            );
        }

        if ($config->getLogprobs() !== null) {
            throw new InvalidArgumentException(
                'Unsupported parameter "logprobs" for Anthropic provider.'
            );
        }
    }

    /**
     * Prepares the messages parameter for the Anthropic API.
     *
     * @since n.e.x.t
     *
     * @param list<Message> $messages The messages to prepare.
     * @return list<array<string, mixed>> The prepared messages.
     */
    private function prepareMessagesParam(array $messages): array
    {
        $result = [];

        foreach ($messages as $message) {
            $role = $message->getRole();

            $content = [];
            foreach ($message->getParts() as $part) {
                $content[] = $this->getMessagePartContentData($part);
            }

            if (!empty($content)) {
                $result[] = [
                    'role' => $this->getMessageRoleString($role),
                    'content' => $content,
                ];
            }
        }

        return $result;
    }

    /**
     * Gets the Anthropic API role string for a message role.
     *
     * @since n.e.x.t
     *
     * @param MessageRoleEnum $role The message role.
     * @return string The API role string.
     */
    private function getMessageRoleString(MessageRoleEnum $role): string
    {
        return $role->isModel() ? 'assistant' : 'user';
    }

    /**
     * Gets the content data for a message part.
     *
     * @since n.e.x.t
     *
     * @param MessagePart $part The message part.
     * @return array<string, mixed> The content data.
     * @throws InvalidArgumentException If the message part type is unsupported.
     */
    private function getMessagePartContentData(MessagePart $part): array
    {
        $type = $part->getType();

        if ($type->isText()) {
            return [
                'type' => 'text',
                'text' => $part->getText(),
            ];
        }

        if ($type->isFile()) {
            $file = $part->getFile();
            if (!$file) {
                throw new RuntimeException(
                    'The file typed message part must contain a file.'
                );
            }
            return $this->prepareImageContent($file);
        }

        if ($type->isFunctionCall()) {
            $functionCall = $part->getFunctionCall();
            if (!$functionCall) {
                throw new RuntimeException(
                    'The function call typed message part must contain a function call.'
                );
            }
            return $this->prepareToolUseContent($functionCall);
        }

        if ($type->isFunctionResponse()) {
            $functionResponse = $part->getFunctionResponse();
            if (!$functionResponse) {
                throw new RuntimeException(
                    'The function response typed message part must contain a function response.'
                );
            }
            return $this->prepareToolResultContent($functionResponse);
        }

        throw new InvalidArgumentException(
            sprintf('Unsupported message part type "%s".', $type)
        );
    }

    /**
     * Prepares image content for the Anthropic API.
     *
     * @since n.e.x.t
     *
     * @param File $file The image file.
     * @return array<string, mixed> The image content block.
     * @throws InvalidArgumentException If the file is not an image.
     */
    private function prepareImageContent(File $file): array
    {
        if (!$file->isImage()) {
            throw new InvalidArgumentException(
                sprintf(
                    'Unsupported file MIME type "%s" for Anthropic provider. Only images are supported.',
                    $file->getMimeType()
                )
            );
        }

        if ($file->isRemote()) {
            return [
                'type' => 'image',
                'source' => [
                    'type' => 'url',
                    'url' => $file->getUrl(),
                ],
            ];
        }

        return [
            'type' => 'image',
            'source' => [
                'type' => 'base64',
                'media_type' => $file->getMimeType(),
                'data' => $file->getBase64Data(),
            ],
        ];
    }

    /**
     * Prepares tool use content for assistant messages.
     *
     * @since n.e.x.t
     *
     * @param FunctionCall $functionCall The function call.
     * @return array<string, mixed> The tool use content block.
     */
    private function prepareToolUseContent(FunctionCall $functionCall): array
    {
        return [
            'type' => 'tool_use',
            'id' => $functionCall->getId(),
            'name' => $functionCall->getName(),
            'input' => $functionCall->getArgs() ?? new \stdClass(),
        ];
    }

    /**
     * Prepares tool result content for user messages.
     *
     * @since n.e.x.t
     *
     * @param FunctionResponse $functionResponse The function response.
     * @return array<string, mixed> The tool result content block.
     */
    private function prepareToolResultContent(FunctionResponse $functionResponse): array
    {
        return [
            'type' => 'tool_result',
            'tool_use_id' => $functionResponse->getId(),
            'content' => json_encode($functionResponse->getResponse()),
        ];
    }

    /**
     * Prepares the tools parameter for the Anthropic API.
     *
     * @since n.e.x.t
     *
     * @param list<FunctionDeclaration> $functionDeclarations The function declarations.
     * @return list<array<string, mixed>> The prepared tools.
     */
    private function prepareToolsParam(array $functionDeclarations): array
    {
        $tools = [];
        foreach ($functionDeclarations as $declaration) {
            $tool = [
                'name' => $declaration->getName(),
                'description' => $declaration->getDescription(),
            ];

            $parameters = $declaration->getParameters();
            if ($parameters !== null) {
                $tool['input_schema'] = $parameters;
            }

            $tools[] = $tool;
        }

        return $tools;
    }

    /**
     * Prepares the output format parameter for structured outputs.
     *
     * @since n.e.x.t
     *
     * @param array<string, mixed>|null $outputSchema The output schema.
     * @return array<string, mixed> The output format parameter.
     */
    private function prepareOutputFormatParam(?array $outputSchema): array
    {
        if (is_array($outputSchema)) {
            /*
             * TODO: Consider validating that all object types in the schema have
             * "additionalProperties": false, which is required by Anthropic's structured
             * outputs API. Currently, if this is missing, the API will return an error.
             * Validation here could provide a better developer experience with clearer
             * error messages, but the library doesn't currently do JSON schema validation
             * elsewhere, so this decision should be made holistically.
             */
            return [
                'type' => 'json_schema',
                'schema' => $outputSchema,
            ];
        }

        return ['type' => 'json'];
    }

    /**
     * Creates a request object for the Anthropic API.
     *
     * @since n.e.x.t
     *
     * @param HttpMethodEnum $method The HTTP method.
     * @param string $path The API endpoint path.
     * @param array<string, string|list<string>> $headers The request headers.
     * @param string|array<string, mixed>|null $data The request data.
     * @return Request The request object.
     */
    private function createRequest(
        HttpMethodEnum $method,
        string $path,
        array $headers = [],
        $data = null
    ): Request {
        return new Request(
            $method,
            AnthropicProvider::url($path),
            $headers,
            $data,
            $this->getRequestOptions()
        );
    }

    /**
     * Throws an exception if the response is not successful.
     *
     * @since n.e.x.t
     *
     * @param Response $response The HTTP response.
     * @throws ResponseException If the response indicates an error.
     */
    private function throwIfNotSuccessful(Response $response): void
    {
        ResponseUtil::throwIfNotSuccessful($response);
    }

    /**
     * Parses the Anthropic API response to a GenerativeAiResult.
     *
     * @since n.e.x.t
     *
     * @param Response $response The API response.
     * @return GenerativeAiResult The parsed result.
     * @throws ResponseException If the response data is invalid.
     */
    private function parseResponseToGenerativeAiResult(Response $response): GenerativeAiResult
    {
        /** @var ResponseData $data */
        $data = $response->getData();

        $contentBlocks = $data['content'] ?? [];
        $parts = [];
        foreach ($contentBlocks as $block) {
            $part = $this->parseContentBlockToMessagePart($block);
            if ($part !== null) {
                $parts[] = $part;
            }
        }

        $message = new Message(MessageRoleEnum::model(), $parts);
        $finishReason = $this->mapStopReasonToFinishReason($data['stop_reason'] ?? 'end_turn');
        $candidate = new Candidate($message, $finishReason);

        $usage = $data['usage'] ?? [];
        $inputTokens = $usage['input_tokens'] ?? 0;
        $outputTokens = $usage['output_tokens'] ?? 0;
        $tokenUsage = new TokenUsage(
            $inputTokens,
            $outputTokens,
            $inputTokens + $outputTokens
        );

        $id = isset($data['id']) && is_string($data['id']) ? $data['id'] : '';

        // Include additional response data.
        $additionalData = [];
        if (isset($data['model'])) {
            $additionalData['model'] = $data['model'];
        }

        return new GenerativeAiResult(
            $id,
            [$candidate],
            $tokenUsage,
            $this->providerMetadata(),
            $this->metadata(),
            $additionalData
        );
    }

    /**
     * Parses a content block from the response to a MessagePart.
     *
     * @since n.e.x.t
     *
     * @param ContentBlock $block The content block.
     * @return MessagePart|null The parsed message part, or null if unsupported.
     */
    private function parseContentBlockToMessagePart(array $block): ?MessagePart
    {
        $type = $block['type'] ?? '';

        switch ($type) {
            case 'text':
                return new MessagePart($block['text'] ?? '');

            case 'tool_use':
                $functionCall = new FunctionCall(
                    isset($block['id']) && is_string($block['id']) ? $block['id'] : null,
                    isset($block['name']) && is_string($block['name']) ? $block['name'] : null,
                    $block['input'] ?? []
                );
                return new MessagePart($functionCall);

            default:
                return null;
        }
    }

    /**
     * Maps Anthropic's stop_reason to FinishReasonEnum.
     *
     * @since n.e.x.t
     *
     * @param string $stopReason The Anthropic stop reason.
     * @return FinishReasonEnum The mapped finish reason.
     */
    private function mapStopReasonToFinishReason(string $stopReason): FinishReasonEnum
    {
        switch ($stopReason) {
            case 'end_turn':
            case 'pause_turn':
            case 'stop_sequence':
                return FinishReasonEnum::stop();

            case 'max_tokens':
            case 'model_context_window_exceeded':
                return FinishReasonEnum::length();

            case 'tool_use':
                return FinishReasonEnum::toolCalls();

            case 'refusal':
                return FinishReasonEnum::contentFilter();

            default:
                /*
                 * TODO: Return FinishReasonEnum::unknown() when available in the enum.
                 * The Vercel AI SDK returns 'unknown' for unrecognized stop reasons.
                 * Anthropic may add new stop reasons in the future, and returning
                 * 'unknown' would allow consumers to handle unrecognized values
                 * explicitly rather than assuming 'stop'.
                 */
                return FinishReasonEnum::stop();
        }
    }
}
