<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 平台预置音色：给新租户自动预置官方标准音色（男/女各一，CosyVoice 官方音色，
     * 非克隆、非名人、无侵权），解决"新租户声音库为空无法出片"的首单卡点。
     * is_preset=1 的记录：不可删除、不可克隆覆盖；is_default 仍可切换（设默认/取消）。
     */
    public function up(): void
    {
        Schema::table('tenant_voices', function (Blueprint $table) {
            $table->boolean('is_preset')->default(false)->after('is_default');
            $table->index(['tenant_id', 'gender', 'is_preset'], 'tv_tenant_gender_preset');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_voices', function (Blueprint $table) {
            $table->dropIndex('tv_tenant_gender_preset');
            $table->dropColumn('is_preset');
        });
    }
};
