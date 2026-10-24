<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EntityLinkResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = parent::toArray($request);
        foreach (['source', 'target'] as $side) {
            if (in_array($this->{$side.'_type'}, ['fact', 'contact'], true) && isset($data[$side])) {
                $data[$side] = array_intersect_key($data[$side], array_flip(['id', 'scope_id', 'label', 'name', 'kind', 'format', 'is_sensitive', 'visibility']));
            }
        }

        return $data;
    }
}
