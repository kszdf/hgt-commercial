<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 发布排期：视频 × 账号 × 时间点（内容日历）。
 */
class PublishSchedule extends Model
{
    protected $fillable = [
        'tenant_id', 'video_job_id', 'platform_account_id', 'schedule_at',
        'status', 'auto_publish', 'note', 'published_at', 'error',
    ];

    protected $casts = [
        'schedule_at' => 'datetime',
        'published_at' => 'datetime',
        'auto_publish' => 'boolean',
    ];

    public const STATUS_PENDING = 'pending';
    public const STATUS_DUE = 'due';
    public const STATUS_PUBLISHING = 'publishing';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';

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

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING => '待发布',
            self::STATUS_DUE => '已到期',
            self::STATUS_PUBLISHING => '发布中',
            self::STATUS_PUBLISHED => '已发布',
            self::STATUS_FAILED => '失败',
            self::STATUS_SKIPPED => '已跳过',
            default => $this->status,
        };
    }

    /** 是否仍可手动执行（到点/待发布且未终态）。 */
    public function isRunnable(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_DUE], true);
    }
}
