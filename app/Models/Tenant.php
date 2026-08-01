<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tenant extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'slug', 'plan', 'status', 'quota_monthly',
        'default_avatar', 'default_male_voice', 'default_female_voice', 'settings',
    ];

    protected $casts = [
        'settings' => 'array',
        'quota_monthly' => 'integer',
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
}
