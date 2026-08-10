<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 用户活动心跳表：记录各用户当前所处的生产环节（选题/二创/出片），
     * 供超级管理员实时监控大盘按租户聚合「正在做什么」。
     * 同一用户仅保留一条最新活动（user_id 唯一）。
     */
    public function up(): void
    {
        Schema::create('user_activities', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('user_id')->unique();
            $table->string('action', 32)->index()->comment('topic|rewrite|video|studio');
            $table->json('detail')->nullable();
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_activities');
    }
};
