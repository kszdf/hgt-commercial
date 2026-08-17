<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration as BaseMigration;

return new class extends BaseMigration
{
    /**
     * 试用权限精细化：
     * - trial_max_jobs：试用累计可生成总条数（0 = 不限）。与 quota_monthly（月度额度）互补：
     *   quota_monthly 限制「每月」次数，trial_max_jobs 限制「整个试用期内」累计总条数。
     * - trial_max_minutes：试用累计可生成总时长（分钟，0 = 不限）。计量来自 video_jobs.duration_sec 累加。
     * - allow_batch：是否允许使用批量外发（原逻辑仅按 plan!=='free' 放开，现改为可单独授权试用账号）。
     *
     * 存量 free 租户：trial_max_jobs / trial_max_minutes 默认 0（即「不受累计限制」，向后兼容，
     * 行为等同旧版「仅受月度额度约束」），allow_batch 默认 false（与旧版免费版不开放批量外发一致）。
     */
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->integer('trial_max_jobs')->default(0)->after('quota_monthly');
            $table->integer('trial_max_minutes')->default(0)->after('trial_max_jobs');
            $table->boolean('allow_batch')->default(false)->after('trial_max_minutes');
        });

        // 存量兜底：确保已存在的 free 租户 trial_max_jobs/trial_max_minutes 为 0（不受累计限制），
        // allow_batch 保持 false。新字段已带默认值，这里仅作显式对齐，幂等。
        \Illuminate\Support\Facades\DB::table('tenants')
            ->where('plan', 'free')
            ->whereNull('trial_max_jobs')
            ->update(['trial_max_jobs' => 0, 'trial_max_minutes' => 0, 'allow_batch' => false]);
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['trial_max_jobs', 'trial_max_minutes', 'allow_batch']);
        });
    }
};
