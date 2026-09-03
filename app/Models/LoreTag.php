<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Attributes\Fillable;
#[Fillable(['scope_id', 'name', 'slug', 'color'])]
class LoreTag extends DomainModel {}
