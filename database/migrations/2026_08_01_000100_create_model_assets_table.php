<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 用户自传数字人模特素材表。
     * 上传后经自动 QC（静音音轨/画幅/时长/人脸）才 status=ready 可用于出片。
     */
    public function up(): void
    {
        Schema::create('model_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('scene')->nullable();          // 场景标签：会议室/户外...
            $table->string('file_path');                  // 宿主绝对路径（随机名）
            $table->unsignedInteger('size')->default(0);  // 字节
            $table->float('duration')->nullable();        // 秒
            $table->string('resolution')->nullable();     // 1080x1920
            $table->string('status')->default('pending'); // pending|processing|ready|rejected
            $table->json('qc_result')->nullable();        // asset QC 结论
            $table->unsignedInteger('use_count')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('model_assets');
    }
};
