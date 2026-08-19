<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 假出片根治：补充「卡死检测 / 失败可追溯」字段。
     *
     *  - last_pipeline_step：8500 最后回报的阶段（queued/editing/rendering/rerender），
     *    看门狗据此比较「阶段是否推进」，而非仅凭任务创建时间。
     *  - step_changed_at：阶段最后推进时间，作为「最近一次真实进展」的基线；
     *    某阶段长时间不推进即判定卡死。
     *  - failed_reason：结构化失败原因分类（timeout/service_unavailable/resource/format/job_lost/unknown），
     *    前端据此展示明确失败提示，而非笼统的「出片失败」。
     *  - failed_at：标记失败的时间，便于排查时间线。
     *  - pipeline_error：8500 返回的原始错误信息，失败溯源用。
     */
    public function up(): void
    {
        Schema::table('video_jobs', function (Blueprint $table) {
            $table->string('last_pipeline_step', 32)->nullable()->after('heartbeat_at')
                ->comment('8500 最后回报的阶段');
            $table->timestamp('step_changed_at')->nullable()->after('last_pipeline_step')
                ->comment('阶段最后推进时间，卡死检测基线');
            $table->string('failed_reason', 32)->nullable()->after('step_changed_at')
                ->comment('失败原因分类：timeout/service_unavailable/resource/format/job_lost/unknown');
            $table->timestamp('failed_at')->nullable()->after('failed_reason')
                ->comment('标记失败的时间');
            $table->text('pipeline_error')->nullable()->after('failed_at')
                ->comment('8500 返回的原始错误信息（失败溯源）');
        });
    }

    public function down(): void
    {
        Schema::table('video_jobs', function (Blueprint $table) {
            $table->dropColumn(['last_pipeline_step', 'step_changed_at', 'failed_reason', 'failed_at', 'pipeline_error']);
        });
    }
};
