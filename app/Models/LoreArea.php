<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Attributes\Fillable;
#[Fillable(['scope_id', 'project_id', 'parent_id', 'created_by', 'name', 'slug', 'sort_order'])]
class LoreArea extends DomainModel {}
