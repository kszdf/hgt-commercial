<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 任务状态自愈：补充心跳时间（检测孤儿任务）与幂等去重键（避免重复提交）。
     */
    public function up(): void
    {
        Schema::table('video_jobs', function (Blueprint $table) {
            $table->timestamp('heartbeat_at')->nullable()->after('status')
                ->comment('客户端轮询心跳时间，用于检测孤儿任务并自动回收');
            $table->string('dedupe_key', 64)->nullable()->after('batch_id')
                ->comment('幂等去重键：hash(tenant_id+mode+dialogue+title)');
            $table->index('dedupe_key', 'video_jobs_dedupe_key_index');
        });
    }

    public function down(): void
    {
        Schema::table('video_jobs', function (Blueprint $table) {
            $table->dropIndex('video_jobs_dedupe_key_index');
            $table->dropColumn(['heartbeat_at', 'dedupe_key']);
        });
    }
};
