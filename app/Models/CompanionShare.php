<?php

namespace App\Models;

use App\Enums\InvitationStatus;
use App\Enums\QueueStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A companion invitation for one visit. The patient sends the link; the companion accepts
 * it with their own account, after which access is tied to that account, not the link.
 * Only the SHA-256 hash of the link token is stored.
 */
class CompanionShare extends Model
{
    protected $primaryKey = 'share_id';

    const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => InvitationStatus::class,
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function queue(): BelongsTo
    {
        return $this->belongsTo(Queue::class, 'queue_id', 'queue_id');
    }

    public function companion(): BelongsTo
    {
        return $this->belongsTo(User::class, 'companion_user_id', 'user_id');
    }

    /** Pending or accepted, not past its expiry, and the visit is still going. */
    public function scopeLive(Builder $query): void
    {
        $query->whereIn('status', InvitationStatus::live())
            ->where('expires_at', '>', now())
            ->whereHas('queue', fn ($queue) => $queue->whereIn('queue_status', QueueStatus::active()));
    }

    /** Stored status, or Expired once the day or the visit is over. Expects queue to be loaded. */
    public function currentStatus(): InvitationStatus
    {
        $live = in_array($this->status->value, InvitationStatus::live(), true);

        return $live && ($this->expires_at->isPast() || ! $this->queue->queue_status->isActive())
            ? InvitationStatus::Expired
            : $this->status;
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /** A live, not yet accepted invitation for this link. */
    public static function findPending(string $token): ?self
    {
        return static::live()->where('status', InvitationStatus::Pending)
            ->where('access_token_hash', static::hashToken($token))->first();
    }
}
