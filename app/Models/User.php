<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['type', 'status', 'created_by', 'name', 'position', 'username', 'email', 'password', 'activated_at', 'is_active', 'profile'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasUlids, Notifiable, SoftDeletes;

    public const TYPES = ['real', 'virtual', 'agent'];

    public const STATUSES = ['active', 'blocked', 'dormant'];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(self::class, 'created_by');
    }

    public function scopeMemberships(): HasMany
    {
        return $this->hasMany(ScopeMember::class);
    }

    public function ownedScopes(): HasMany
    {
        return $this->hasMany(Scope::class, 'owner_id');
    }

    public function projectMemberships(): HasMany
    {
        return $this->hasMany(ProjectMember::class);
    }

    public function delegatedPersonas(): HasMany
    {
        return $this->hasMany(ContractorDelegation::class, 'operator_id');
    }

    public function receivedDelegations(): HasMany
    {
        return $this->hasMany(ContractorDelegation::class, 'contractor_id');
    }

    public function isReal(): bool
    {
        return $this->type === 'real';
    }

    public function isVirtual(): bool
    {
        return $this->type === 'virtual';
    }

    public function isAgent(): bool
    {
        return $this->type === 'agent';
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'activated_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'profile' => 'array',
        ];
    }
}
