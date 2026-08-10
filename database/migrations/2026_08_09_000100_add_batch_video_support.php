<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('video_jobs', function (Blueprint $table) {
            $table->string('batch_id', 36)->nullable()->after('cover_asset_id')->index('video_jobs_batch_id_index');
        });

        Schema::create('video_job_batches', function (Blueprint $table) {
            $table->id();
            $table->string('batch_id', 36)->unique();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('user_id');
            $table->json('config');   // 统一形式 + 声线 + 字幕等配置
            $table->json('scripts');  // [{title, cleaned, status, job_id}]
            $table->unsignedTinyInteger('total')->default(0);
            $table->unsignedTinyInteger('done')->default(0);
            $table->unsignedTinyInteger('failed')->default(0);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index('batch_id');
        });
    }

    public function down(): void
    {
        Schema::table('video_jobs', function (Blueprint $table) {
            $table->dropIndex('video_jobs_batch_id_index');
            $table->dropColumn('batch_id');
        });
        Schema::dropIfExists('video_job_batches');
    }
};
