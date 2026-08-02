<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantVoice extends Model
{
    protected $fillable = [
        'tenant_id', 'user_id', 'name', 'gender', 'voice_id',
        'model', 'status', 'is_default', 'use_count',
    ];

    protected $casts = [
        'is_default' => 'boolean',
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

    /** 该租户同性别下的所有音色。 */
    public static function forTenant(int $tenantId, ?string $gender = null)
    {
        $q = static::where('tenant_id', $tenantId)->where('status', 'ready');
        if ($gender) {
            $q->where('gender', $gender);
        }
        return $q->orderByDesc('is_default')->orderByDesc('created_at')->get();
    }
}
