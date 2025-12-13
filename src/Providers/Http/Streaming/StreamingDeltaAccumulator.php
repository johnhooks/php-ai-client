<?php

declare(strict_types=1);

namespace WordPress\AiClient\Providers\Http\Streaming;

use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessagePartChannelEnum;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Tools\DTO\FunctionCall;

/**
 * Accumulates streaming delta chunks into a complete response.
 *
 * @since n.e.x.t
 *
 * @phpstan-type ToolCallAccumulator array{
 *     id: string|null,
 *     type: string,
 *     function: array{name: string|null, arguments: string}
 * }
 * @phpstan-type ChoiceAccumulator array{
 *     content: string,
 *     reasoning_content: string|null,
 *     role: string,
 *     finish_reason: string|null,
 *     tool_calls: array<int, ToolCallAccumulator>
 * }
 */
class StreamingDeltaAccumulator
{
    /**
     * The unique identifier for the response.
     *
     * @since n.e.x.t
     *
     * @var string
     */
    private string $id = '';

    /**
     * The model identifier from the response.
     *
     * @since n.e.x.t
     *
     * @var string
     */
    private string $model = '';

    /**
     * Accumulated choice data indexed by choice position.
     *
     * @since n.e.x.t
     *
     * @var array<int, ChoiceAccumulator>
     */
    private array $choices = [];

    /**
     * Token usage statistics accumulated from the stream.
     *
     * @since n.e.x.t
     *
     * @var array{prompt_tokens: int, completion_tokens: int, total_tokens: int}
     */
    private array $usage = [
        'prompt_tokens' => 0,
        'completion_tokens' => 0,
        'total_tokens' => 0,
    ];

    /**
     * The most recent content delta received.
     *
     * @since n.e.x.t
     *
     * @var string|null
     */
    private ?string $lastDelta = null;

    /**
     * Adds a parsed streaming chunk to the accumulated state.
     *
     * @since n.e.x.t
     *
     * @param array<string, mixed> $chunk The parsed SSE event data to accumulate.
     */
    public function addChunk(array $chunk): void
    {
        $this->lastDelta = null;

        if (isset($chunk['id']) && is_string($chunk['id'])) {
            $this->id = $chunk['id'];
        }

        if (isset($chunk['model']) && is_string($chunk['model'])) {
            $this->model = $chunk['model'];
        }

        if (isset($chunk['choices']) && is_array($chunk['choices'])) {
            foreach ($chunk['choices'] as $choice) {
                if (!is_array($choice)) {
                    continue;
                }

                /** @var array<string, mixed> $choice */

                $index = isset($choice['index']) && is_numeric($choice['index']) ? (int) $choice['index'] : 0;
                $this->ensureChoiceExists($index);

                $delta = isset($choice['delta']) && is_array($choice['delta']) ? $choice['delta'] : [];

                if (isset($delta['content']) && is_string($delta['content'])) {
                    $this->choices[$index]['content'] .= $delta['content'];
                    $this->lastDelta = $delta['content'];
                }

                if (isset($delta['reasoning_content']) && is_string($delta['reasoning_content'])) {
                    if ($this->choices[$index]['reasoning_content'] === null) {
                        $this->choices[$index]['reasoning_content'] = '';
                    }
                    $this->choices[$index]['reasoning_content'] .= $delta['reasoning_content'];
                }

                if (isset($delta['role']) && is_string($delta['role'])) {
                    $this->choices[$index]['role'] = $delta['role'];
                }

                if (isset($delta['tool_calls']) && is_array($delta['tool_calls'])) {
                    $toolCallDeltas = array_values(array_filter(
                        $delta['tool_calls'],
                        static fn($item): bool => is_array($item)
                    ));

                    /** @var array<int, array<string, mixed>> $toolCallDeltas */
                    $this->accumulateToolCalls($index, $toolCallDeltas);
                }

                if (isset($choice['finish_reason']) && is_string($choice['finish_reason'])) {
                    $this->choices[$index]['finish_reason'] = $choice['finish_reason'];
                }
            }
        }

        if (isset($chunk['usage']) && is_array($chunk['usage'])) {
            $this->usage = [
                'prompt_tokens' => isset($chunk['usage']['prompt_tokens'])
                    && is_numeric($chunk['usage']['prompt_tokens'])
                    ? (int) $chunk['usage']['prompt_tokens']
                    : $this->usage['prompt_tokens'],
                'completion_tokens' => isset($chunk['usage']['completion_tokens'])
                    && is_numeric($chunk['usage']['completion_tokens'])
                    ? (int) $chunk['usage']['completion_tokens']
                    : $this->usage['completion_tokens'],
                'total_tokens' => isset($chunk['usage']['total_tokens'])
                    && is_numeric($chunk['usage']['total_tokens'])
                    ? (int) $chunk['usage']['total_tokens']
                    : $this->usage['total_tokens'],
            ];
        }
    }

