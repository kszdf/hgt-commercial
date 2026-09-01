<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tenant extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'slug', 'plan', 'status', 'is_test', 'trial_ends_at', 'quota_monthly',
        'trial_max_jobs', 'trial_max_minutes', 'allow_batch',
        'default_avatar', 'default_male_voice', 'default_female_voice', 'settings',
        'theme_preset', 'theme_overrides',
    ];

    protected $casts = [
        'settings' => 'array',
        'theme_overrides' => 'array',
        'quota_monthly' => 'integer',
        'trial_max_jobs' => 'integer',
        'trial_max_minutes' => 'integer',
        'allow_batch' => 'boolean',
        'is_test' => 'boolean',
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
     * 试用累计可生成总条数（0 = 不限）。
     */
    public function trialMaxJobs(): int
    {
        return (int) $this->trial_max_jobs;
    }

    /**
     * 试用累计可生成总时长（分钟，0 = 不限）。
     */
    public function trialMaxMinutes(): int
    {
        return (int) $this->trial_max_minutes;
    }

    /**
     * 试用累计已生成总条数（不限时返回 0，仅在受限时参与计量）。
     * 统计本租户所有已完成（done）出片任务数。
     */
    public function trialJobsUsed(): int
    {
        if ($this->trialMaxJobs() === 0) {
            return 0;
        }
        return (int) $this->videoJobs()->where('status', 'done')->count();
    }

    /**
     * 试用累计已生成总时长（秒，不限时返回 0）。
     * 累加本租户所有已完任务（有 duration_sec）的成片时长。
     */
    public function trialSecondsUsed(): int
    {
        if ($this->trialMaxMinutes() === 0) {
            return 0;
        }
        return (int) $this->videoJobs()
            ->where('status', 'done')
            ->whereNotNull('duration_sec')
            ->sum('duration_sec');
    }

    /**
     * 试用累计已生成总时长（分钟，向上取整，不限时返回 0）。
     */
    public function trialMinutesUsed(): int
    {
        return (int) ceil($this->trialSecondsUsed() / 60);
    }

    /**
     * 是否可发起新的视频生成，综合判定（适用于所有套餐）：
     * 1) 免费套餐试用到期 → 禁止；
     * 2) 月度额度（quota_monthly）超限 → 禁止（不限量套餐跳过）；
     * 3) 试用累计总条数（trial_max_jobs）超限 → 禁止（不限跳过）；
     * 4) 试用累计总时长（trial_max_minutes）超限 → 禁止（不限跳过）。
     * 已订阅套餐（pro/enterprise）跳过 1/3/4，仅受 2 约束（enterprise 不限量时全跳过）。
     */
    public function canGenerate(): bool
    {
        if ($this->plan === 'free' && $this->isTrialExpired()) {
            return false;
        }
        if (! $this->isUnlimited() && $this->isOverQuota()) {
            return false;
        }
        if ($this->trialMaxJobs() > 0 && $this->trialJobsUsed() >= $this->trialMaxJobs()) {
            return false;
        }
        if ($this->trialMaxMinutes() > 0 && $this->trialMinutesUsed() >= $this->trialMaxMinutes()) {
            return false;
        }
        return true;
    }

    /**
     * 生成拦截的详细原因（供前端友好提示）。
     * 返回 null 表示可生成；否则返回 {code, message, ...}。
     */
    public function generationBlockReason(): ?array
    {
        if ($this->plan === 'free' && $this->isTrialExpired()) {
            return [
                'code' => 'trial_expired',
                'message' => '免费试用已结束，请升级订阅套餐后继续生成视频。',
            ];
        }
        if (! $this->isUnlimited() && $this->isOverQuota()) {
            return [
                'code' => 'quota_exceeded',
                'message' => '本月生成额度已用完，请升级套餐或下月继续使用。',
                'usage' => $this->usageThisMonth(),
                'quota' => $this->quota_monthly,
            ];
        }
        if ($this->trialMaxJobs() > 0 && $this->trialJobsUsed() >= $this->trialMaxJobs()) {
            return [
                'code' => 'trial_jobs_exceeded',
                'message' => '试用累计生成条数已达上限（' . $this->trialMaxJobs() . ' 条），无法继续生成。',
                'used' => $this->trialJobsUsed(),
                'max' => $this->trialMaxJobs(),
            ];
        }
        if ($this->trialMaxMinutes() > 0 && $this->trialMinutesUsed() >= $this->trialMaxMinutes()) {
            return [
                'code' => 'trial_minutes_exceeded',
                'message' => '试用累计生成时长已达上限（' . $this->trialMaxMinutes() . ' 分钟），无法继续生成。',
                'used' => $this->trialMinutesUsed(),
                'max' => $this->trialMaxMinutes(),
            ];
        }
        return null;
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
