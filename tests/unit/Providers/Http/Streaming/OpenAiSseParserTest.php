<?php

declare(strict_types=1);

namespace WordPress\AiClient\Tests\unit\Providers\Http\Streaming;

use Generator;
use PHPUnit\Framework\TestCase;
use WordPress\AiClient\Providers\Http\Streaming\OpenAiSseParser;

/**
 * @covers \WordPress\AiClient\Providers\Http\Streaming\OpenAiSseParser
 */
class OpenAiSseParserTest extends TestCase
{
    public function testParsesContentDeltas(): void
    {
        $parser = new OpenAiSseParser();
        $stream = $this->createStream([
            "data: {\"choices\":[{\"delta\":{\"content\":\"Hello\"}}]}\n\n",
            "data: {\"choices\":[{\"delta\":{\"content\":\" world\"}}]}\n\n",
            "data: [DONE]\n\n",
        ]);

        $results = iterator_to_array($parser->parse($stream));

        self::assertCount(2, $results);
        self::assertSame('Hello', $results[0]['choices'][0]['delta']['content']);
        self::assertSame(' world', $results[1]['choices'][0]['delta']['content']);
    }

    public function testParsesToolCalls(): void
    {
        $parser = new OpenAiSseParser();
        $stream = $this->createStream([
            "data: {\"choices\":[{\"delta\":{\"tool_calls\":[{\"index\":0,\"id\":\"call_123\","
            . "\"function\":{\"name\":\"get_weather\",\"arguments\":\"\"}}]}}]}\n\n",
            "data: {\"choices\":[{\"delta\":{\"tool_calls\":[{\"index\":0,\"function\":"
            . "{\"arguments\":\"{\\\"loc\"}}]}}]}\n\n",
            "data: {\"choices\":[{\"delta\":{\"tool_calls\":[{\"index\":0,\"function\":"
            . "{\"arguments\":\"ation\\\":\\\"NYC\\\"}\"}}]}}]}\n\n",
            "data: [DONE]\n\n",
        ]);

        $results = iterator_to_array($parser->parse($stream));

        self::assertCount(3, $results);
        self::assertSame('call_123', $results[0]['choices'][0]['delta']['tool_calls'][0]['id']);
        self::assertSame('get_weather', $results[0]['choices'][0]['delta']['tool_calls'][0]['function']['name']);
        self::assertSame('{"loc', $results[1]['choices'][0]['delta']['tool_calls'][0]['function']['arguments']);
    }

    public function testHandlesChunkedData(): void
    {
        $parser = new OpenAiSseParser();
        $stream = $this->createStream([
            "data: {\"choices\":[{\"del",
            "ta\":{\"content\":\"test\"}}]}\n\n",
        ]);

        $results = iterator_to_array($parser->parse($stream));

        self::assertCount(1, $results);
        self::assertSame('test', $results[0]['choices'][0]['delta']['content']);
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
