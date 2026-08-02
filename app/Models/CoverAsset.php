<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class CoverAsset extends Model
{
    protected $table = 'cover_assets';

    protected $fillable = [
        'tenant_id', 'user_id', 'name', 'scene', 'category',
        'file_path', 'preview_path', 'width', 'height', 'size', 'status', 'use_count', 'is_preset',
    ];

    protected $casts = [
        'is_preset' => 'boolean',
        'width' => 'integer',
        'height' => 'integer',
        'size' => 'integer',
        'use_count' => 'integer',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** 容器绝对路径（图片在容器内存储，预览时直接读取）。 */
    public function path(): string
    {
        return Storage::disk('local')->path(ltrim($this->file_path, '/'));
    }

    public function url(): string
    {
        return route('studio.covers.preview', $this);
    }

    public function scopeForTenant($query, $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    /** 平台预设封面（全局可读，tenant_id 为空）。 */
    public function scopePresets($query)
    {
        return $query->where('is_preset', true);
    }

    public function isPreset(): bool
    {
        return (bool) $this->is_preset;
    }

    /** 预设封面是否可被当前租户使用（预设公开，或本人所属）。 */
    public function isAccessibleBy(int $tenantId): bool
    {
        return $this->is_preset || $this->tenant_id === $tenantId;
    }
}
