<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\VideoController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\StudioController;
use App\Http\Controllers\ModelAssetController;
use App\Http\Controllers\VoiceCloneController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\PublishController;
use App\Http\Controllers\MetricController;
use App\Http\Controllers\AnalyticController;
use App\Http\Controllers\CoverAssetController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return auth()->check() ? redirect('/dashboard') : redirect('/login');
});

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login']);
    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [AuthController::class, 'register']);
});

Route::middleware('auth')->group(function () {
    Route::get('/dashboard', function () {
        return view('dashboard');
    })->name('dashboard');
    Route::post('/logout', [AuthController::class, 'logout']);

    // 视频出片（滚动字幕卡 / 本地数字人出镜）闭环
    Route::get('/studio/scroll', [VideoController::class, 'showScroll']);
    Route::post('/studio/scroll/generate', [VideoController::class, 'generate']);
    Route::get('/studio/scroll/status/{jobId}', [VideoController::class, 'status']);
    Route::get('/studio/scroll/download/{jobId}', [VideoController::class, 'download']);

    // 智能选题 / 智能二创（AI 文本，代理到 8500 的 /topic /rewrite）
    Route::get('/studio/topic', [StudioController::class, 'topic'])->name('studio.topic');
    Route::post('/studio/topic/generate', [StudioController::class, 'topicGenerate']);
    Route::get('/studio/rewrite', [StudioController::class, 'rewrite'])->name('studio.rewrite');
    Route::post('/studio/rewrite/generate', [StudioController::class, 'rewriteGenerate']);

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

    // 数据模块（录入 / CSV导入 / 平台授权占位）
    Route::get('/studio/metrics', [MetricController::class, 'index'])->name('studio.metrics');
    Route::post('/studio/metrics', [MetricController::class, 'store'])->name('studio.metrics.store');
    Route::post('/studio/metrics/import', [MetricController::class, 'import'])->name('studio.metrics.import');
    Route::get('/studio/metrics/connect/{platform}', [MetricController::class, 'connect'])->name('studio.metrics.connect');

    // 数据复盘看板
    Route::get('/studio/analytics', [AnalyticController::class, 'index'])->name('studio.analytics');

    // 计费与配额
    Route::get('/admin/billing', [AdminController::class, 'billing'])->name('admin.billing');
    Route::post('/admin/billing/upgrade', [AdminController::class, 'upgrade'])->name('admin.billing.upgrade');
});
