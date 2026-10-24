<?php

namespace App\Http\Resources;

use App\Services\ContractorContext;
use App\Services\PrivateRecordAccessService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FactResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $scope = $request->route('scope');
        $actor = app(ContractorContext::class)->actor($request);
        $access = app(PrivateRecordAccessService::class);

        return [...$this->resource->attributesToArray(), 'value' => $this->is_sensitive ? null : $this->value,
            'can_write' => ! $this->trashed() && $access->write($actor, $scope, $this->resource), 'can_manage' => $access->manage($actor, $scope, $this->resource),
            'can_reveal' => ! $this->trashed() && $access->reveal($actor, $scope, $this->resource)];
    }
}
