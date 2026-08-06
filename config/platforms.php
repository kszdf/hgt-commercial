<?php

return [
    /**
     * 平台注册表（单一事实源 · Laravel 侧权威）
     * ─────────────────────────────────────────────
     * 与 python-pipeline/platform_specs.py 逐字段同步。
     * 任一平台增删 / 规格变更，两处必须同时改（搜索 `PLATFORM_REGISTRY_SYNC` 定位）。
     *
     * 字段说明：
     *  - label  : 中文展示名
     *  - spec   : 出片分辨率 [宽, 高]（像素）
     *  - topic  : 是否进入「智能选题」平台子集（竖屏短视频需针对性调性适配）
     *  - publish: 自动发布可行性
     *      auto   = 官方 API 成熟（如 YouTube）
     *      api    = 需企业认证 + 内容授权（如 抖音/视频号/小红书/快手）
     *      manual = 无稳定官方 API，走预留人工模块（如 B站）
     */
    'platforms' => [
        'douyin'      => ['label' => '抖音',   'spec' => [1080, 1920], 'topic' => true,  'publish' => 'api'],
        'shipinhao'   => ['label' => '视频号', 'spec' => [1080, 1920], 'topic' => true,  'publish' => 'api'],
        'xiaohongshu' => ['label' => '小红书', 'spec' => [1080, 1440], 'topic' => true,  'publish' => 'api'],
        'kuaishou'    => ['label' => '快手',   'spec' => [1080, 1920], 'topic' => true,  'publish' => 'api'],
        'bilibili'    => ['label' => 'B站',    'spec' => [1920, 1080], 'topic' => false, 'publish' => 'manual'],
        'youtube'     => ['label' => 'YouTube','spec' => [1920, 1080], 'topic' => false, 'publish' => 'auto'],
    ],
];
