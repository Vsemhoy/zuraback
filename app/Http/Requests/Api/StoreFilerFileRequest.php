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
            'photo' => ['sometimes', 'boolean'],
            'category' => ['required', Rule::in(FilerFile::CATEGORIES)],
            'visibility' => ['required', Rule::in(['private', 'scope'])],
            'subject_type' => ['nullable', Rule::in(['task', 'event', 'book', 'project', 'user']), 'required_with:subject_id'],
            'subject_id' => ['nullable', 'ulid', 'required_with:subject_type'],
        ];
    }

    public function after(): array
    {
        return [function ($validator): void {
            if ($this->boolean('photo') && ! $validator->errors()->has('file')) {
                $mime = $this->file('file')?->getMimeType();
                if (! in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
                    $validator->errors()->add('file', 'Для фотографий поддерживаются JPEG, PNG и WebP.');
                }
            }
        }];
    }
}
