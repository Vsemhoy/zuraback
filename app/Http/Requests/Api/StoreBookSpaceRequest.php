<?php
namespace App\Http\Requests\Api;
class StoreBookSpaceRequest extends WorkspaceRequest { public function rules(): array { return ['title'=>['required','string','max:255'],'slug'=>['nullable','alpha_dash','max:160'],'visibility'=>['sometimes','in:private,scope,public']]; } }
