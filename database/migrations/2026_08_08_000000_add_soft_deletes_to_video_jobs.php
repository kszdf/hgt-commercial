<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 为 video_jobs 增加软删除支持（回收站功能前置）。
     * 已删除的视频进入「回收站」，可恢复或彻底删除，不立即从库表抹除。
     */
    public function up(): void
    {
        Schema::table('video_jobs', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('video_jobs', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
