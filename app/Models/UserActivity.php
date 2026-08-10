<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 用户活动心跳：记录各用户当前所处的生产环节（选题 / 二创 / 出片）。
 * 同一用户仅保留一条最新活动（user_id 唯一），前端每 20s 上报一次，覆盖式更新。
 * 供超级管理员实时监控大盘按租户聚合「正在做什么」。
 */
class UserActivity extends Model
{
    protected $fillable = [
        'tenant_id', 'user_id', 'action', 'detail', 'last_seen_at',
    ];

    protected $casts = [
        'detail' => 'array',
        'last_seen_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** 最近 N 分钟内有心跳的活动。 */
    public function scopeRecent($query, int $minutes = 5)
    {
        return $query->where('last_seen_at', '>=', now()->subMinutes($minutes));
    }

    /** 按 user_id 覆盖式 upsert（同一用户只保留一条最新活动记录）。 */
    public static function upsertFor(User $user, string $action, ?array $detail = null): self
    {
        return static::updateOrCreate(
            ['user_id' => $user->id],
            [
                'tenant_id' => $user->tenant_id,
                'action' => $action,
                'detail' => $detail,
                'last_seen_at' => now(),
            ]
        );
    }
}
