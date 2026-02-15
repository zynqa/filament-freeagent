<?php

declare(strict_types=1);

namespace Zynqa\FilamentFreeAgent\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FreeAgentOAuthToken extends Model
{
    protected $table = 'freeagent_oauth_tokens';

    protected $fillable = [
        'user_id',
        'access_token',
        'refresh_token',
        'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(config('auth.providers.users.model'));
    }

    /**
     * Check if the token has expired
     */
    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /**
     * Check if the token will expire soon (within 5 minutes)
     */
    public function isExpiringSoon(): bool
    {
        return $this->expires_at->subMinutes(5)->isPast();
    }

    /**
     * Check if the token is valid (not expired)
     */
    public function isValid(): bool
    {
        return ! $this->isExpired();
    }

    /**
     * Scope to get only valid (non-expired) tokens
     */
    public function scopeValid($query)
    {
        return $query->where('expires_at', '>', Carbon::now());
    }

    /**
     * Scope to get tokens for a specific user
     */
    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }
}
