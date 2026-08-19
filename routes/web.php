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
use App\Http\Controllers\AccountController;
use App\Http\Controllers\MetricsController;
use App\Http\Controllers\ScheduleController;
use App\Http\Controllers\MatrixController;
use App\Http\Controllers\TemplateController;
use App\Http\Controllers\XhsController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return auth()->check() ? redirect('/dashboard') : redirect('/login');
});

// 公开法律页（无需登录）
Route::view('/privacy', 'legal.privacy')->name('privacy');
Route::view('/terms', 'legal.terms')->name('terms');

// 8500 微服务心跳探测（前端全局轮询，崩了显示红字预警；独立于登录态，
// 避免会话过期时 302 跳登录页把预警挡住而看不到服务宕机）
Route::get('/studio/pipeline-health', [StudioController::class, 'pipelineHealth']);

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
    Route::get('/studio/scroll/job-log/{jobId}', [VideoController::class, 'jobLog']);
    Route::get('/studio/scroll/download/{jobId}', [VideoController::class, 'download']);
    // 出片中止：前端点击「中止」后由 onAbort 调此端点，转发 8500 标记 job 取消
    Route::post('/studio/scroll/cancel', [VideoController::class, 'cancel'])->name('studio.scroll.cancel');
    // 出片队列预估（提交前 / 轮询时展示当前队列数与预计等待，只读）
    Route::get('/studio/scroll/queue-estimate', [VideoController::class, 'queueEstimate']);

    // 出片音色列表（供单条出片表单使用；批量出片功能已移除）
    Route::get('/studio/available-voices', [VideoController::class, 'voices']);

    // 视频生成列表 / 回收站（软删除：删除进回收站，可恢复或彻底删除）
    Route::get('/studio/videos', [VideoController::class, 'library'])->name('studio.videos');
    Route::delete('/studio/videos/{videoJob}', [VideoController::class, 'destroy'])->name('studio.videos.destroy');
    Route::post('/studio/videos/{videoJob}/hit', [VideoController::class, 'markHit'])->name('studio.videos.hit');
    Route::get('/studio/videos/{videoJob}/clone-data', [VideoController::class, 'cloneData'])->name('studio.videos.clone-data');
    Route::get('/studio/recycle', [VideoController::class, 'recycle'])->name('studio.recycle');
    Route::post('/studio/recycle/{videoJob}/restore', [VideoController::class, 'restore'])->name('studio.recycle.restore')->withTrashed();
    Route::delete('/studio/recycle/{videoJob}', [VideoController::class, 'forceDestroy'])->name('studio.recycle.force')->withTrashed();

    // 智能选题 / 智能二创（AI 文本，代理到 8500 的 /topic /rewrite）
    Route::get('/studio/topic', [StudioController::class, 'topic'])->name('studio.topic');
    Route::post('/studio/topic/generate', [StudioController::class, 'topicGenerate']);
    Route::post('/studio/topic/hotspots', [StudioController::class, 'hotspotTopics']);
    // 选题二创：仅承接从「智能选题」选中的选题，不处理自由原始稿
    Route::get('/studio/rewrite', [StudioController::class, 'rewrite'])->name('studio.rewrite');
    // 原始稿二创：用户自有文案/口播稿的自由改写入口，与选题上下文隔离
    Route::get('/studio/rewrite-original', [StudioController::class, 'rewriteOriginal'])->name('studio.rewrite-original');
    Route::post('/studio/rewrite/generate', [StudioController::class, 'rewriteGenerate']);

    // 爆款拆解（输入→提取文案→结构拆解→潜力评估→去二创→数字人出片）
    Route::get('/studio/dissect', [StudioController::class, 'dissect'])->name('studio.dissect');
    Route::post('/studio/dissect/analyze', [StudioController::class, 'dissectAnalyze']);
    // 唤醒沉睡端点：去AI痕迹 / 获客军师（潜力评估）
    Route::post('/studio/deai', [StudioController::class, 'deai']);
    Route::post('/studio/strategist', [StudioController::class, 'suggestStrategist']);

    // 实时活动心跳上报（选题 / 二创 / 出片在线态，供超级管理员监控大盘）
    Route::post('/studio/activity', [StudioController::class, 'activityPing']);

    // 智能质检（违禁词 / 时长 / 风险 / 视频技术层，代理到 8500 的 /qc、/qc-video）
    Route::get('/studio/qc', [StudioController::class, 'qc'])->name('studio.qc');
    Route::post('/studio/qc/generate', [StudioController::class, 'qcGenerate']);
    Route::post('/studio/qc/video/{jobId}', [StudioController::class, 'qcVideo']);

    // AI 智能生成标题/副标题（根据文稿内容，代理到 8500 的 /suggest-title）
    Route::post('/studio/scroll/suggest-title', [StudioController::class, 'suggestTitle']);

    // 用户自传模特素材管理（上传 / 列表 / 预览 / 删除 / 重新上传）
    Route::get('/studio/models', [ModelAssetController::class, 'index'])->name('studio.models');
    Route::post('/studio/models', [ModelAssetController::class, 'store']);
    Route::get('/studio/models/json', [ModelAssetController::class, 'modelsJson']);
    Route::get('/studio/models/{modelAsset}/preview', [ModelAssetController::class, 'preview'])->name('studio.models.preview');
    Route::delete('/studio/models/{modelAsset}', [ModelAssetController::class, 'destroy'])->name('studio.models.destroy');
    Route::post('/studio/models/{modelAsset}/reupload', [ModelAssetController::class, 'reupload'])->name('studio.models.reupload');

    // 封面素材管理（上传 / 列表 / 预览 / 删除 / 重新上传）
    // ---- 小红书图文笔记 ----
    Route::get('/studio/xhs', [XhsController::class, 'index'])->name('studio.xhs');
    Route::post('/studio/xhs/build-note', [XhsController::class, 'buildNote']);
    Route::post('/studio/xhs/generate', [XhsController::class, 'generate']);
    Route::post('/studio/xhs/regen-cover', [XhsController::class, 'regenCover']);
    Route::post('/studio/xhs/publish', [XhsController::class, 'publish']);
    Route::post('/studio/xhs/download', [XhsController::class, 'download']);

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

    // 批量外发（视频 × 账号 矩阵分发；simulated 标记区分真实/模拟发布，见 PublishController）
    Route::get('/studio/publish', [PublishController::class, 'index'])->name('studio.publish');
    Route::post('/studio/publish', [PublishController::class, 'publish'])->name('studio.publish.do');

    // 平台账号管理（多账号矩阵发布：账号属性 / 每日上限 / 授权态）
    Route::get('/studio/accounts', [AccountController::class, 'index'])->name('studio.accounts');
    Route::get('/studio/accounts/json', [AccountController::class, 'json'])->name('studio.accounts.json');
    Route::post('/studio/accounts', [AccountController::class, 'store']);
    Route::post('/studio/accounts/{account}', [AccountController::class, 'update'])->name('studio.accounts.update');
    Route::post('/studio/accounts/{account}/authorized', [AccountController::class, 'markAuthorized'])->name('studio.accounts.authorized');
    Route::post('/studio/accounts/{account}/unauthorized', [AccountController::class, 'markUnauthorized'])->name('studio.accounts.unauthorized');
    Route::delete('/studio/accounts/{account}', [AccountController::class, 'destroy'])->name('studio.accounts.destroy');

    // 数据效果（数据回流 · 半自动：手动速填 / 抖音自动同步 / 未同步清单）
    Route::get('/studio/metrics', [MetricsController::class, 'index'])->name('studio.metrics');
    Route::post('/studio/metrics/record', [MetricsController::class, 'record'])->name('studio.metrics.record');
    Route::post('/studio/metrics/sync', [MetricsController::class, 'sync'])->name('studio.metrics.sync');

    // 内容日历 / 发布排期（到点提醒或自动发布）
    Route::get('/studio/schedule', [ScheduleController::class, 'index'])->name('studio.schedule');
    Route::post('/studio/schedule', [ScheduleController::class, 'store']);
    Route::post('/studio/schedule/{schedule}/auto', [ScheduleController::class, 'toggleAuto'])->name('studio.schedule.auto');
    Route::post('/studio/schedule/{schedule}/run', [ScheduleController::class, 'runNow'])->name('studio.schedule.run');
    Route::delete('/studio/schedule/{schedule}', [ScheduleController::class, 'destroy'])->name('studio.schedule.destroy');

    // 内容矩阵（一个选题 → 视频稿 + 小红书图文 + 朋友圈文案）
    Route::get('/studio/matrix', [MatrixController::class, 'index'])->name('studio.matrix');
    Route::post('/studio/matrix/video', [MatrixController::class, 'generateVideo'])->name('studio.matrix.video');
    Route::post('/studio/matrix/xhs', [MatrixController::class, 'generateXhs'])->name('studio.matrix.xhs');
    Route::post('/studio/matrix/moment', [MatrixController::class, 'generateMoment'])->name('studio.matrix.moment');
    Route::post('/studio/matrix/xhs/publish', [MatrixController::class, 'publishXhs'])->name('studio.matrix.xhs.publish');

    // 话术模板市场（财税垂类：钩子/开头/避坑/结尾/选题角度）
    Route::get('/studio/templates', [TemplateController::class, 'index'])->name('studio.templates');
    Route::post('/studio/templates', [TemplateController::class, 'store']);
    Route::post('/studio/templates/{template}', [TemplateController::class, 'update'])->name('studio.templates.update');
    Route::delete('/studio/templates/{template}', [TemplateController::class, 'destroy'])->name('studio.templates.destroy');
    Route::post('/studio/templates/{template}/copy', [TemplateController::class, 'copy'])->name('studio.templates.copy');

    // 计费与配额
    Route::get('/admin/billing', [AdminController::class, 'billing'])->name('admin.billing');
    Route::post('/admin/billing/upgrade', [AdminController::class, 'upgrade'])->name('admin.billing.upgrade');
    Route::post('/admin/billing/checkout', [PaymentController::class, 'checkout'])->name('admin.billing.checkout');
    Route::get('/admin/billing/order-status', [PaymentController::class, 'orderStatus'])->name('admin.billing.order-status');

    // 超级管理员实时监控大盘（仅超级管理员可访问，组件 mount 内强制守卫）
    Route::get('/admin/monitor', function () {
        return view('admin.monitor');
    })->name('admin.monitor');

    // 超级管理员：租户（试用账号）管理（仅超管可访问，守卫在控制器 middleware 内强制）
    Route::prefix('admin')->group(function () {
        Route::get('/tenants', [AdminController::class, 'tenants'])->name('admin.tenants');
        Route::post('/tenants', [AdminController::class, 'storeTrial'])->name('admin.tenants.store');
        Route::post('/tenants/{tenant}/trial', [AdminController::class, 'updateTrial'])->name('admin.tenants.update-trial');
    });

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
