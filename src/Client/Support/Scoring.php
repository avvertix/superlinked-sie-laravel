<?php

declare(strict_types=1);

namespace Sie\Client\Support;

/**
 * Client-side ColBERT-style late-interaction (MaxSim) scoring for
 * pre-encoded multivectors, e.g. fetched from a vector DB, so a caller can
 * "encode once, score many" without round-tripping to `/v1/score`. Direct
 * port of `scoring.py`'s `maxsim`/`maxsim_batch`, using plain nested arrays
 * (no numpy equivalent needed at these typical multivector sizes).
 */
final class Scoring
{
    /**
     * For each document, sum over query tokens of the max dot-product
     * similarity to any token in that document.
     *
     * @param  list<list<float>>  $query  `[numQueryTokens][dim]`
     * @param  list<list<list<float>>>  $documents  list of `[numDocTokens][dim]` multivectors
     * @return list<float> one score per document, same order as `$documents`
     */
    public static function maxsim(array $query, array $documents): array
    {
        return array_map(
            static fn (array $document): float => self::scoreOne($query, $document),
            $documents,
        );
    }

    /**
     * @param  list<list<list<float>>>  $queries
     * @param  list<list<list<float>>>  $documents
     * @return list<list<float>> `[numQueries][numDocuments]`
     */
    public static function maxsimBatch(array $queries, array $documents): array
    {
        return array_map(
            static fn (array $query): array => self::maxsim($query, $documents),
            $queries,
        );
    }

    /**
     * @param  list<list<float>>  $query
     * @param  list<list<float>>  $document
     */
    private static function scoreOne(array $query, array $document): float
    {
        $total = 0.0;

        foreach ($query as $queryToken) {
            $best = null;

            foreach ($document as $documentToken) {
                $similarity = self::dot($queryToken, $documentToken);

                if ($best === null || $similarity > $best) {
                    $best = $similarity;
                }
            }

            $total += $best ?? 0.0;
        }

        return $total;
    }

    /**
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    private static function dot(array $a, array $b): float
    {
        $sum = 0.0;

        foreach ($a as $index => $value) {
            $sum += $value * ($b[$index] ?? 0.0);
        }

        return $sum;
    }
}
