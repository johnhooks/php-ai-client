<?php

declare(strict_types=1);

namespace WordPress\AiClient\Tests\unit\Results\DTO;

use PHPUnit\Framework\TestCase;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Http\Streaming\StreamingDeltaAccumulator;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Results\DTO\StreamingGenerativeAiResult;

/**
 * @covers \WordPress\AiClient\Results\DTO\StreamingGenerativeAiResult
 */
class StreamingGenerativeAiResultTest extends TestCase
{
    public function testExtendsGenerativeAiResult(): void
    {
        $accumulator = new StreamingDeltaAccumulator();
        $accumulator->addChunk(['id' => 'resp_123', 'model' => 'gpt-4']);
        $accumulator->addChunk(['choices' => [['delta' => ['content' => 'Hello']]]]);

        $providerMetadata = new ProviderMetadata('openai', 'OpenAI', ProviderTypeEnum::cloud());
        $modelMetadata = new ModelMetadata('gpt-4', 'GPT-4', [], []);

        $result = StreamingGenerativeAiResult::fromAccumulator(
            $accumulator,
            $providerMetadata,
            $modelMetadata
        );

        self::assertInstanceOf(GenerativeAiResult::class, $result);
    }

    public function testStreamingSpecificMethods(): void
    {
        $accumulator = new StreamingDeltaAccumulator();
        $accumulator->addChunk(['id' => 'resp_123', 'model' => 'gpt-4']);
        $accumulator->addChunk(['choices' => [['delta' => ['content' => 'Hello']]]]);

        $providerMetadata = new ProviderMetadata('openai', 'OpenAI', ProviderTypeEnum::cloud());
        $modelMetadata = new ModelMetadata('gpt-4', 'GPT-4', [], []);

        $result = StreamingGenerativeAiResult::fromAccumulator(
            $accumulator,
            $providerMetadata,
            $modelMetadata
        );

        self::assertSame('Hello', $result->getDelta());
        self::assertFalse($result->isComplete());
    }

    public function testCompleteResultHasUsageAndText(): void
    {
        $accumulator = new StreamingDeltaAccumulator();
        $accumulator->addChunk(['id' => 'resp_123', 'model' => 'gpt-4']);
        $accumulator->addChunk(['choices' => [['delta' => ['content' => 'Hello']]]]);
        $accumulator->addChunk(['choices' => [['finish_reason' => 'stop']]]);
        $accumulator->addChunk(['usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15]]);

        $providerMetadata = new ProviderMetadata('openai', 'OpenAI', ProviderTypeEnum::cloud());
        $modelMetadata = new ModelMetadata('gpt-4', 'GPT-4', [], []);

        $result = StreamingGenerativeAiResult::fromAccumulator(
            $accumulator,
            $providerMetadata,
            $modelMetadata
        );

        self::assertTrue($result->isComplete());
        self::assertSame('resp_123', $result->getId());
        self::assertSame('Hello', $result->toText());
        self::assertSame(15, $result->getTokenUsage()->getTotalTokens());
    }
}
