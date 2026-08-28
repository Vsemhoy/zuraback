<?php
namespace App\Http\Requests\Api;
class UpdateBookPageRequest extends WorkspaceRequest { public function rules(): array { return ['parent_id'=>['sometimes','nullable','ulid'],'title'=>['sometimes','string','max:255'],'slug'=>['sometimes','nullable','alpha_dash','max:160'],'visibility'=>['sometimes','in:private,scope,public'],'sort_order'=>['sometimes','integer']]; } }
