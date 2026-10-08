<?php

declare(strict_types=1);

namespace Sie\Ai;

use finfo;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use Laravel\Ai\Classification\Boolean;
use Laravel\Ai\Classification\Choice;
use Laravel\Ai\Classification\Score;
use Laravel\Ai\Contracts\Files\StorableFile;
use Laravel\Ai\Contracts\Gateway\ClassificationGateway;
use Laravel\Ai\Contracts\Gateway\EmbeddingGateway;
use Laravel\Ai\Contracts\Gateway\RerankingGateway;
use Laravel\Ai\Contracts\Providers\ClassificationProvider;
use Laravel\Ai\Contracts\Providers\EmbeddingProvider;
use Laravel\Ai\Contracts\Providers\RerankingProvider;
use Laravel\Ai\Contracts\Question;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Files\File;
use Laravel\Ai\Responses\ClassificationResponse;
use Laravel\Ai\Responses\Data\Answer;
use Laravel\Ai\Responses\Data\BooleanAnswer;
use Laravel\Ai\Responses\Data\ChoiceAnswer;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\RankedDocument;
use Laravel\Ai\Responses\Data\RerankingUsage;
use Laravel\Ai\Responses\Data\ScoreAnswer;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\Usage;
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
class SieGateway implements ClassificationGateway, EmbeddingGateway, RerankingGateway
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
            new Usage,
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
        int $timeout = 30,
        array $providerOptions = [],
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

        // SIE's score envelope reports no token or billing figures, so usage stays empty.
        return new RerankingResponse(
            $ranked,
            new RerankingUsage,
            new Meta(provider: $provider->name(), model: $model),
        );
    }

    /**
     * Typed questions go to a decision model's `extract` capability, which
     * answers them all about one record in a single call.
     *
     * @param  string|array<string, mixed>  $state
     * @param  array<string, Question>  $questions
     * @param  array<string, mixed>  $providerOptions
     * @param  array<int, File|UploadedFile>  $attachments  Text files only; their content is read into the text the questions are asked about.
     */
    public function classify(
        ClassificationProvider $provider,
        string $model,
        string|array $state,
        array $questions,
        int $timeout = 30,
        array $providerOptions = [],
        array $attachments = [],
    ): ClassificationResponse {
        $schema = [];

        foreach ($questions as $key => $question) {
            $schema[$key] = $this->wireQuestion($question);
        }

        $result = $this->request($provider, $model, $providerOptions)
            ->schema($schema)
            ->extract($this->mapInput($state, $attachments))
            ->throwIfAnyFailed()
            ->sole();

        $answers = [];

        foreach (array_keys($questions) as $key) {
            $raw = $result->data[$key] ?? null;

            if (! is_array($raw)) {
                throw new InvalidArgumentException("SIE returned no answer for question [{$key}].");
            }

            $answers[$key] = $this->answer((string) $key, $raw);
        }

        return new ClassificationResponse(
            $answers,
            // Like embeddings, the extract envelope reports no token usage.
            new TextUsage,
            new Meta(provider: $provider->name(), model: $model),
        );
    }

    /**
     * A decision model reads one text per record, so the state and every
     * attachment are joined into it.
     *
     * @param  string|array<string, mixed>  $state
     * @param  array<int, File|UploadedFile>  $attachments
     */
    private function mapInput(string|array $state, array $attachments): string
    {
        // A structured state has no wire shape of its own, so it is sent as JSON text.
        $text = is_string($state) ? $state : json_encode($state, JSON_THROW_ON_ERROR);

        foreach (array_values($attachments) as $attachment) {
            $text .= "\n\n".$this->attachmentText($attachment);
        }

        return $text;
    }

    /**
     * @throws InvalidArgumentException if the attachment is not a text file with inline content.
     */
    private function attachmentText(File|UploadedFile $attachment): string
    {
        if ($attachment instanceof UploadedFile) {
            $attachment = Document::fromUpload($attachment);
        }

        if (! $attachment instanceof StorableFile) {
            throw new InvalidArgumentException('SIE classification only accepts attachments with inline content; ['.get_debug_type($attachment).'] given.');
        }

        $content = $attachment->content();
        $mime = $attachment->mimeType() ?? (new finfo(FILEINFO_MIME_TYPE))->buffer($content);

        if (! $this->isText($mime) || ! mb_check_encoding($content, 'UTF-8')) {
            throw new InvalidArgumentException("SIE classification only accepts text attachments; [{$mime}] given.");
        }

        $name = $attachment->name();

        return $name !== null ? "{$name}:\n{$content}" : $content;
    }

    private function isText(string|false $mime): bool
    {
        return is_string($mime) && (
            str_starts_with($mime, 'text/')
            || in_array($mime, ['application/json', 'application/xml', 'application/yaml', 'application/x-yaml'], true)
            || str_ends_with($mime, '+json')
            || str_ends_with($mime, '+xml')
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function wireQuestion(Question $question): array
    {
        return match (true) {
            $question instanceof Boolean => array_filter([
                'type' => 'noul',
                'instructions' => $question->instructions,
                'criteria' => $question->criteria,
            ], static fn (mixed $value): bool => $value !== null),
            $question instanceof Choice => [
                'type' => 'choice',
                'instructions' => $question->instructions,
                // SIE needs a description per option; fall back to the option's own name.
                'criteria' => array_combine(
                    array_keys($question->options),
                    array_map(
                        static fn (mixed $description, string|int $name): mixed => $description ?? (string) $name,
                        $question->options,
                        array_keys($question->options),
                    ),
                ),
            ],
            $question instanceof Score => [
                'type' => 'score',
                'instructions' => $question->instructions,
                'criteria' => $question->levels,
            ],
            default => throw new InvalidArgumentException('SIE cannot answer a ['.$question::class.'] question.'),
        };
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function answer(string $key, array $raw): Answer
    {
        $confidence = isset($raw['confidence']) ? (float) $raw['confidence'] : null;

        if (($raw['type'] ?? null) === 'choice') {
            /** @var array<string, float> $probabilities */
            $probabilities = $raw['probabilities'] ?? [];

            return new ChoiceAnswer((string) $raw['choice'], $probabilities, $confidence);
        }

        if (($raw['type'] ?? null) === 'score') {
            // Levels are positional, so the wire's "0".."k-1" keys arrive as ints.
            /** @var array<int, float> $probabilities */
            $probabilities = $raw['probabilities'] ?? [];

            return new ScoreAnswer((float) $raw['score'], $probabilities, $raw['legend'] ?? [], $confidence);
        }

        if (($raw['type'] ?? null) === 'noul') {
            return new BooleanAnswer((float) $raw['noul']);
        }

        throw new InvalidArgumentException("SIE returned an unknown answer type for question [{$key}].");
    }

    /**
     * @param  array<string, mixed>  $providerOptions
     */
    private function request(
        ClassificationProvider|EmbeddingProvider|RerankingProvider $provider,
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
