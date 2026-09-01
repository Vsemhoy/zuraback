<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;

class ContractorContext
{
    public function actor(Request $request): User
    {
        return $request->attributes->get('contractor_actor') ?? $request->user();
    }

    /** @return array<string, string> */
    public function auditMetadata(Request $request): array
    {
        $actor = $this->actor($request);
        $operator = $request->user();

        return $actor->is($operator) ? [] : ['performed_by' => $operator->id];
    }
}
