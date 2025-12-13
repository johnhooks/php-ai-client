<?php

declare(strict_types=1);

namespace WordPress\AiClient\Tests\unit\Providers\OpenAiCompatibleImplementation;

use Generator;
use PHPUnit\Framework\TestCase;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface;
use WordPress\AiClient\Providers\Http\Contracts\RequestAuthenticationInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Results\DTO\StreamingGenerativeAiResult;

/**
 * @covers \WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleTextGenerationModel
 */
class StreamingTextGenerationTest extends TestCase
{
    public function testStreamGenerateTextResultAccumulatesContent(): void
    {
        $httpTransporter = $this->createMock(HttpTransporterInterface::class);
        $httpTransporter
            ->method('streamResponse')
            ->willReturnCallback(function (): Generator {
                yield "data: {\"id\":\"resp_1\",\"model\":\"gpt-4\",\"choices\":[{\"index\":0,"
                    . "\"delta\":{\"content\":\"Hello\"}}]}\n\n";
                yield "data: {\"choices\":[{\"index\":0,\"delta\":{\"content\":\" world\"}}]}\n\n";
                yield "data: {\"choices\":[{\"index\":0,\"finish_reason\":\"stop\"}],\"usage\":"
                    . "{\"prompt_tokens\":5,\"completion_tokens\":5,\"total_tokens\":10}}\n\n";
                yield "data: [DONE]\n\n";
            });

        $httpTransporter
            ->method('send')
            ->willReturn($this->createMock(\WordPress\AiClient\Providers\Http\DTO\Response::class));

        $requestAuth = $this->createMock(RequestAuthenticationInterface::class);
        $requestAuth->method('authenticateRequest')->willReturnArgument(0);

        $modelMetadata = new ModelMetadata('gpt-4', 'GPT-4', [], []);
        $providerMetadata = new ProviderMetadata('openai', 'OpenAI', ProviderTypeEnum::cloud());

        $model = new MockOpenAiCompatibleTextGenerationModel(
            $modelMetadata,
            $providerMetadata,
            $httpTransporter,
            $requestAuth
        );

        $prompt = [
            new \WordPress\AiClient\Messages\DTO\Message(
                \WordPress\AiClient\Messages\Enums\MessageRoleEnum::user(),
                [new \WordPress\AiClient\Messages\DTO\MessagePart('Hello')]
            ),
        ];

        $results = iterator_to_array($model->streamGenerateTextResult($prompt));

        self::assertCount(3, $results);
        self::assertContainsOnlyInstancesOf(GenerativeAiResult::class, $results);
        self::assertInstanceOf(StreamingGenerativeAiResult::class, $results[0]);
        self::assertSame('Hello', $results[0]->getDelta());
        self::assertSame(' world', $results[1]->getDelta());
        self::assertTrue($results[2]->isComplete());
        self::assertSame('Hello world', $results[2]->toText());
        self::assertSame(10, $results[2]->getTokenUsage()->getTotalTokens());
    }
}
