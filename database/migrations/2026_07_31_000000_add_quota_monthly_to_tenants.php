<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 租户每月生成额度：quota_monthly = 0 表示不限量（企业版）。
     * 计费按"生成次数"计量（每次提交出片 = 1 次）。
     */
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->integer('quota_monthly')->default(10)->after('plan');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('quota_monthly');
        });
    }
};
