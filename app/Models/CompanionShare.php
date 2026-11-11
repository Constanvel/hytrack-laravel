<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Read-only access for a family companion. Only the SHA-256 hash of the link token
 * is stored; the token itself exists only in the link handed to the patient.
 */
class CompanionShare extends Model
{
    protected $primaryKey = 'share_id';

    const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'expires_at' => 'datetime',
        ];
    }

    public function queue(): BelongsTo
    {
        return $this->belongsTo(Queue::class, 'queue_id', 'queue_id');
    }

    public function scopeUsable(Builder $query): void
    {
        $query->where('is_active', true)->where('expires_at', '>', now());
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function findUsable(string $token): ?self
    {
        return static::usable()->where('access_token_hash', static::hashToken($token))->first();
    }
}
