<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ModelAsset extends Model
{
    protected $fillable = [
        'tenant_id', 'user_id', 'name', 'scene', 'file_path', 'preview_path',
        'size', 'duration', 'resolution', 'status', 'qc_result', 'use_count',
    ];

    protected $casts = [
        'qc_result' => 'array',
        'size' => 'integer',
        'duration' => 'float',
        'use_count' => 'integer',
    ];

    /** 宿主 face2face 路径 -> HEYGEM 容器路径（/code/data/...），供出片时传给 8500。
     *  8500 返回 Windows 反斜杠，先归一化为正斜杠。 */
    public function containerPath(): string
    {
        $p = str_replace('\\', '/', $this->file_path ?? '');
        return str_replace('d:/heygem_data/face2face', '/code/data', $p);
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
