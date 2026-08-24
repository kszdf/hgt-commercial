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

    /** 平台中文名（复用 PlatformAccount 的标签表）。 */
    public function platformLabel(): string
    {
        return PlatformAccount::PLATFORM_LABELS[$this->platform] ?? $this->platform;
    }

    /** 是否「待人工发布」（无 API 平台，一键发布后存入待发清单）。 */
    public function isManual(): bool
    {
        return $this->status === 'manual';
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'success' => '已发布',
            'manual' => '待人工发布',
            'failed' => '失败',
            default => $this->status,
        };
    }
}
