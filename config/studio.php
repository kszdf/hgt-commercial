<?php

return [
    /*
    |--------------------------------------------------------------------------
    | 出片页"自用默认"参数（平台 owner 配置，用户端不暴露滑块）
    |--------------------------------------------------------------------------
    |
    | 出片页高级参数（字幕字号/描边/行数/位置/风格/字体）已从界面隐藏，
    | 出片使用这里的默认值。owner 改 .env 中对应键即可全局调优，无需改代码：
    |   SUBTITLE_DEFAULT_SIZE=92
    |   SUBTITLE_DEFAULT_OUTLINE=5
    |   SUBTITLE_DEFAULT_LINES=3
    |   SUBTITLE_DEFAULT_POSITION=bottom
    |   SUBTITLE_DEFAULT_STYLE=dynamic
    |   SUBTITLE_DEFAULT_FONT=hei
    | 改完 .env 后重启 app 容器生效（docker restart hgt-commercial-app-1）。
    */
    'subtitle_defaults' => [
        'size'     => (int) env('SUBTITLE_DEFAULT_SIZE', 92),
        'outline'  => (int) env('SUBTITLE_DEFAULT_OUTLINE', 5),
        'lines'    => (int) env('SUBTITLE_DEFAULT_LINES', 3),
        'position' => (string) env('SUBTITLE_DEFAULT_POSITION', 'bottom'),
        'style'    => (string) env('SUBTITLE_DEFAULT_STYLE', 'dynamic'),
        'font'     => (string) env('SUBTITLE_DEFAULT_FONT', 'hei'),
    ],
];
