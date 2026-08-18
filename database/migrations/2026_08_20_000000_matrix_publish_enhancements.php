<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 功能包一：多账号矩阵发布 + 数据回流 + 发布透明化 的增量迁移。
 *
 * 1) platform_accounts：补账号运营属性（定位标签 / 每日上限 / 今日已发计数），
 *    支撑「多账号矩阵发布」与每日风控限额。
 * 2) publish_records：补 simulated（真实/模拟发布区分，发布透明化）、
 *    platform_status（平台返回状态）、post_url（作品外链）、account_name_snapshot（历史可读）。
 * 3) metrics_daily：补账号维度 + 小红书收藏 + 留资 + 数据来源（手动/自动），
 *    并把唯一键扩展为「出片 × 平台 × 账号 × 日期」。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_accounts', function (Blueprint $table) {
            $table->string('avatar_url')->nullable()->after('account_name');
            $table->string('remark')->nullable()->after('avatar_url');
            $table->json('content_tags')->nullable()->after('remark');          // 内容定位标签：风险警示/政策解读/实操指南/案例故事/避坑指南/留资转化/通用
            $table->unsignedInteger('daily_limit')->default(3)->after('content_tags'); // 每日发布上限（矩阵玩法风控）
            $table->unsignedInteger('today_count')->default(0)->after('daily_limit');  // 今日已发布数（每日 0 点由 metrics:sync 或命令重置）
            $table->timestamp('last_published_at')->nullable()->after('today_count');
        });

        Schema::table('publish_records', function (Blueprint $table) {
            $table->boolean('simulated')->default(false)->after('status');       // true = dry 模拟发布（未配置平台凭证），非真实发出
            $table->string('platform_status', 20)->nullable()->after('simulated'); // 平台返回：published / partial / failed
            $table->string('post_url')->nullable()->after('external_id');        // 平台作品外链
            $table->string('account_name_snapshot')->nullable()->after('platform_account_id'); // 发布时账号名快照
        });

        Schema::table('metrics_daily', function (Blueprint $table) {
            $table->dropUnique('metrics_unique');
        });
        Schema::table('metrics_daily', function (Blueprint $table) {
            $table->foreignId('platform_account_id')->nullable()->after('video_job_id')
                ->constrained('platform_accounts')->nullOnDelete();
            $table->unsignedBigInteger('favorites')->default(0)->after('likes');  // 小红书收藏
            $table->unsignedBigInteger('leads')->default(0)->after('favorites');  // 留资数（抖音线索/私域表单）
            $table->string('data_source', 10)->default('manual')->after('leads'); // manual | auto
            $table->timestamp('synced_at')->nullable()->after('data_source');
            $table->unique(['video_job_id', 'platform', 'platform_account_id', 'metric_date'], 'metrics_account_unique');
        });
    }

    public function down(): void
    {
        Schema::table('metrics_daily', function (Blueprint $table) {
            $table->dropUnique('metrics_account_unique');
            $table->dropColumn(['favorites', 'leads', 'platform_account_id', 'data_source', 'synced_at']);
            $table->unique(['video_job_id', 'platform', 'metric_date'], 'metrics_unique');
        });

        Schema::table('publish_records', function (Blueprint $table) {
            $table->dropColumn(['simulated', 'platform_status', 'post_url', 'account_name_snapshot']);
        });

        Schema::table('platform_accounts', function (Blueprint $table) {
            $table->dropColumn(['avatar_url', 'remark', 'content_tags', 'daily_limit', 'today_count', 'last_published_at']);
        });
    }
};