    /**
     * Returns the response identifier.
     *
     * @since n.e.x.t
     *
     * @return string The unique response ID from the API.
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * Returns the model identifier.
     *
     * @since n.e.x.t
     *
     * @return string The model ID that generated the response.
     */
    public function getModel(): string
    {
        return $this->model;
    }

    /**
     * Returns the accumulated content for a specific choice.
     *
     * @since n.e.x.t
     *
     * @param int $index The zero-based choice index.
     * @return string The accumulated text content, or empty string if not available.
     */
    public function getContent(int $index = 0): string
    {
        return isset($this->choices[$index]['content']) ? $this->choices[$index]['content'] : '';
    }

    /**
     * Returns the accumulated reasoning content for a specific choice.
     *
     * @since n.e.x.t
     *
     * @param int $index The zero-based choice index.
     * @return string|null The accumulated reasoning content, or null if not present.
     */
    public function getReasoningContent(int $index = 0): ?string
    {
        return isset($this->choices[$index]['reasoning_content']) ? $this->choices[$index]['reasoning_content'] : null;
    }

    /**
     * Returns the accumulated tool calls for a specific choice.
     *
     * @since n.e.x.t
     *
     * @param int $index The zero-based choice index.
     * @return array<int, ToolCallAccumulator> The accumulated tool call data.
     */
    public function getToolCalls(int $index = 0): array
    {
        return isset($this->choices[$index]['tool_calls']) ? $this->choices[$index]['tool_calls'] : [];
    }

    /**
     * Returns the most recent content delta.
     *
     * @since n.e.x.t
     *
     * @return string|null The last delta content received, or null if none.
     */
    public function getLastDelta(): ?string
    {
        return $this->lastDelta;
    }

    /**
     * Returns the accumulated token usage statistics.
     *
     * @since n.e.x.t
     *
     * @return array{prompt_tokens: int, completion_tokens: int, total_tokens: int} The token counts.
     */
    public function getUsage(): array
    {
        return $this->usage;
    }

    /**
     * Returns the finish reason for a specific choice.
     *
     * @since n.e.x.t
     *
     * @param int $index The zero-based choice index.
     * @return string|null The finish reason, or null if streaming is not complete.
     */
    public function getFinishReason(int $index = 0): ?string
    {
        return isset($this->choices[$index]['finish_reason']) ? $this->choices[$index]['finish_reason'] : null;
    }

