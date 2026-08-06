<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 免费试用机制：每个新租户注册即自动开通（无需审批），
     * 进入为期 TRIAL_DAYS 天的免费试用，期间受月度用量上限约束。
     * 试用到期且仍为 free 套餐（未订阅）则禁止继续出片，需升级订阅。
     */
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->timestamp('trial_ends_at')->nullable()->after('status');
        });

        // 存量免费租户按注册时间补足试用窗口（从注册起算 TRIAL_DAYS 天），避免立即被判定到期。
        $days = (int) env('TRIAL_DAYS', 7);
        $rows = DB::table('tenants')
            ->where('plan', 'free')
            ->whereNull('trial_ends_at')
            ->get();

        foreach ($rows as $t) {
            DB::table('tenants')
                ->where('id', $t->id)
                ->update([
                    'trial_ends_at' => Carbon::parse($t->created_at)->addDays($days),
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('trial_ends_at');
        });
    }
};
