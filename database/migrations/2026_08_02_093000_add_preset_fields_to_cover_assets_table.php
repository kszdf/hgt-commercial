<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 预设封面库：平台按行业分类提供的官方封面（is_preset=true，tenant_id 为空、全局可读）。
     * 租户上传的封面仍保留 tenant_id（租户隔离）。
     */
    public function up(): void
    {
        Schema::table('cover_assets', function (Blueprint $table) {
            $table->string('category')->nullable()->after('scene')
                ->comment('行业分类 slug（仅预设封面使用）');
            $table->boolean('is_preset')->default(false)->after('category')
                ->comment('是否为平台预设封面（全局可读，租户不可删）');
        });

        // 预设封面归属全局，tenant_id 允许为空（保留外键，仅放宽为可空）
        DB::statement('ALTER TABLE cover_assets MODIFY tenant_id BIGINT UNSIGNED NULL');

        Schema::table('cover_assets', function (Blueprint $table) {
            $table->index(['category', 'is_preset'], 'cover_assets_category_preset_idx');
        });
    }

    public function down(): void
    {
        Schema::table('cover_assets', function (Blueprint $table) {
            $table->dropIndex('cover_assets_category_preset_idx');
            $table->dropColumn(['category', 'is_preset']);
        });

        DB::statement('ALTER TABLE cover_assets MODIFY tenant_id BIGINT UNSIGNED NOT NULL');
    }
};
