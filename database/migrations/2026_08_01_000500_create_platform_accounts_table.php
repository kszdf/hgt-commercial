<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 视频平台账号（OAuth 授权凭证）。本机无授权，先存结构，授权后接通拉取。
     */
    public function up(): void
    {
        Schema::create('platform_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('platform');                  // wechat | douyin | xiaohongshu
            $table->string('account_name')->nullable();
            $table->text('oauth_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('status')->default('unauthorized'); // unauthorized | authorized | expired
            $table->timestamps();

            $table->index(['tenant_id', 'platform']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_accounts');
    }
};
