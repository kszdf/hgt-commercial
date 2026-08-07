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
        // 站点经云端 nginx(SSL 终止) → frp 隧道 → 本容器；仅信任该链路。
        // 容器无公网直连，故以 '*' 信任上游 X-Forwarded-*，使 Laravel 正确识别 https 并生成 https 资源链接。
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
