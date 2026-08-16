<?php

declare(strict_types=1);

namespace Sie\Exceptions;

use Sie\Client\Exceptions\SieException;

/**
 * Raised when the laravel/ai bridge is asked to embed without being told how
 * wide the vectors are.
 *
 * SIE serves models from 512 to 4096 dimensions, and `EmbeddingGateway` wants a
 * single integer. Guessing is worse than failing: a wrong width does not error,
 * it silently mismatches the vector column and surfaces days later as poor
 * recall.
 */
final class MissingEmbeddingDimensionsException extends SieException
{
    public function __construct(string $model)
    {
        parent::__construct(sprintf(
            'No embedding dimensions are configured for [%s]. Set superlinked-sie-laravel.ai.embeddings.dimensions '
            .'(or SIE_AI_EMBEDDINGS_DIMENSIONS) to the width this model reports — run SIE::models() to see it.',
            $model,
        ));
    }
}
