<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 平台账号：新增加密存储的账号信息字段。
     *
     * 用于保存自动发布所需的账号凭据（手机号、密码、平台-specific 字段等），
     * 以加密 JSON 形式存储，避免明文落地。
     */
    public function up(): void
    {
        Schema::table('platform_accounts', function (Blueprint $table) {
            $table->text('account_info')->nullable()->after('remark'); // encrypt(JSON)
        });
    }

    public function down(): void
    {
        Schema::table('platform_accounts', function (Blueprint $table) {
            $table->dropColumn('account_info');
        });
    }
};
