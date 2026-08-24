<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 视频平台账号（多账号矩阵发布的核心实体）。
 *
 * 一个租户可拥有同一平台的多个账号，每个账号独立：
 *  - OAuth 凭证（oauth_token / refresh_token / expires_at，经 8500 平台级授权后回填）
 *  - 内容定位标签（content_tags：风险警示 / 政策解读 / 实操指南 / 案例故事 / 避坑指南 / 留资转化 / 通用）
 *  - 每日发布上限（daily_limit，矩阵玩法风控；today_count 每日 0 点重置）
 */
class PlatformAccount extends Model
{
    protected $fillable = [
        'tenant_id', 'platform', 'account_name',
        'avatar_url', 'remark', 'content_tags', 'daily_limit',
        'today_count', 'last_published_at',
        'oauth_token', 'refresh_token', 'expires_at', 'status',
        'account_info',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'last_published_at' => 'datetime',
        'content_tags' => 'array',
        'daily_limit' => 'integer',
        'today_count' => 'integer',
        'account_info' => 'encrypted:array',
    ];

    /** 平台规范键 → 中文名（与 config/platforms.php 保持一致）。 */
    public const PLATFORM_LABELS = [
        'douyin' => '抖音',
        'shipinhao' => '视频号',
        'xiaohongshu' => '小红书',
        'kuaishou' => '快手',
        'bilibili' => 'B站',
        'youtube' => 'YouTube',
        'wechat' => '公众号',
        'manual' => '手动',
    ];

    /** 人工发布平台：无稳定公开 API / 未接入，一键发布走「待人工发布」而非报错。 */
    public const MANUAL_PLATFORMS = ['shipinhao', 'bilibili', 'youtube', 'kuaishou'];

    /** 内容定位标签（选题/改写页「重点方向」同源）。 */
    public const CONTENT_TAGS = [
        '风险警示', '政策解读', '实操指南', '案例故事', '避坑指南', '留资转化', '通用',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function publishRecords(): HasMany
    {
        return $this->hasMany(PublishRecord::class);
    }

    public function metrics(): HasMany
    {
        return $this->hasMany(MetricDaily::class);
    }

    public function isAuthorized(): bool
    {
        return $this->status === 'authorized';
    }

    /** 是否人工发布平台（无需 OAuth/凭证授权，一键发布走「待人工发布」）。 */
    public function isManualPlatform(): bool
    {
        return in_array($this->platform, self::MANUAL_PLATFORMS, true);
    }

    /** 是否可一键发布（人工平台恒可，自动平台需已授权）。 */
    public function isPublishable(): bool
    {
        return $this->isManualPlatform() || $this->isAuthorized();
    }

    public function platformLabel(): string
    {
        return self::PLATFORM_LABELS[$this->platform] ?? $this->platform;
    }

    /** 今日已发布数（跨天自动归零：以 last_published_at 是否今天判定，免定时任务）。 */
    private function usedToday(): int
    {
        return ($this->last_published_at && $this->last_published_at->isToday())
            ? (int) $this->today_count
            : 0;
    }

    /** 今日是否还能发布（每日上限风控）。 */
    public function canPublishToday(): bool
    {
        return $this->usedToday() < (int) $this->daily_limit;
    }

    public function remainingToday(): int
    {
        return max(0, (int) $this->daily_limit - $this->usedToday());
    }

    /** 发布成功计数（外部负责在发布成功后调用；跨天自动从 0 起算）。 */
    public function markPublished(): void
    {
        $this->update([
            'today_count' => $this->usedToday() + 1,
            'last_published_at' => now(),
        ]);
    }

    /** 每日 0 点重置今日计数（由 metrics:sync 命令或调度调用）。 */
    public static function resetTodayCounts(): void
    {
        static::query()->update(['today_count' => 0]);
    }
}
