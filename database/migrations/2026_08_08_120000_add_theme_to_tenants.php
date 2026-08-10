<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 多主题 + 租户 DIY 定制：
     * - theme_preset：预设风格（indigo 靛蓝商务 / warm 暖阳亲和 / teal 青翠清新），默认 indigo。
     * - theme_overrides：租户自由覆盖（accent 强调色、page_tint 页面底色、density 菜单密度），JSON。
     */
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('theme_preset')->nullable()->default('indigo')->after('settings');
            $table->json('theme_overrides')->nullable()->after('theme_preset');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['theme_preset', 'theme_overrides']);
        });
    }
};
