<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 租户表：SaaS 多租户隔离的核心。
     * 每个注册账号 = 一个租户；租户下可有多个用户(成员)。
     * default_avatar / default_male_voice / default_female_voice 为该租户出片默认形象与声音。
     */
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('plan')->default('free');      // free | pro | enterprise
            $table->string('status')->default('active');  // active | suspended
            $table->string('default_avatar')->nullable();
            $table->string('default_male_voice')->nullable();
            $table->string('default_female_voice')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
