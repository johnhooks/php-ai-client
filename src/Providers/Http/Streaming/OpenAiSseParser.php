<?php

declare(strict_types=1);

namespace WordPress\AiClient\Providers\Http\Streaming;

use Generator;
use WordPress\AiClient\Common\Exception\RuntimeException;
use WordPress\AiClient\Providers\Http\Streaming\Contracts\SseParserInterface;

/**
 * SSE parser for OpenAI-compatible streaming responses.
 *
 * Handles SSE streams that emit only `data:` lines terminated by `data: [DONE]`.
 *
 * @since n.e.x.t
 */
class OpenAiSseParser implements SseParserInterface
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
     * Parses a single SSE event into structured data.
     *
     * @since n.e.x.t
     *
     * @param string $event The raw SSE event string to parse.
     * @return array<string, mixed>|null The parsed JSON data, or null if no data payload is present.
     */
    private function parseEvent(string $event): ?array
    {
        $lines = explode("\n", $event);

        foreach ($lines as $line) {
            $line = trim($line);

            if (strpos($line, 'data:') === 0) {
                $data = trim(substr($line, 5));

                if ($data === '[DONE]') {
                    return null;
                }

                $decoded = json_decode($data, true);
                if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
                    throw new RuntimeException(
                        sprintf('Malformed SSE data: %s', json_last_error_msg())
                    );
                }

                /** @var array<string, mixed> $decoded */
                return $decoded;
            }
        }

        return null;
    }
}
