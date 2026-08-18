<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlatformAccount extends Model
{
    protected $fillable = [
        'tenant_id', 'platform', 'account_name',
        'oauth_token', 'refresh_token', 'expires_at', 'status',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function isAuthorized(): bool
    {
        return $this->status === 'authorized';
    }
}
