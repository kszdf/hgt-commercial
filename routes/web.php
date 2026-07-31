<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\VideoController;
use App\Http\Controllers\AdminController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect('/login');
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

    // 计费与配额
    Route::get('/admin/billing', [AdminController::class, 'billing'])->name('admin.billing');
    Route::post('/admin/billing/upgrade', [AdminController::class, 'upgrade'])->name('admin.billing.upgrade');
});
