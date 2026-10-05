<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

// Sentry 错误上报（未安装包或未配置 DSN 时自动跳过）
if (class_exists('Sentry\\Sentry') && env('SENTRY_LARAVEL_DSN')) {
    \Sentry\init([
        'dsn' => env('SENTRY_LARAVEL_DSN'),
        'environment' => env('APP_ENV', 'production'),
        'traces_sample_rate' => (float) env('SENTRY_TRACES_SAMPLE_RATE', 0.0),
    ]);
}

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // 上游链路（按当前部署形态，2026-10-05 起）：
        //   访客 → 国内服务器 124.223.14.171 的 nginx（SSL 终止，配置见
        //      /etc/nginx/sites-available/zmgen.conf）→ frp 隧道(7000) → 本机 frpc
        //      → 本机 nginx:8080 → 本容器。
        //   云 nginx 已注入 X-Forwarded-Proto/For，Laravel 据此识别 https。
        //   （旧 Cloudflare Tunnel 形态已于 2026-10-05 整体撤除，不再经过境外节点。）
        // 容器无公网直连，两种形态下都只能以 '*' 信任上游 X-Forwarded-*，
        // 使 Laravel 正确识别 https 并生成 https 资源链接。
        // ⚠️ 切勿改成具体 IP 列表：会导致生成 http 链接与重定向循环。
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Sentry 异常上报（包已装 + DSN 已配时生效）
        if (class_exists('Sentry\\Sentry') && env('SENTRY_LARAVEL_DSN')) {
            $exceptions->reportable(function (\Throwable $e) {
                \Sentry\captureException($e);
            });
        }
        // 全局兜底：任何漏网的 8500 不可达异常 → 503 友好降级，避免裸 500
        $exceptions->renderable(function (\App\Exceptions\PipelineUnavailableException $e) {
            return response()->json([
                'error' => '出片服务暂时不可用，请稍后重试',
                'detail' => $e->getMessage(),
            ], 503);
        });
    })->create();
