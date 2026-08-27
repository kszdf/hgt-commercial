<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 财税老板行业分群（P0）：选题→二创→出片→发布包装 行业贯穿。
     * video_jobs 记录该任务所属老板行业（餐饮/电商直播/制造业…），
     * 发布包装时读取，生成行业化标题/封面，缺省回退"财税"。
     */
    public function up(): void
    {
        Schema::table('video_jobs', function (Blueprint $table) {
            $table->string('industry', 40)->nullable()->after('title');
        });
    }

    public function down(): void
    {
        Schema::table('video_jobs', function (Blueprint $table) {
            $table->dropColumn('industry');
        });
    }
};
