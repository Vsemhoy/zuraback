<?php

namespace App\Http\Resources;

use App\Services\ContractorContext;
use App\Services\PrivateRecordAccessService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ContactResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $actor = app(ContractorContext::class)->actor($request);
        $scope = $request->route('scope');
        $access = app(PrivateRecordAccessService::class);
        $data = $this->resource->attributesToArray();

        return [...$data, 'can_write' => ! $this->trashed() && $access->write($actor, $scope, $this->resource), 'can_manage' => $access->manage($actor, $scope, $this->resource)];
    }
}
