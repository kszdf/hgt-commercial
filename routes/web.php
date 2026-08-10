<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\VideoController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\StudioController;
use App\Http\Controllers\ModelAssetController;
use App\Http\Controllers\VoiceCloneController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\PublishController;
use App\Http\Controllers\CoverAssetController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return auth()->check() ? redirect('/dashboard') : redirect('/login');
});

// 公开法律页（无需登录）
Route::view('/privacy', 'legal.privacy')->name('privacy');
Route::view('/terms', 'legal.terms')->name('terms');

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    // 登录限流：每 IP 每分钟最多 5 次，防暴力破解
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    // 注册限流：每 IP 每分钟最多 5 次，防批量注册滥用
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:5,1');

    // 手机验证码找回密码（B 方案：无需邮箱，凭手机号+短信验证码重置）
    Route::get('/forgot-password', [PasswordResetController::class, 'showForgot'])->name('password.request');
    // 发码限流：每 IP 每分钟最多 5 次（叠加控制器内层 60s/手机号 + 每日 10 条，防短信轰炸）
    Route::post('/forgot-password', [PasswordResetController::class, 'sendCode'])->middleware('throttle:5,1');
    Route::get('/reset-password', [PasswordResetController::class, 'showReset'])->name('password.reset');
    Route::post('/reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:10,1');
});

