<?php

declare(strict_types=1);

namespace WordPress\AiClient\Providers\Http\Streaming\Contracts;

use Generator;

/**
 * Interface for Server-Sent Events parsers.
 *
 * @since n.e.x.t
 */
interface SseParserInterface
{
    /**
     * Parses raw HTTP chunks into SSE event payloads.
     *
     * @since n.e.x.t
     *
     * @param Generator<int, string, mixed, void> $stream Generator yielding raw HTTP chunks.
     * @return Generator<int, array<string, mixed>, mixed, void> Generator yielding parsed event data.
     */
    public function parse(Generator $stream): Generator;

    /**
     * Resets any internal parser state.
     *
     * @since n.e.x.t
     */
    public function reset(): void;
}