    /**
     * Checks whether all choices have finished streaming.
     *
     * @since n.e.x.t
     *
     * @return bool True if all choices have a finish reason, false otherwise.
     */
    public function isComplete(): bool
    {
        if (empty($this->choices)) {
            return false;
        }

        foreach ($this->choices as $choice) {
            if (!isset($choice['finish_reason']) || !is_string($choice['finish_reason'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Returns the number of accumulated choices.
     *
     * @since n.e.x.t
     *
     * @return int The count of choices in the response.
     */
    public function getChoiceCount(): int
    {
        return count($this->choices);
    }

    /**
     * Converts the accumulated choice data into a Message object.
     *
     * @since n.e.x.t
     *
     * @param int $index The zero-based choice index.
     * @return Message The message containing accumulated content and tool calls.
     */
    public function toMessage(int $index = 0): Message
    {
        if (!isset($this->choices[$index])) {
            return new Message(MessageRoleEnum::model(), [new MessagePart('')]);
        }

        $choice = $this->choices[$index];
        $parts = [];

        if ($choice['reasoning_content'] !== null && $choice['reasoning_content'] !== '') {
            $parts[] = new MessagePart($choice['reasoning_content'], MessagePartChannelEnum::thought());
        }

        if ($choice['content'] !== '') {
            $parts[] = new MessagePart($choice['content']);
        }

        foreach ($choice['tool_calls'] as $toolCall) {
            if ($toolCall['type'] === 'function' && $toolCall['function']['name'] !== null) {
                $decodedArguments = json_decode($toolCall['function']['arguments'], true);
                if (!is_array($decodedArguments)) {
                    $decodedArguments = [];
                }

                $functionCall = new FunctionCall(
                    $toolCall['id'],
                    $toolCall['function']['name'],
                    $decodedArguments
                );

                $parts[] = new MessagePart($functionCall);
            }
        }

        if (empty($parts)) {
            $parts[] = new MessagePart('');
        }

        $role = $choice['role'] === 'user' ? MessageRoleEnum::user() : MessageRoleEnum::model();

        return new Message($role, $parts);
    }

    /**
     * Resets all accumulated state to initial values.
     *
     * @since n.e.x.t
     */
    public function reset(): void
    {
        $this->id = '';
        $this->model = '';
        $this->choices = [];
        $this->usage = [
            'prompt_tokens' => 0,
            'completion_tokens' => 0,
            'total_tokens' => 0,
        ];
        $this->lastDelta = null;
    }

    /**
     * Ensures a choice entry exists at the specified index.
     *
     * @since n.e.x.t
     *
     * @param int $index The zero-based choice index to ensure exists.
     */
    private function ensureChoiceExists(int $index): void
    {
        if (!isset($this->choices[$index])) {
            $this->choices[$index] = [
                'content' => '',
                'reasoning_content' => null,
                'role' => 'assistant',
                'finish_reason' => null,
                'tool_calls' => [],
            ];
        }
    }

    /**
     * Accumulates tool call deltas into the choice data.
     *
     * @since n.e.x.t
     *
     * @param int $choiceIndex The zero-based choice index.
     * @param array<int, array<string, mixed>> $toolCallDeltas The tool call delta data to accumulate.
     */
    private function accumulateToolCalls(int $choiceIndex, array $toolCallDeltas): void
    {
        foreach ($toolCallDeltas as $delta) {
            if (!is_array($delta)) {
                continue;
            }

            $toolIndex = isset($delta['index']) && is_numeric($delta['index']) ? (int) $delta['index'] : 0;

            if (!isset($this->choices[$choiceIndex]['tool_calls'][$toolIndex])) {
                $this->choices[$choiceIndex]['tool_calls'][$toolIndex] = [
                    'id' => null,
                    'type' => 'function',
                    'function' => [
                        'name' => null,
                        'arguments' => '',
                    ],
                ];
            }

            $toolCall = &$this->choices[$choiceIndex]['tool_calls'][$toolIndex];

            if (isset($delta['id']) && is_string($delta['id'])) {
                $toolCall['id'] = $delta['id'];
            }

            if (isset($delta['type']) && is_string($delta['type'])) {
                $toolCall['type'] = $delta['type'];
            }

            if (isset($delta['function']) && is_array($delta['function'])) {
                if (isset($delta['function']['name']) && is_string($delta['function']['name'])) {
                    $toolCall['function']['name'] = $delta['function']['name'];
                }
                if (isset($delta['function']['arguments']) && is_string($delta['function']['arguments'])) {
                    $toolCall['function']['arguments'] .= $delta['function']['arguments'];
                }
            }
        }
    }
}
