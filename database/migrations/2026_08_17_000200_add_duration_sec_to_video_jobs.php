<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * video_jobs 记录成片时长（秒），用于计量「试用累计总时长」。
     * 由 8500 /status 返回的 duration 字段在轮询/兜底同步/下载时回写；空表示该任务尚未产生成片时长。
     */
    public function up(): void
    {
        Schema::table('video_jobs', function (Blueprint $table) {
            $table->float('duration_sec')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('video_jobs', function (Blueprint $table) {
            $table->dropColumn('duration_sec');
        });
    }
};
