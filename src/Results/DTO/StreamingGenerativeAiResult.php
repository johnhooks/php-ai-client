<?php

declare(strict_types=1);

namespace WordPress\AiClient\Results\DTO;

use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Http\Streaming\StreamingDeltaAccumulator;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Results\Enums\FinishReasonEnum;

/**
 * Represents a streaming AI response that extends GenerativeAiResult.
 *
 * @since n.e.x.t
 */
class StreamingGenerativeAiResult extends GenerativeAiResult
{
    /**
     * The new content delta for this streaming chunk.
     *
     * @since n.e.x.t
     *
     * @var string|null
     */
    private ?string $delta;

    /**
     * Whether all choices have finished streaming.
     *
     * @since n.e.x.t
     *
     * @var bool
     */
    private bool $complete;

    /**
     * Creates a streaming result from the current accumulator state.
     *
     * @since n.e.x.t
     *
     * @param StreamingDeltaAccumulator $accumulator The accumulator containing accumulated streaming data.
     * @param ProviderMetadata $providerMetadata The provider metadata for this result.
     * @param ModelMetadata $modelMetadata The model metadata for this result.
     * @return self The constructed streaming result instance.
     */
    public static function fromAccumulator(
        StreamingDeltaAccumulator $accumulator,
        ProviderMetadata $providerMetadata,
        ModelMetadata $modelMetadata
    ): self {
        $candidates = self::buildCandidatesFromAccumulator($accumulator);
        $tokenUsage = self::buildTokenUsageFromAccumulator($accumulator);

        $instance = new self(
            $accumulator->getId(),
            $candidates,
            $tokenUsage,
            $providerMetadata,
            $modelMetadata,
            [
                'streaming' => true,
                'model' => $accumulator->getModel(),
            ]
        );

        $instance->delta = $accumulator->getLastDelta();
        $instance->complete = $accumulator->isComplete();

        return $instance;
    }

    /**
     * Returns the new content delta for this streaming chunk.
     *
     * @since n.e.x.t
     *
     * @return string|null The delta content, or null if no new content in this chunk.
     */
    public function getDelta(): ?string
    {
        return $this->delta;
    }

    /**
     * Checks whether the stream has completed.
     *
     * @since n.e.x.t
     *
     * @return bool True if all choices have finished streaming, false otherwise.
     */
    public function isComplete(): bool
    {
        return $this->complete;
    }

    /**
     * Builds candidate objects from the accumulator state.
     *
     * @since n.e.x.t
     *
     * @param StreamingDeltaAccumulator $accumulator The accumulator to extract candidates from.
     * @return Candidate[] The array of candidate objects.
     */
    private static function buildCandidatesFromAccumulator(StreamingDeltaAccumulator $accumulator): array
    {
        $candidates = [];
        $choiceCount = $accumulator->getChoiceCount();

        if ($choiceCount === 0) {
            $choiceCount = 1;
        }

        for ($index = 0; $index < $choiceCount; $index++) {
            $message = $accumulator->toMessage($index);
            $finishReason = self::mapFinishReason($accumulator->getFinishReason($index));
            $candidates[] = new Candidate($message, $finishReason);
        }

        return $candidates;
    }

    /**
     * Builds a token usage object from the accumulator state.
     *
     * @since n.e.x.t
     *
     * @param StreamingDeltaAccumulator $accumulator The accumulator to extract usage from.
     * @return TokenUsage The token usage statistics.
     */
    private static function buildTokenUsageFromAccumulator(StreamingDeltaAccumulator $accumulator): TokenUsage
    {
        $usage = $accumulator->getUsage();

        return new TokenUsage(
            $usage['prompt_tokens'],
            $usage['completion_tokens'],
            $usage['total_tokens']
        );
    }

    /**
     * Maps a string finish reason to the corresponding enum value.
     *
     * @since n.e.x.t
     *
     * @param string|null $reason The finish reason string from the API.
     * @return FinishReasonEnum The corresponding finish reason enum value.
     */
    private static function mapFinishReason(?string $reason): FinishReasonEnum
    {
        switch ($reason) {
            case 'length':
                return FinishReasonEnum::length();
            case 'tool_calls':
                return FinishReasonEnum::toolCalls();
            case 'content_filter':
                return FinishReasonEnum::contentFilter();
            case 'stop':
            default:
                return FinishReasonEnum::stop();
        }
    }
}
