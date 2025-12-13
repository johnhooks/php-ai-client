<?php

declare(strict_types=1);

namespace WordPress\AiClient\Providers\Http\Streaming;

use Generator;
use WordPress\AiClient\Common\Exception\RuntimeException;
use WordPress\AiClient\Providers\Http\Streaming\Contracts\SseParserInterface;

/**
 * SSE parser for Anthropic streaming responses.
 *
 * Normalizes Anthropic events into an OpenAI-compatible structure.
 *
 * @since n.e.x.t
 */
class AnthropicSseParser implements SseParserInterface
{
    /**
     * @var string Buffer for incomplete SSE data.
     */
    private string $buffer = '';

    /**
     * {@inheritDoc}
     */
    public function parse(Generator $stream): Generator
    {
        foreach ($stream as $chunk) {
            $this->buffer .= $chunk;

            while (true) {
                $position = strpos($this->buffer, "\n\n");
                $delimiterLength = 2;
                $altPosition = strpos($this->buffer, "\r\n\r\n");

                if ($altPosition !== false && ($position === false || $altPosition < $position)) {
                    $position = $altPosition;
                    $delimiterLength = 4;
                }

                if ($position === false) {
                    break;
                }

                $event = substr($this->buffer, 0, $position);
                $this->buffer = substr($this->buffer, $position + $delimiterLength);

                $parsed = $this->parseEvent($event);
                if ($parsed !== null) {
                    yield $parsed;
                }
            }
        }

        $trimmed = trim($this->buffer);
        if ($trimmed !== '') {
            $parsed = $this->parseEvent($this->buffer);
            if ($parsed !== null) {
                yield $parsed;
            }
        }
    }

    /**
     * {@inheritDoc}
     */
    public function reset(): void
    {
        $this->buffer = '';
    }

    /**
     * Parses and normalizes a single Anthropic SSE event.
     *
     * @since n.e.x.t
     *
     * @param string $event The raw SSE event string to parse.
     * @return array<string, mixed>|null The normalized OpenAI-compatible data, or null if not applicable.
     */
    private function parseEvent(string $event): ?array
    {
        $eventType = null;
        $eventData = null;

        foreach (explode("\n", $event) as $line) {
            $line = trim($line);

            if (strpos($line, 'event:') === 0) {
                $eventType = trim(substr($line, 6));
            } elseif (strpos($line, 'data:') === 0) {
                $eventData = trim(substr($line, 5));
            }
        }

        if ($eventType === 'message_stop') {
            return null;
        }

        if ($eventData === null) {
            return null;
        }

        $decoded = json_decode($eventData, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            throw new RuntimeException(
                sprintf('Malformed Anthropic SSE data: %s', json_last_error_msg())
            );
        }

        /** @var array<string, mixed> $decoded */

        if ($eventType === null) {
            return null;
        }

        if ($eventType === 'content_block_delta' || $eventType === 'message_delta') {
            return $this->normalizeToOpenAiFormat($eventType, $decoded);
        }

        return null;
    }

    /**
     * Normalizes Anthropic events into an OpenAI-compatible structure.
     *
     * @since n.e.x.t
     *
     * @param string $eventType The Anthropic event type (e.g., 'content_block_delta').
     * @param array<string, mixed> $data The parsed event data from the Anthropic API.
     * @return array<string, mixed> The normalized payload in OpenAI-compatible format.
     */
    private function normalizeToOpenAiFormat(string $eventType, array $data): array
    {
        if ($eventType === 'content_block_delta') {
            $text = '';
            if (
                isset($data['delta'])
                && is_array($data['delta'])
                && isset($data['delta']['text'])
                && is_string($data['delta']['text'])
            ) {
                $text = $data['delta']['text'];
            }

            return [
                'choices' => [
                    [
                        'index' => 0,
                        'delta' => [
                            'content' => $text,
                        ],
                    ],
                ],
            ];
        }

        if ($eventType === 'message_delta') {
            $normalized = [];

            if (isset($data['usage']) && is_array($data['usage'])) {
                $inputTokens = (isset($data['usage']['input_tokens']) && is_numeric($data['usage']['input_tokens']))
                    ? (int) $data['usage']['input_tokens']
                    : 0;
                $outputTokens = (isset($data['usage']['output_tokens']) && is_numeric($data['usage']['output_tokens']))
                    ? (int) $data['usage']['output_tokens']
                    : 0;
                $normalized['usage'] = [
                    'prompt_tokens' => $inputTokens,
                    'completion_tokens' => $outputTokens,
                    'total_tokens' => $inputTokens + $outputTokens,
                ];
            }

            if (
                isset($data['delta'])
                && is_array($data['delta'])
                && isset($data['delta']['stop_reason'])
                && is_string($data['delta']['stop_reason'])
            ) {
                $normalized['choices'] = [
                    [
                        'index' => 0,
                        'finish_reason' => $this->mapStopReason($data['delta']['stop_reason']),
                    ],
                ];
            }

            return $normalized;
        }

        return $data;
    }

    /**
     * Maps Anthropic stop reasons to OpenAI-compatible finish reasons.
     *
     * @since n.e.x.t
     *
     * @param string $stopReason The stop reason returned by the Anthropic API.
     * @return string The corresponding OpenAI-compatible finish reason.
     */
    private function mapStopReason(string $stopReason): string
    {
        switch ($stopReason) {
            case 'end_turn':
                return 'stop';
            case 'max_tokens':
                return 'length';
            case 'tool_use':
                return 'tool_calls';
            default:
                return 'stop';
        }
    }
}
