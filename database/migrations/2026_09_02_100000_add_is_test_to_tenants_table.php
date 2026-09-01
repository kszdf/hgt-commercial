<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 租户类别标记：is_test=1 表示公司内部测试账号（区别于真实客户试用/付费）。
     * 用于租户管理页区分展示：正式付费 / 试用 / 测试。
     */
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->boolean('is_test')->default(false)->after('status');
            $table->index(['plan', 'is_test'], 'tenants_plan_test');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropIndex('tenants_plan_test');
            $table->dropColumn('is_test');
        });
    }
};
