<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Document extends Model
{
    use Auditable;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_archived' => 'boolean'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(DocumentVersion::class);
    }

    public function currentVersion(): HasOne
    {
        return $this->hasOne(DocumentVersion::class)->where('is_current', true);
    }

    /**
     * Scope query to documents visible to the given user (CMP-30 / FR-011 / BR-002).
     */
    public function scopeVisibleTo($query, User $user)
    {
        $userRole = is_string($user->role) ? $user->role : $user->role?->value;

        return $query->where('organization_id', $user->organization_id)
            ->where('is_archived', false)
            ->when($userRole === 'general_employee' || $userRole === 'employee', function ($q) {
                $q->where('access_level', 'all_staff');
            });
    }
}
