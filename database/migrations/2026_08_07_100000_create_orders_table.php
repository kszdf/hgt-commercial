<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->onDelete('cascade');
            $table->string('plan');                       // free / pro / enterprise
            $table->decimal('amount', 10, 2);            // 订单金额（元）
            $table->string('currency')->default('CNY');
            $table->string('gateway');                   // wechat / alipay
            $table->string('gateway_order_no')->unique();// 商户订单号（out_trade_no）
            $table->string('status')->default('pending');// pending / paid / failed / expired
            $table->timestamp('paid_at')->nullable();
            $table->json('raw')->nullable();             // 原始回调报文（审计用）
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
