<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

abstract class WorkspaceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }
}
