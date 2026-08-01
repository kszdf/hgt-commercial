<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * model_assets 增加 preview_path（Laravel 可服务的副本路径，与 file_path 渲染真值分离）。
     */
    public function up(): void
    {
        Schema::table('model_assets', function (Blueprint $table) {
            $table->string('preview_path')->nullable()->after('file_path');
        });
    }

    public function down(): void
    {
        Schema::table('model_assets', function (Blueprint $table) {
            $table->dropColumn('preview_path');
        });
    }
};
