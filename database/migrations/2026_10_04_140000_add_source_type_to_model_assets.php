<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('model_assets', function (Blueprint $table) {
            // 数字人来源：video=上传视频素材（原路径）/ photo=上传一张照片自动生成（单图口播）
            $table->string('source_type', 16)->default('video')->after('scene');
            // 照片数字人的：原始照片（宿主 storage 路径），便于回看/重建
            $table->string('source_photo')->nullable()->after('source_type');
        });
    }

    public function down(): void
    {
        Schema::table('model_assets', function (Blueprint $table) {
            $table->dropColumn(['source_type', 'source_photo']);
        });
    }
};
