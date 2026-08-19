<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('video_jobs', function (Blueprint $table) {
            // 记录 8500 最近一次返回的真实进度值，用于识别"僵尸进度"（如 HEYGEM status=1 progress=20 长期不变）
            $table->unsignedTinyInteger('last_progress')->nullable()->after('last_pipeline_step')
                ->comment('最近一次真实进度 0-100');
            // 记录进度变化时间，用于判断进度是否长期停滞
            $table->timestamp('progress_changed_at')->nullable()->after('last_progress')
                ->comment('进度最近一次变化的时间');
        });
    }

    public function down(): void
    {
        Schema::table('video_jobs', function (Blueprint $table) {
            $table->dropColumn(['last_progress', 'progress_changed_at']);
        });
    }
};
