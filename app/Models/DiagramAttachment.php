<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;

#[Fillable(['diagram_id', 'subject_type', 'subject_id', 'created_by'])]
class DiagramAttachment extends DomainModel {}
