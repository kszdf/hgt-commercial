<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 出片任务表：用于用量计量与配额拦截。
     * 每次提交出片在 generate 时插入一条 queued 记录，状态随管线回调/查询更新。
     */
    public function up(): void
    {
        Schema::create('video_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('job_id');                       // 出片微服务返回的 job id
            $table->string('mode')->default('scroll');      // scroll | avatar
            $table->string('title')->nullable();
            $table->string('status')->default('queued');    // queued | done | failed
            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_jobs');
    }
};
