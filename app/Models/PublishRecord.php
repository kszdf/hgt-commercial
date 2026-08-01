<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PublishRecord extends Model
{
    protected $fillable = [
        'tenant_id', 'video_job_id', 'platform',
        'platform_account_id', 'status', 'external_id', 'error', 'published_at',
    ];

    protected $casts = [
        'published_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function videoJob(): BelongsTo
    {
        return $this->belongsTo(VideoJob::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(PlatformAccount::class, 'platform_account_id');
    }

    public function isSuccess(): bool
    {
        return $this->status === 'success';
    }
}
