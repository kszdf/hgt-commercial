<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * video_jobs 增加人工审核备注列。
     * - publish_status 状态机复用：draft(刚出片待审) → reviewing(审核中) → approved(通过可外发) / rejected(驳回)
     * - review_note 存驳回理由 / 审核备注（驳回时必填）
     */
    public function up(): void
    {
        Schema::table('video_jobs', function (Blueprint $table) {
            $table->text('review_note')->nullable()->after('publish_status');
        });
    }

    public function down(): void
    {
        Schema::table('video_jobs', function (Blueprint $table) {
            $table->dropColumn('review_note');
        });
    }
};
