<?php
namespace App\Http\Requests\Api;
class StoreBookBlockVersionRequest extends WorkspaceRequest { public function rules(): array { return ['title'=>['nullable','string'],'content'=>['nullable','string'],'payload'=>['nullable','array'],'search_text'=>['nullable','string'],'status'=>['sometimes','in:draft,published']]; } }
