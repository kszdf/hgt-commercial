<?php

namespace Database\Seeders;

use App\Models\QcRule;
use Illuminate\Database\Seeder;

/**
 * 默认质检规则（全局，不绑定租户）。
 * priority: 1 高危(阻断) 2 中危(告警) 3 提示
 * 可被管理员在后台启停；8500 引擎按启用规则的 params 执行阈值。
 */
class QcRulesSeeder extends Seeder
{
    public function run(): void
    {
        $rules = [
            ['code' => 'forbidden_high', 'type' => 'compliance', 'priority' => 1, 'enabled' => true,
             'params' => null, 'description' => '广告法/平台高危违禁词（国家级、第一、最…）'],
            ['code' => 'forbidden_mid', 'type' => 'compliance', 'priority' => 2, 'enabled' => true,
             'params' => null, 'description' => '中等风险违禁词（诱导词、绝对化用语）'],
            ['code' => 'no_audio', 'type' => 'technical', 'priority' => 1, 'enabled' => true,
             'params' => null, 'description' => '出片产物缺少音轨'],
            ['code' => 'not_portrait', 'type' => 'technical', 'priority' => 2, 'enabled' => true,
             'params' => null, 'description' => '画幅非竖屏 9:16'],
            ['code' => 'too_long', 'type' => 'duration', 'priority' => 2, 'enabled' => true,
             'params' => ['max_duration_sec' => 180], 'description' => '出片时长超过平台上限'],
            ['code' => 'require_silent_audio', 'type' => 'asset', 'priority' => 2, 'enabled' => true,
             'params' => null, 'description' => '模特素材含音轨（将自动静音化，避免原声污染）'],
            ['code' => 'asset_portrait', 'type' => 'asset', 'priority' => 1, 'enabled' => true,
             'params' => null, 'description' => '模特素材必须竖屏 9:16'],
            ['code' => 'asset_duration', 'type' => 'asset', 'priority' => 2, 'enabled' => true,
             'params' => ['min_duration_sec' => 3, 'max_duration_sec' => 30], 'description' => '模特素材时长建议区间'],
        ];

        foreach ($rules as $r) {
            QcRule::updateOrCreate(
                ['code' => $r['code']],
                array_merge($r, ['status' => 'active', 'version' => 1])
            );
        }
    }
}
