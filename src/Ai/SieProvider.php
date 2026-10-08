<?php

declare(strict_types=1);

namespace Sie\Ai;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\Contracts\Gateway\ClassificationGateway;
use Laravel\Ai\Contracts\Gateway\EmbeddingGateway;
use Laravel\Ai\Contracts\Gateway\RerankingGateway;
use Laravel\Ai\Contracts\Providers\ClassificationProvider;
use Laravel\Ai\Contracts\Providers\EmbeddingProvider;
use Laravel\Ai\Contracts\Providers\RerankingProvider;
use Laravel\Ai\Providers\Concerns;
use Laravel\Ai\Providers\Provider;
use Sie\Exceptions\MissingEmbeddingDimensionsException;

/**
 * SIE as a `laravel/ai` provider.
 *
 * Embeddings, reranking and classification (typed decisions), and dense vectors only — `EmbeddingGateway`
 * has room for one representation and one width, so sparse and multivector
 * output stays on the native `SIE::` surface (see ADR 0002).
 */
class SieProvider extends Provider implements ClassificationProvider, EmbeddingProvider, RerankingProvider
{
    use Concerns\Classifies;
    use Concerns\GeneratesEmbeddings;
    use Concerns\HasClassificationGateway;
    use Concerns\HasEmbeddingGateway;
    use Concerns\HasRerankingGateway;
    use Concerns\Reranks;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        protected array $config,
        protected Dispatcher $events,
    ) {}

    public function defaultEmbeddingsModel(): string
    {
        /** @var string $model */
        $model = $this->packageConfig('ai.embeddings.model', 'BAAI/bge-m3');

        return $model;
    }

    public function defaultEmbeddingsDimensions(): int
    {
        $dimensions = $this->packageConfig('ai.embeddings.dimensions', null);

        if (! is_numeric($dimensions) || (int) $dimensions <= 0) {
            throw new MissingEmbeddingDimensionsException($this->defaultEmbeddingsModel());
        }

        return (int) $dimensions;
    }

    public function defaultRerankingModel(): string
    {
        /** @var string $model */
        $model = $this->packageConfig('ai.reranking.model', 'BAAI/bge-m3');

        return $model;
    }

    public function defaultClassificationModel(): string
    {
        /** @var string $model */
        $model = $this->packageConfig('ai.classification.model', 'fastino/GLiNER2.5-Decide');

        return $model;
    }

    /**
     * Text files only: the decision models read text, so the gateway reads an
     * attachment's content into the record and refuses images.
     */
    public function supportsClassificationAttachments(): bool
    {
        return true;
    }

    public function classificationGateway(): ClassificationGateway
    {
        return $this->classificationGateway ??= new SieGateway;
    }

    public function embeddingGateway(): EmbeddingGateway
    {
        return $this->embeddingGateway ??= new SieGateway;
    }

    public function rerankingGateway(): RerankingGateway
    {
        return $this->rerankingGateway ??= new SieGateway;
    }

    /**
     * The SIE connection this bridge speaks to.
     *
     * Named explicitly rather than following the default connection, so that
     * changing the default cannot silently repoint stored embeddings at a
     * different cluster.
     */
    public function connectionName(): ?string
    {
        $connection = $this->packageConfig('ai.connection', null);

        return is_string($connection) ? $connection : null;
    }

    private function packageConfig(string $key, mixed $default): mixed
    {
        /** @var Repository $config */
        $config = Container::getInstance()->make('config');

        return $config->get("superlinked-sie-laravel.{$key}", $default);
    }
}
