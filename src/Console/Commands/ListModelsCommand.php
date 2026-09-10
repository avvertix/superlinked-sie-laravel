<?php

declare(strict_types=1);

namespace Sie\Console\Commands;

use Illuminate\Console\Command;
use Sie\Client\Data\ModelDims;
use Sie\Client\Data\ModelInfo;
use Sie\Client\Exceptions\SieException;
use Sie\SieManager;
use Throwable;

/**
 * Shows what a cluster actually serves.
 *
 * Which **Capabilities** a model supports is declared by the cluster, not by
 * the caller, so "can this model encode?" is a question only the catalog can
 * answer — and the answer decides which chain a developer can write.
 */
class ListModelsCommand extends Command
{
    protected $signature = 'sie:models
                            {filter? : Only show models whose name contains this}
                            {--loaded : Only show models currently loaded on a worker}
                            {--connection= : The SIE connection to read, defaulting to the configured one}';

    protected $description = 'List the models a SIE cluster serves';

    public function handle(SieManager $manager): int
    {
        $name = $this->option('connection');
        $name = is_string($name) && $name !== '' ? $name : null;

        try {
            $connection = $manager->connection($name);
            $models = $connection->models();
        } catch (Throwable $exception) {
            // A missing connection, an unset url, or an unreachable cluster are
            // all ordinary operator mistakes, and a stack trace helps nobody.
            // Anything that is *not* one of ours is named, so a bug in here
            // cannot masquerade as an unreachable cluster.
            $this->components->error($exception instanceof SieException
                ? $exception->getMessage()
                : $exception::class.': '.$exception->getMessage());

            return self::FAILURE;
        }

        if ($models->isEmpty()) {
            $this->components->warn("No models are served by the [{$connection->name}] connection.");

            return self::SUCCESS;
        }

        // A cluster serves upwards of 150 models once profile variants are
        // counted, so scanning the whole table to find one is impractical.
        $filter = $this->argument('filter');
        $filter = is_string($filter) && $filter !== '' ? $filter : null;

        $criteria = [];

        if ($filter !== null) {
            $models = $models->filter(
                static fn (ModelInfo $model): bool => stripos($model->name, $filter) !== false,
            );

            $criteria[] = "name contains [{$filter}]";
        }

        if ($this->option('loaded')) {
            // Strictly true: a model whose state the cluster did not report is
            // not known to be loaded, and guessing either way is worse than
            // leaving it out of an answer that was asked for precisely.
            $models = $models->filter(static fn (ModelInfo $model): bool => $model->loaded === true);

            $criteria[] = 'currently loaded';
        }

        $where = $criteria === [] ? '' : ' where '.implode(' and ', $criteria);

        if ($models->isEmpty()) {
            $this->components->warn("No models on the [{$connection->name}] connection{$where}.");

            return self::SUCCESS;
        }

        $this->components->info(sprintf(
            'Models served by the [%s] connection (%s)%s.',
            $connection->name,
            $connection->format()->value,
            $where,
        ));

        $rows = $models
            ->sortBy(static fn (ModelInfo $model): string => $model->name)
            ->map(static fn (ModelInfo $model): array => [
                $model->name,
                self::join($model->inputs),
                self::join($model->outputs),
                self::dimensions($model->dims),
                self::loaded($model->loaded),
            ])
            ->values()
            ->all();

        $this->table(['Model', 'Inputs', 'Outputs', 'Dimensions', 'Loaded'], $rows);

        return self::SUCCESS;
    }

    /**
     * @param  ?list<string>  $values
     */
    private static function join(?array $values): string
    {
        return $values === null || $values === [] ? '-' : implode(', ', $values);
    }

    /**
     * Only the outputs that actually have a width are worth a column.
     */
    private static function dimensions(?ModelDims $dims): string
    {
        if ($dims === null) {
            return '-';
        }

        $parts = [];

        foreach (['dense' => $dims->dense, 'sparse' => $dims->sparse, 'multivector' => $dims->multivector] as $output => $width) {
            if ($width !== null) {
                $parts[] = "{$output}: {$width}";
            }
        }

        return $parts === [] ? '-' : implode(', ', $parts);
    }

    private static function loaded(?bool $loaded): string
    {
        return match ($loaded) {
            true => 'yes',
            false => 'no',
            default => '-',
        };
    }
}
