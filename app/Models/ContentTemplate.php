<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 话术模板（财税垂类：留资钩子 / 爆款开头 / 避坑清单 / 结尾引导 / 选题角度）。
 * tenant_id 为空 = 平台级（所有租户可见，超管维护）。
 */
class ContentTemplate extends Model
{
    protected $fillable = [
        'tenant_id', 'type', 'title', 'content', 'tags', 'status', 'use_count',
    ];

    protected $casts = [
        'tags' => 'array',
        'use_count' => 'integer',
    ];

    public const TYPES = [
        'hook' => '留资钩子',
        'opening' => '爆款开头',
        'avoidance' => '避坑清单',
        'ending' => '结尾引导',
        'angle' => '选题角度',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    public function isPlatform(): bool
    {
        return $this->tenant_id === null;
    }
}
