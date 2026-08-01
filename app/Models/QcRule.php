<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QcRule extends Model
{
    protected $fillable = [
        'code', 'type', 'priority', 'enabled', 'params', 'version', 'status', 'description',
    ];

    protected $casts = [
        'params' => 'array',
        'enabled' => 'boolean',
        'priority' => 'integer',
        'version' => 'integer',
    ];

    /** 取当前生效规则（active + enabled），按 type 分组，便于前端/调用方使用。 */
    public static function activeRules(): array
    {
        return self::where('status', 'active')
            ->where('enabled', true)
            ->get()
            ->groupBy('type')
            ->toArray();
    }
}
