<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantVoice extends Model
{
    /**
     * 平台预置音色（CosyVoice 官方标准音色，非克隆/非名人，商用无侵权风险）。
     * 新租户注册/建号时自动各预置一条（男/女），解决"声音库为空无法出片"的首单卡点。
     */
    public const PRESET_MALE = ['voice_id' => 'longanyang', 'name' => '平台男声·龙昂扬', 'model' => 'cosyvoice-v3-plus'];
    public const PRESET_FEMALE = ['voice_id' => 'longanhuan', 'name' => '平台女声·龙安欢', 'model' => 'cosyvoice-v3-plus'];

    protected $fillable = [
        'tenant_id', 'user_id', 'name', 'gender', 'voice_id',
        'model', 'status', 'is_default', 'is_preset', 'use_count',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'is_preset' => 'boolean',
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

    /** 为新租户预置官方标准音色（男/女各一）。幂等：已存在同性别预置则跳过。 */
    public static function ensurePresetVoices(int $tenantId, ?int $userId = null): void
    {
        foreach ([
            ['gender' => 'male', 'preset' => self::PRESET_MALE],
            ['gender' => 'female', 'preset' => self::PRESET_FEMALE],
        ] as $item) {
            $exists = static::where('tenant_id', $tenantId)
                ->where('gender', $item['gender'])
                ->where('is_preset', true)
                ->exists();
            if ($exists) {
                continue;
            }
            $isFirst = ! static::where('tenant_id', $tenantId)
                ->where('gender', $item['gender'])
                ->where('status', 'ready')
                ->exists();
            static::create([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'name' => $item['preset']['name'],
                'gender' => $item['gender'],
                'voice_id' => $item['preset']['voice_id'],
                'model' => $item['preset']['model'],
                'status' => 'ready',
                'is_default' => $isFirst,   // 该性别尚无任何音色时预置音色自动为默认
                'is_preset' => true,
            ]);
        }
    }
}
