<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'name',
        'email',
        'phone',
        'password',
        'last_seen_at',
    ];

    /**
     * 租户(为 null 表示平台全局管理员)
     */
    public function tenant()
    {
        return $this->belongsTo(\App\Models\Tenant::class);
    }

    public function isGlobalAdmin(): bool
    {
        return $this->tenant_id === null;
    }

    /**
     * 是否在线：最近 N 分钟内有活跃心跳。
     */
    public function isOnline(int $minutes = 5): bool
    {
        return $this->last_seen_at !== null
            && $this->last_seen_at->gt(now()->subMinutes($minutes));
    }

    /**
     * 更新最近活跃时间（刻意绕过 updated_at，避免干扰业务逻辑排序）。
     */
    public function touchSeen(): void
    {
        if (! $this->exists) {
            return;
        }
        static::whereKey($this->getKey())->update(['last_seen_at' => now()]);
        $this->last_seen_at = now();
    }

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
