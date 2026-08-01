<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 每日指标快照：播放/转发/评论/点赞，按 video(出片任务) × 平台 × 日期。
     * 外部平台数据经 PlatformAdapter 拉取或手动录入写入。
     */
    public function up(): void
    {
        Schema::create('metrics_daily', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('video_job_id')->nullable()->constrained()->nullOnDelete();
            $table->string('platform');                 // wechat | douyin | xiaohongshu | manual
            $table->date('metric_date');
            $table->unsignedBigInteger('views')->default(0);
            $table->unsignedBigInteger('shares')->default(0);
            $table->unsignedBigInteger('comments')->default(0);
            $table->unsignedBigInteger('likes')->default(0);
            $table->timestamps();

            $table->unique(['video_job_id', 'platform', 'metric_date'], 'metrics_unique');
            $table->index(['video_job_id', 'platform', 'metric_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('metrics_daily');
    }
};