Route::middleware('auth')->group(function () {
    Route::get('/dashboard', function () {
        return view('dashboard');
    })->name('dashboard');
    Route::post('/logout', [AuthController::class, 'logout']);

    // 登录用户自助改密码（账号安全）
    Route::get('/settings/password', [AuthController::class, 'showChangePassword'])->name('settings.password');
    Route::post('/settings/password', [AuthController::class, 'changePassword']);

    // 视频出片（滚动字幕卡 / 本地数字人出镜）闭环
    Route::get('/studio/scroll', [VideoController::class, 'showScroll']);
    Route::post('/studio/scroll/generate', [VideoController::class, 'generate']);
    Route::get('/studio/scroll/status/{jobId}', [VideoController::class, 'status']);
    Route::get('/studio/scroll/download/{jobId}', [VideoController::class, 'download']);

    // 批量出片（统一形式一键生成 N 条）
    Route::get('/studio/available-voices', [VideoController::class, 'voices']);
    Route::post('/studio/batch-video/plan', [VideoController::class, 'storeBatchPlan']);
    Route::get('/studio/batch-video/{batchId}', [VideoController::class, 'batchStatus']);
    Route::post('/studio/batch-video/{batchId}/progress', [VideoController::class, 'batchProgress']);

    // 视频生成列表 / 回收站（软删除：删除进回收站，可恢复或彻底删除）
    Route::get('/studio/videos', [VideoController::class, 'library'])->name('studio.videos');
    Route::delete('/studio/videos/{videoJob}', [VideoController::class, 'destroy'])->name('studio.videos.destroy');
    Route::get('/studio/recycle', [VideoController::class, 'recycle'])->name('studio.recycle');
    Route::post('/studio/recycle/{videoJob}/restore', [VideoController::class, 'restore'])->name('studio.recycle.restore')->withTrashed();
    Route::delete('/studio/recycle/{videoJob}', [VideoController::class, 'forceDestroy'])->name('studio.recycle.force')->withTrashed();

    // 智能选题 / 智能二创（AI 文本，代理到 8500 的 /topic /rewrite）
    Route::get('/studio/topic', [StudioController::class, 'topic'])->name('studio.topic');
    Route::post('/studio/topic/generate', [StudioController::class, 'topicGenerate']);
    // 选题二创：仅承接从「智能选题」选中的选题，不处理自由原始稿
    Route::get('/studio/rewrite', [StudioController::class, 'rewrite'])->name('studio.rewrite');
    // 原始稿二创：用户自有文案/口播稿的自由改写入口，与选题上下文隔离
    Route::get('/studio/rewrite-original', [StudioController::class, 'rewriteOriginal'])->name('studio.rewrite-original');
    Route::post('/studio/rewrite/generate', [StudioController::class, 'rewriteGenerate']);

    // 实时活动心跳上报（选题 / 二创 / 出片在线态，供超级管理员监控大盘）
    Route::post('/studio/activity', [StudioController::class, 'activityPing']);

    // 智能质检（违禁词 / 时长 / 风险 / 视频技术层，代理到 8500 的 /qc、/qc-video）
    Route::get('/studio/qc', [StudioController::class, 'qc'])->name('studio.qc');
    Route::post('/studio/qc/generate', [StudioController::class, 'qcGenerate']);
    Route::post('/studio/qc/video/{jobId}', [StudioController::class, 'qcVideo']);

    // 用户自传模特素材管理（上传 / 列表 / 预览 / 删除 / 重新上传）
    Route::get('/studio/models', [ModelAssetController::class, 'index'])->name('studio.models');
    Route::post('/studio/models', [ModelAssetController::class, 'store']);
    Route::get('/studio/models/json', [ModelAssetController::class, 'modelsJson']);
    Route::get('/studio/models/{modelAsset}/preview', [ModelAssetController::class, 'preview'])->name('studio.models.preview');
    Route::delete('/studio/models/{modelAsset}', [ModelAssetController::class, 'destroy'])->name('studio.models.destroy');
    Route::post('/studio/models/{modelAsset}/reupload', [ModelAssetController::class, 'reupload'])->name('studio.models.reupload');

    // 封面素材管理（上传 / 列表 / 预览 / 删除 / 重新上传）
    Route::get('/studio/covers', [CoverAssetController::class, 'index'])->name('studio.covers');
    Route::get('/studio/covers/json', [CoverAssetController::class, 'coversJson']);
    Route::get('/studio/covers/{coverAsset}/preview', [CoverAssetController::class, 'preview'])->name('studio.covers.preview');
    Route::post('/studio/covers', [CoverAssetController::class, 'store']);
    Route::delete('/studio/covers/{coverAsset}', [CoverAssetController::class, 'destroy'])->name('studio.covers.destroy');
    Route::post('/studio/covers/{coverAsset}/reupload', [CoverAssetController::class, 'reupload'])->name('studio.covers.reupload');
    Route::post('/studio/covers/{coverAsset}/pick', [CoverAssetController::class, 'pickPreset'])->name('studio.covers.pick');

    // 声音克隆（租户上传音频 → CosyVoice 克隆 → 声音库）
    Route::get('/studio/voices', [VoiceCloneController::class, 'index'])->name('studio.voices');
    Route::post('/studio/voices', [VoiceCloneController::class, 'store']);
    Route::post('/studio/voices/{voice}/default', [VoiceCloneController::class, 'setDefault'])->name('studio.voices.default');
    Route::delete('/studio/voices/{voice}', [VoiceCloneController::class, 'destroy'])->name('studio.voices.destroy');

    // 人工审核（出片完成 → 审核队列 → 通过/驳回）
    Route::get('/studio/review', [ReviewController::class, 'index'])->name('studio.review');
    Route::post('/studio/review/{videoJob}/approve', [ReviewController::class, 'approve'])->name('studio.review.approve');
    Route::post('/studio/review/{videoJob}/reject', [ReviewController::class, 'reject'])->name('studio.review.reject');

    // 批量外发（审核通过视频一键分发多平台；当前演示模式，真实平台需 OAuth 授权）
    Route::get('/studio/publish', [PublishController::class, 'index'])->name('studio.publish');
    Route::post('/studio/publish', [PublishController::class, 'publish'])->name('studio.publish.do');

    // 计费与配额
    Route::get('/admin/billing', [AdminController::class, 'billing'])->name('admin.billing');
    Route::post('/admin/billing/upgrade', [AdminController::class, 'upgrade'])->name('admin.billing.upgrade');
    Route::post('/admin/billing/checkout', [PaymentController::class, 'checkout'])->name('admin.billing.checkout');
    Route::get('/admin/billing/order-status', [PaymentController::class, 'orderStatus'])->name('admin.billing.order-status');

    // 超级管理员实时监控大盘（仅超级管理员可访问，组件 mount 内强制守卫）
    Route::get('/admin/monitor', function () {
        return view('admin.monitor');
    })->name('admin.monitor');

    // 外观设置（多主题预设 + 租户 DIY 覆盖）
    Route::get('/studio/settings/appearance', [StudioController::class, 'appearance'])->name('studio.settings.appearance');
    Route::post('/studio/settings/appearance', [StudioController::class, 'appearanceUpdate']);
});

// 支付异步回调（微信/支付宝服务器直连，CSRF 豁免）
Route::post('/pay/wechat/notify', [PaymentController::class, 'wechatNotify'])
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class]);
Route::post('/pay/alipay/notify', [PaymentController::class, 'alipayNotify'])
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class]);
Route::get('/pay/return', [PaymentController::class, 'return'])->name('pay.return');
