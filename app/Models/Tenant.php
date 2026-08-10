<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tenant extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'slug', 'plan', 'status', 'trial_ends_at', 'quota_monthly',
        'default_avatar', 'default_male_voice', 'default_female_voice', 'settings',
        'theme_preset', 'theme_overrides',
    ];

    protected $casts = [
        'settings' => 'array',
        'theme_overrides' => 'array',
        'quota_monthly' => 'integer',
        'trial_ends_at' => 'datetime',
    ];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function videoJobs(): HasMany
    {
        return $this->hasMany(VideoJob::class);
    }

    public function modelAssets(): HasMany
    {
        return $this->hasMany(ModelAsset::class);
    }

    public function qcReports(): HasMany
    {
        return $this->hasMany(QcReport::class);
    }

    public function metrics(): HasMany
    {
        return $this->hasMany(MetricDaily::class);
    }

    public function platformAccounts(): HasMany
    {
        return $this->hasMany(PlatformAccount::class);
    }

    /** quota_monthly = 0 表示不限量（企业版）。 */
    public function isUnlimited(): bool
    {
        return (int) $this->quota_monthly === 0;
    }

    public function planLabel(): string
    {
        return match ($this->plan) {
            'pro' => '专业版',
            'enterprise' => '企业版',
            default => '免费版',
        };
    }

    /** 本月已用生成次数（含未完成）。 */
    public function usageThisMonth(): int
    {
        return $this->videoJobs()
            ->where('created_at', '>=', now()->startOfMonth())
            ->count();
    }

    public function isOverQuota(): bool
    {
        if ($this->isUnlimited()) {
            return false;
        }
        return $this->usageThisMonth() >= $this->quota_monthly;
    }

    /** 剩余额度（不限量返回 null）。 */
    public function remainingQuota(): ?int
    {
        if ($this->isUnlimited()) {
            return null;
        }
        return max(0, $this->quota_monthly - $this->usageThisMonth());
    }

    /**
     * 免费试用是否仍在进行。
     * - 非免费套餐（已订阅）一律视为不在试用期内；
     * - trial_ends_at 为空（存量兜底）视为仍可用。
     */
    public function isTrialActive(): bool
    {
        if ($this->plan !== 'free') {
            return false;
        }
        if ($this->trial_ends_at === null) {
            return true;
        }
        return $this->trial_ends_at->isFuture();
    }

    /** 免费试用是否已结束（已订阅套餐永不为 true）。 */
    public function isTrialExpired(): bool
    {
        return $this->plan === 'free'
            && $this->trial_ends_at !== null
            && $this->trial_ends_at->isPast();
    }

    /** 试用剩余天数（无试用期返回 null）。 */
    public function trialDaysLeft(): ?int
    {
        if ($this->trial_ends_at === null) {
            return null;
        }
        return max(0, (int) now()->diffInDays($this->trial_ends_at, false));
    }

    /**
     * 是否可发起新的视频生成。
     * - 已订阅套餐（pro/enterprise）：仅受月度额度约束；
     * - 免费套餐：试用期内且未超额度方可生成，到期未订阅则禁止。
     */
    public function canGenerate(): bool
    {
        if ($this->plan !== 'free') {
            return true;
        }
        if ($this->isTrialExpired()) {
            return false;
        }
        return ! $this->isOverQuota();
    }

    /**
     * 试用期内单条视频时长硬上限（秒）。
     * 默认 600 秒（10 分钟），可由 TRIAL_MAX_DURATION_SEC 环境变量覆盖。
     * 已订阅套餐不受此限（走全局 MAX_VIDEO_DURATION_SEC）。
     */
    public function trialMaxDurationSec(): int
    {
        return (int) env('TRIAL_MAX_DURATION_SEC', 600);
    }
}
