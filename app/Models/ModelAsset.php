<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ModelAsset extends Model
{
    protected $fillable = [
        'tenant_id', 'user_id', 'name', 'scene', 'source_type', 'source_photo',
        'file_path', 'preview_path',
        'size', 'duration', 'resolution', 'status', 'is_default', 'qc_result', 'use_count',
    ];

    protected $casts = [
        'qc_result' => 'array',
        'is_default' => 'boolean',
        'size' => 'integer',
        'duration' => 'float',
        'use_count' => 'integer',
    ];

    /** 是否照片数字人（单图口播：一张照片自动生成）。 */
    public function isPhoto(): bool
    {
        return $this->source_type === 'photo';
    }

    /** 宿主 face2face 路径 -> HEYGEM 容器路径（/code/data/...），供出片时传给 8500。
     *  8500 返回 Windows 反斜杠，先归一化为正斜杠。 */
    public function containerPath(): string
    {
        $p = str_replace('\\', '/', $this->file_path ?? '');
        // 大小写不敏感替换：8500 有时回 d:/... 有时回 D:/...，两种都要能映射到 /code/data
        return preg_replace('#^[dD]:/heygem_data/face2face#', '/code/data', $p);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isReady(): bool
    {
        return $this->status === 'ready';
    }
}
