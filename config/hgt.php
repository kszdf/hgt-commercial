<?php

/*
 * HGT 平台全局默认值（单一配置源）。
 *
 * 背景：平台早期是自用工具，品牌名、人设等硬编码散落在
 * PublishPackController / VideoController / capabilities.py / chat_orchestrator.py 多处。
 * 定位改为「给财税同行用的内容生产平台」后，这些必须是租户自己的，
 * 因此收敛到本文件 + python 侧 brand_defaults.py 两处。
 *
 * ── 对外（给同行用）切换 SOP ──────────────────────────────
 *   1. .env 设 HGT_BRAND_FALLBACK=（留空）
 *   2. 各租户在「租户设置」填自己的 ip_name
 *   3. php artisan config:clear
 *   4. 重启 NSSM HGTCommercial8500（python 侧读环境变量）
 * 全程零代码改动。
 */

return [

    /*
     * 租户未配置 ip_name 时的兜底品牌名。
     * 自用阶段保留默认值；对外阶段清空即可。
     */
    'brand_fallback' => env('HGT_BRAND_FALLBACK', '昆山老张讲财税'),

    /*
     * 订阅号群发频次上限（微信 3.27「非真人自动化创作」合规要求）。
     * 订阅号：每日 1 次。若将来接服务号（每月 4 次），改这里并同步 PublishQuota 策略。
     */
    'wechat_daily_publish_limit' => (int) env('HGT_WECHAT_DAILY_LIMIT', 1),

];
