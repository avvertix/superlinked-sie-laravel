<?php

declare(strict_types=1);

namespace Sie\Client\Data;

/**
 * Advertised model capabilities (mirrors the gateway's `capabilities` object
 * on each `/v1/models` entry). These flags mean the model *supports* a
 * task — not a precision-independent quality guarantee.
 */
final class ModelCapabilities
{
    /**
     * @param  ?list<string>  $grammar
     * @param  ?list<string>  $loraAdapters
     * @param  ?array<string, list<string>>  $profileLoraAdapters
     */
    public function __construct(
        public readonly ?array $grammar = null,
        public readonly ?bool $tools = null,
        public readonly ?array $loraAdapters = null,
        public readonly ?array $profileLoraAdapters = null,
        public readonly ?bool $code = null,
        public readonly ?bool $sql = null,
        public readonly ?bool $guard = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            grammar: $data['grammar'] ?? null,
            tools: $data['tools'] ?? null,
            loraAdapters: $data['lora_adapters'] ?? null,
            profileLoraAdapters: $data['profile_lora_adapters'] ?? null,
            code: $data['code'] ?? null,
            sql: $data['sql'] ?? null,
            guard: $data['guard'] ?? null,
        );
    }
}
