<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 每日指标快照：播放/转发/评论/点赞/收藏/留资，按 出片 × 平台 × 账号 × 日期。
 * 数据来源双轨：手动速填（data_source=manual，半自动）或 8500 /metrics/fetch 自动拉取
 * （data_source=auto，抖音 data.external.item）。「未同步」用 syncStatus() 显式表达。
 */
class MetricDaily extends Model
{
    protected $table = 'metrics_daily';

    protected $fillable = [
        'tenant_id', 'video_job_id', 'platform_account_id', 'platform', 'metric_date',
        'views', 'shares', 'comments', 'likes', 'favorites', 'leads',
        'data_source', 'synced_at',
    ];

    protected $casts = [
        'metric_date' => 'date',
        'views' => 'integer',
        'shares' => 'integer',
        'comments' => 'integer',
        'likes' => 'integer',
        'favorites' => 'integer',
        'leads' => 'integer',
        'synced_at' => 'datetime',
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

    public function platformLabel(): string
    {
        return PlatformAccount::PLATFORM_LABELS[$this->platform] ?? $this->platform;
    }

    /** 数据来源中文态。 */
    public function sourceLabel(): string
    {
        return $this->data_source === 'auto' ? '自动同步' : '手动录入';
    }

    /** 互动总数（不含播放）。 */
    public function interactions(): int
    {
        return (int) $this->shares + (int) $this->comments + (int) $this->likes + (int) $this->favorites;
    }
}
