<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 内容日历 / 发布排期（功能包二）。
 *
 * 一条排期 = 视频 × 账号 × 时间点：
 *   - auto_publish=false：到点仅提醒（status=pending→due，日历页高亮"今日待发"）；
 *   - auto_publish=true ：到点由 schedules:dispatch 自动走 PublishRunner 真实/模拟发布。
 * 状态机：pending → due / publishing → published / failed / skipped。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('publish_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('video_job_id')->constrained('video_jobs')->cascadeOnDelete();
            $table->foreignId('platform_account_id')->nullable()->constrained('platform_accounts')->nullOnDelete();
            $table->dateTime('schedule_at');
            $table->string('status', 20)->default('pending'); // pending|due|publishing|published|failed|skipped
            $table->boolean('auto_publish')->default(false);
            $table->string('note')->nullable();
            $table->dateTime('published_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'schedule_at']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('publish_schedules');
    }
};
