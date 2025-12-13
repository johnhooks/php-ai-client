<?php

declare(strict_types=1);

namespace WordPress\AiClient\Tests\unit\Providers\Http\Streaming;

use Generator;
use PHPUnit\Framework\TestCase;
use WordPress\AiClient\Providers\Http\Streaming\AnthropicSseParser;

/**
 * @covers \WordPress\AiClient\Providers\Http\Streaming\AnthropicSseParser
 */
class AnthropicSseParserTest extends TestCase
{
    public function testParsesContentBlockDelta(): void
    {
        $parser = new AnthropicSseParser();
        $stream = $this->createStream([
            "event: content_block_delta\n",
            "data: {\"delta\":{\"text\":\"Hello\"}}\n\n",
        ]);

        $results = iterator_to_array($parser->parse($stream));

        self::assertCount(1, $results);
        self::assertSame('Hello', $results[0]['choices'][0]['delta']['content']);
    }

    public function testParsesMessageDeltaUsageAndFinishReason(): void
    {
        $parser = new AnthropicSseParser();
        $stream = $this->createStream([
            "event: message_delta\n",
            "data: {\"usage\":{\"input_tokens\":10,\"output_tokens\":5},\"delta\":{\"stop_reason\":\"end_turn\"}}\n\n",
        ]);

        $results = iterator_to_array($parser->parse($stream));

        self::assertCount(1, $results);
        self::assertSame(10, $results[0]['usage']['prompt_tokens']);
        self::assertSame(5, $results[0]['usage']['completion_tokens']);
        self::assertSame(15, $results[0]['usage']['total_tokens']);
        self::assertSame('stop', $results[0]['choices'][0]['finish_reason']);
    }

    public function testIgnoresStopEvent(): void
    {
        $parser = new AnthropicSseParser();
        $stream = $this->createStream([
            "event: message_stop\n",
            "data: {}\n\n",
        ]);

        $results = iterator_to_array($parser->parse($stream));

        self::assertCount(0, $results);
    }

    /**
     * @param array<int, string> $chunks
     * @return Generator<int, string, mixed, void>
     */
    private function createStream(array $chunks): Generator
    {
        foreach ($chunks as $chunk) {
            yield $chunk;
        }
    }
}
