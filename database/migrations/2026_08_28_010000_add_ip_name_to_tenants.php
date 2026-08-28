<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 租户品牌/IP 配置（P1-⑦ 多租户 SaaS 化）：
     * 每个租户可配置自己的内容 IP 名称（封面水印/片尾留资/发布落款），
     * 默认声线/形象字段（default_male_voice 等）表里已有，此处补 ip_name。
     */
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('ip_name', 40)->nullable()->after('default_female_voice');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('ip_name');
        });
    }
};
