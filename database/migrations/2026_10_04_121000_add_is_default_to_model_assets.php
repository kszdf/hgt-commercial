<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 专属数字人：给模特素材加「默认出镜」标记。
     * 客户上传自己的视频建成专属数字人后，设为默认，出片时自动选它，
     * 不用每次出片都重新挑一遍。
     */
    public function up(): void
    {
        Schema::table('model_assets', function (Blueprint $table) {
            $table->boolean('is_default')->default(false)->after('status');
            $table->index(['tenant_id', 'is_default']);
        });
    }

    public function down(): void
    {
        Schema::table('model_assets', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'is_default']);
            $table->dropColumn('is_default');
        });
    }
};
