<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        //
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // 全局兜底：任何漏网的 8500 不可达异常 → 503 友好降级，避免裸 500
        $exceptions->renderable(function (\App\Exceptions\PipelineUnavailableException $e) {
            return response()->json([
                'error' => '出片服务暂时不可用，请稍后重试',
                'detail' => $e->getMessage(),
            ], 503);
        });
    })->create();
