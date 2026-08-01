<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QcReport extends Model
{
    protected $fillable = [
        'tenant_id', 'video_job_id', 'target_type', 'target_id',
        'score', 'level', 'status', 'issues', 'auto_fixed',
    ];

    protected $casts = [
        'issues' => 'array',
        'auto_fixed' => 'array',
        'score' => 'integer',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function videoJob(): BelongsTo
    {
        return $this->belongsTo(VideoJob::class);
    }

    public function isBlocked(): bool
    {
        return $this->status === 'blocked';
    }
}
