<?php

namespace App\Http\Requests\Api;

use App\Models\FilerFile;
use Illuminate\Validation\Rule;

class StoreFilerFileRequest extends WorkspaceRequest
{
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:20480'],
            'category' => ['required', Rule::in(FilerFile::CATEGORIES)],
            'visibility' => ['required', Rule::in(['private', 'scope'])],
            'subject_type' => ['nullable', Rule::in(['task', 'event', 'book', 'project', 'user']), 'required_with:subject_id'],
            'subject_id' => ['nullable', 'ulid', 'required_with:subject_type'],
        ];
    }
}
