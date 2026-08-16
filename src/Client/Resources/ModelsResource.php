<?php

declare(strict_types=1);

namespace Sie\Client\Resources;

use Sie\Client\Data\ModelInfo;
use Sie\Client\Requests\Models\GetModelRequest;
use Sie\Client\Requests\Models\ListModelsRequest;
use Sie\Client\Support\SimpleRequestSender;

final class ModelsResource
{
    public function __construct(private readonly SimpleRequestSender $sender) {}

    /**
     * @return list<ModelInfo>
     */
    public function list(): array
    {
        $response = $this->sender->send(new ListModelsRequest);
        $data = $response->json();

        return array_map(ModelInfo::fromArray(...), $data['models'] ?? []);
    }

    public function get(string $model): ModelInfo
    {
        $response = $this->sender->send(new GetModelRequest($model));

        return ModelInfo::fromArray($response->json());
    }
}
