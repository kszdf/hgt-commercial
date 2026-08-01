<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class CoverAsset extends Model
{
    protected $table = 'cover_assets';

    protected $fillable = [
        'tenant_id', 'user_id', 'name', 'scene',
        'file_path', 'preview_path', 'width', 'height', 'size', 'status', 'use_count',
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
}
