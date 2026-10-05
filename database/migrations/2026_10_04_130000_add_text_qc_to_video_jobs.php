<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('video_jobs', function (Blueprint $table) {
            // 文本合规预检结论（与机器技术质检 qc_status 解耦，合规模块）
            // 取值：passed（无高危）/ warned（中风险，建议修改）/ blocked（命中高危违禁词，须先改）
            $table->string('text_qc_status')->nullable()->after('qc_status')->comment('文本合规预检结论');
            // 文本质检结构化摘要（risk_level / hits 数 / 关键命中词），便于审核页直接展示
            $table->text('text_qc_summary')->nullable()->after('text_qc_status')->comment('文本合规预检摘要');
        });
    }

    public function down(): void
    {
        Schema::table('video_jobs', function (Blueprint $table) {
            $table->dropColumn(['text_qc_status', 'text_qc_summary']);
        });
    }
};
