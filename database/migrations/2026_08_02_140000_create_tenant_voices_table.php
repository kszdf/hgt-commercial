<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_voices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('name', 60);
            $table->enum('gender', ['male', 'female'])->default('male');
            $table->string('voice_id', 120);   // dashscope CosyVoice 克隆返回的 voice_id
            $table->string('model', 40)->default('cosyvoice-v3-plus');
            $table->string('status', 20)->default('ready'); // ready | failed
            $table->boolean('is_default')->default(false);   // 该性别下的默认音色
            $table->integer('use_count')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'gender']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_voices');
    }
};
