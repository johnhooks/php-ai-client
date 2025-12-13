<?php

declare(strict_types=1);

namespace WordPress\AiClient\Tests\unit\Providers\Http\Streaming;

use PHPUnit\Framework\TestCase;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Providers\Http\Streaming\StreamingDeltaAccumulator;

/**
 * @covers \WordPress\AiClient\Providers\Http\Streaming\StreamingDeltaAccumulator
 */
class StreamingDeltaAccumulatorTest extends TestCase
{
    public function testAccumulatesContentAndDelta(): void
    {
        $accumulator = new StreamingDeltaAccumulator();

        $accumulator->addChunk(['choices' => [['delta' => ['content' => 'Hello']]]]);
        $accumulator->addChunk(['choices' => [['delta' => ['content' => ' world']]]]);

        self::assertSame('Hello world', $accumulator->getContent());
        self::assertSame(' world', $accumulator->getLastDelta());
    }

    public function testAccumulatesToolCalls(): void
    {
        $accumulator = new StreamingDeltaAccumulator();

        $accumulator->addChunk([
            'choices' => [[
                'delta' => [
                    'tool_calls' => [[
                        'index' => 0,
                        'id' => 'call_123',
                        'function' => ['name' => 'get_weather', 'arguments' => ''],
                    ]],
                ],
            ]],
        ]);

        $accumulator->addChunk([
            'choices' => [[
                'delta' => [
                    'tool_calls' => [[
                        'index' => 0,
                        'function' => ['arguments' => '{"location":"NYC"}'],
                    ]],
                ],
            ]],
        ]);

        $toolCalls = $accumulator->getToolCalls();
        self::assertCount(1, $toolCalls);
        self::assertSame('call_123', $toolCalls[0]['id']);
        self::assertSame('get_weather', $toolCalls[0]['function']['name']);
        self::assertSame('{"location":"NYC"}', $toolCalls[0]['function']['arguments']);
    }

    public function testToMessageIncludesReasoningAndContent(): void
    {
        $accumulator = new StreamingDeltaAccumulator();

        $accumulator->addChunk([
            'choices' => [[
                'delta' => ['reasoning_content' => 'Let me think...'],
            ]],
        ]);
        $accumulator->addChunk([
            'choices' => [[
                'delta' => ['content' => 'The answer is 42.'],
            ]],
        ]);
        $accumulator->addChunk([
            'choices' => [[
                'finish_reason' => 'stop',
            ]],
        ]);

        $message = $accumulator->toMessage();

        self::assertSame(MessageRoleEnum::model(), $message->getRole());
        self::assertCount(2, $message->getParts());
    }

    public function testTracksMultipleChoices(): void
    {
        $accumulator = new StreamingDeltaAccumulator();

        $accumulator->addChunk(['choices' => [
            ['index' => 0, 'delta' => ['content' => 'First']],
            ['index' => 1, 'delta' => ['content' => 'Second']],
        ]]);

        self::assertSame('First', $accumulator->getContent(0));
        self::assertSame('Second', $accumulator->getContent(1));
        self::assertSame(2, $accumulator->getChoiceCount());
    }
}
