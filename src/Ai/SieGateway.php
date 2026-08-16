<?php

declare(strict_types=1);

namespace Sie\Ai;

use Laravel\Ai\Contracts\Gateway\EmbeddingGateway;
use Laravel\Ai\Contracts\Gateway\RerankingGateway;
use Laravel\Ai\Contracts\Providers\EmbeddingProvider;
use Laravel\Ai\Contracts\Providers\RerankingProvider;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\RankedDocument;
use Laravel\Ai\Responses\EmbeddingsResponse;
use Laravel\Ai\Responses\RerankingResponse;
use Sie\Client\Data\ScoreEntry;
use Sie\Input;
use Sie\PendingRequest;

/**
 * Translates `laravel/ai` calls into SIE requests.
 *
 * The contract has nowhere to carry SIE's instruction, query flag, profile,
 * pool, or GPU type, so they travel in `$providerOptions` and are applied here.
 */
class SieGateway implements EmbeddingGateway, RerankingGateway
{
    /**
     * @param  array<int, mixed>  $inputs
     * @param  array<string, mixed>  $providerOptions
     */
    public function generateEmbeddings(
        EmbeddingProvider $provider,
        string $model,
        array $inputs,
        int $dimensions,
        int $timeout = 30,
        array $providerOptions = [],
    ): EmbeddingsResponse {
        $request = $this->request($provider, $model, $providerOptions);

        if (isset($providerOptions['instruction']) && is_string($providerOptions['instruction'])) {
            $request->instruction($providerOptions['instruction']);
        }

        if (array_key_exists('is_query', $providerOptions)) {
            $request->asQuery((bool) $providerOptions['is_query']);
        }

        if (isset($providerOptions['options']) && is_array($providerOptions['options'])) {
            $request->options($providerOptions['options']);
        }

        $results = $request->encode(array_map(
            static fn (mixed $input): Input => Input::text((string) $input),
            array_values($inputs),
        ));

        /** @var array<int, array<float>> $embeddings */
        $embeddings = array_map(
            static fn (?array $vector): array => $vector ?? [],
            $results->dense(),
        );

        return new EmbeddingsResponse(
            $embeddings,
            // SIE's encode envelope reports timings, not token usage, so there
            // is no honest number to put here.
            0,
            new Meta(provider: $provider->name(), model: $results->model() ?? $model),
        );
    }

    /**
     * @param  array<int, string>  $documents
     */
    public function rerank(
        RerankingProvider $provider,
        string $model,
        array $documents,
        string $query,
        ?int $limit = null,
    ): RerankingResponse {
        $documents = array_values($documents);

        // Ids are assigned here rather than left to the server, so each score
        // can be mapped back to the caller's original position.
        $inputs = [];

        foreach ($documents as $index => $document) {
            $inputs[] = Input::text($document, (string) $index);
        }

        $scores = $this->request($provider, $model, [])->score(Input::text($query), $inputs);

        $results = $scores
            ->map(static function (ScoreEntry $entry) use ($documents): ?RankedDocument {
                $index = is_numeric($entry->itemId) ? (int) $entry->itemId : null;

                return $index !== null && array_key_exists($index, $documents)
                    ? new RankedDocument($index, $documents[$index], $entry->score)
                    : null;
            })
            ->filter()
            ->values();

        if ($limit !== null) {
            $results = $results->take($limit);
        }

        /** @var array<int, RankedDocument> $ranked */
        $ranked = $results->values()->all();

        return new RerankingResponse($ranked, new Meta(provider: $provider->name(), model: $model));
    }

    /**
     * @param  array<string, mixed>  $providerOptions
     */
    private function request(
        EmbeddingProvider|RerankingProvider $provider,
        string $model,
        array $providerOptions,
    ): PendingRequest {
        $connection = $provider instanceof SieProvider ? $provider->connectionName() : null;

        $request = new PendingRequest($connection, $model);

        if (isset($providerOptions['profile']) && is_string($providerOptions['profile'])) {
            $request->profile($providerOptions['profile']);
        }

        if (isset($providerOptions['pool']) && is_string($providerOptions['pool'])) {
            $request->pool($providerOptions['pool']);
        }

        if (isset($providerOptions['gpu']) && is_string($providerOptions['gpu'])) {
            $request->gpu($providerOptions['gpu']);
        }

        return $request;
    }
}
