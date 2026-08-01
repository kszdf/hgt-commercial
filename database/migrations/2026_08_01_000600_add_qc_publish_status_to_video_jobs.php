<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * video_jobs 扩展质检/发布状态列，不影响原有渲染流(status: queued|rendering|done|failed)。
     * qc_status:    pending|passed|warned|blocked|need_review
     * publish_status: draft|reviewing|approved|rejected|published
     */
    public function up(): void
    {
        Schema::table('video_jobs', function (Blueprint $table) {
            $table->string('qc_status')->nullable()->after('status');
            $table->string('publish_status')->nullable()->after('qc_status');
        });
    }

    public function down(): void
    {
        Schema::table('video_jobs', function (Blueprint $table) {
            $table->dropColumn(['qc_status', 'publish_status']);
        });
    }
};
