<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 发布记录：每次将审核通过的视频分发到某个平台都落一条。
     * 当前为演示模式（status 直接写 success），真实多平台分发需在各平台
     * 配置 OAuth 授权（platform_accounts）后由后台任务对接官方 API。
     */
    public function up(): void
    {
        Schema::create('publish_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('video_job_id')->constrained('video_jobs')->cascadeOnDelete();
            $table->string('platform');                 // wechat | douyin | xiaohongshu
            $table->foreignId('platform_account_id')->nullable()->constrained('platform_accounts')->nullOnDelete();
            $table->string('status')->default('pending'); // pending | success | failed
            $table->string('external_id')->nullable();  // 平台返回的视频 ID
            $table->text('error')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'video_job_id']);
            $table->index(['tenant_id', 'platform']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('publish_records');
    }
};
