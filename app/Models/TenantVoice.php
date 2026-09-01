<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantVoice extends Model
{
    /**
     * 平台预置音色（CosyVoice 官方标准音色，非克隆/非名人，商用无侵权风险）。
     * 新租户注册/建号时自动各预置一条（男/女），解决"声音库为空无法出片"的首单卡点。
     * 2026-09-01 选型：男=龙硕(博才干练,新闻播报级) / 女=龙小夏(沉稳权威)，
     * 均走 cosyvoice-v3-flash 模型（合成速度约为 v3-plus 的 3 倍）。
     */
    public const PRESET_MALE = ['voice_id' => 'longshuo_v3', 'name' => '平台男声·龙硕', 'model' => 'cosyvoice-v3-flash'];
    public const PRESET_FEMALE = ['voice_id' => 'longxiaoxia_v3', 'name' => '平台女声·龙小夏', 'model' => 'cosyvoice-v3-flash'];

    /**
     * 官方音色库（阿里云 CosyVoice 官方预置音色，非克隆/非名人，商用无侵权风险）。
     * 全部经真实合成实测可用（2026-09-01），按性别分组，供租户按需添加。
     * 与预置音色的区别：预置=注册自动给，官方库=租户自助添加（添加后可删除）。
     */
    public const OFFICIAL_VOICES = [
        'male' => [
            ['voice_id' => 'longshuo_v3',   'name' => '龙硕',   'desc' => '博才干练 · 新闻播报级'],
            ['voice_id' => 'longshu_v3',    'name' => '龙书',   'desc' => '沉稳青年 · 新闻播报级'],
            ['voice_id' => 'longsanshu_v3', 'name' => '龙三叔', 'desc' => '沉稳质感 · 有声书'],
            ['voice_id' => 'longtian_v3',   'name' => '龙天',   'desc' => '磁性理智 · 30-35岁'],
            ['voice_id' => 'longfei_v3',    'name' => '龙飞',   'desc' => '热血磁性 · 30-35岁'],
            ['voice_id' => 'longnan_v3',    'name' => '龙楠',   'desc' => '睿智青年 · 有声书'],
            ['voice_id' => 'longze_v3',     'name' => '龙泽',   'desc' => '温暖元气 · 25-30岁'],
            ['voice_id' => 'longxing_v3',   'name' => '龙星',   'desc' => '磁性青年'],
            ['voice_id' => 'longanlang_v3', 'name' => '龙安朗', 'desc' => '清爽利落 · 20-25岁'],
            ['voice_id' => 'longlaobo_v3',  'name' => '龙老伯', 'desc' => '沧桑岁月 · 60岁以上'],
            ['voice_id' => 'longanyang',    'name' => '龙昂扬', 'desc' => '阳光大男孩 · 20-30岁'],
            ['voice_id' => 'longhuhu_v3',   'name' => '龙虎虎', 'desc' => '活力男声'],
        ],
        'female' => [
            ['voice_id' => 'longxiaoxia_v3',  'name' => '龙小夏', 'desc' => '沉稳权威 · 语音助手级'],
            ['voice_id' => 'longxiaochun_v3', 'name' => '龙小淳', 'desc' => '知性积极 · 语音助手级'],
            ['voice_id' => 'longyingtao_v3',  'name' => '龙应桃', 'desc' => '温柔淡定 · 25-30岁'],
            ['voice_id' => 'longyuan_v3',     'name' => '龙媛',   'desc' => '温暖治愈 · 35-40岁'],
            ['voice_id' => 'longyue_v3',      'name' => '龙悦',   'desc' => '温暖磁性 · 30-35岁'],
            ['voice_id' => 'longwan_v3',      'name' => '龙婉',   'desc' => '细腻柔声 · 20-30岁'],
            ['voice_id' => 'longyumi_v3',     'name' => '龙玉米', 'desc' => '正经青年 · 20-25岁'],
            ['voice_id' => 'longjiaxin_v3',   'name' => '龙嘉欣', 'desc' => '优雅女声'],
            ['voice_id' => 'longanhuan',      'name' => '龙安欢', 'desc' => '欢脱元气 · 20-30岁'],
        ],
    ];

    /** 官方音色统一模型（全部走 v3-flash，实测 1-2.6s 快速合成）。 */
    public const OFFICIAL_MODEL = 'cosyvoice-v3-flash';

    /** 官方音色库 → 是否已被某租户添加（voice_id 列表）。 */
    public static function officialAddedVoiceIds(int $tenantId): array
    {
        return static::where('tenant_id', $tenantId)->pluck('voice_id')->all();
    }

    /** 添加一个官方音色到租户声音库（幂等：已存在则跳过；不设默认，避免覆盖租户选择）。 */
    public static function addOfficialVoice(int $tenantId, ?int $userId, string $voiceId): ?self
    {
        // 校验 voice_id 在官方库中
        $found = null;
        foreach (self::OFFICIAL_VOICES as $gender => $list) {
            foreach ($list as $v) {
                if ($v['voice_id'] === $voiceId) {
                    $found = ['gender' => $gender, 'name' => $v['name'], 'desc' => $v['desc']];
                    break 2;
                }
            }
        }
        if (! $found) {
            return null;
        }
        if (static::where('tenant_id', $tenantId)->where('voice_id', $voiceId)->exists()) {
            return static::where('tenant_id', $tenantId)->where('voice_id', $voiceId)->first();
        }
        return static::create([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'name' => $found['name'],
            'gender' => $found['gender'],
            'voice_id' => $voiceId,
            'model' => self::OFFICIAL_MODEL,
            'status' => 'ready',
            'is_default' => false,   // 不自设默认，尊重租户当前选择
            'is_preset' => false,    // 自助添加的官方音色可删除
        ]);
    }

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
