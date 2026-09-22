<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable(['filer_file_id', 'subject_type', 'subject_id'])]
class FilerAttachment extends DomainModel
{
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
