<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 邮箱改选填后：users.email 允许 NULL（空邮箱不再报错）。
     * 唯一索引对 NULL 不去重，多个无邮箱用户可共存。
     */
    public function up(): void
    {
        // 先允许 NULL，再归一化空字符串（顺序不能反：列未允许 NULL 时写 NULL 会报错）
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
        });
        DB::table('users')->where('email', '')->update(['email' => null]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable(false)->change();
        });
    }
};
