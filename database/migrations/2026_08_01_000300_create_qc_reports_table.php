<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 质检报告表：每条出片/素材的质检结论。
     */
    public function up(): void
    {
        Schema::create('qc_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('video_job_id')->nullable()->constrained()->nullOnDelete();
            $table->string('target_type')->default('video'); // video | asset
            $table->unsignedBigInteger('target_id')->nullable(); // video_jobs.id / model_assets.id
            $table->unsignedTinyInteger('score')->default(0);   // 0-100
            $table->string('level')->default('low');            // low | medium | high
            $table->string('status')->default('passed');        // passed | warned | blocked | need_review
            $table->json('issues')->nullable();                 // [{code,level,message,auto_fixed}]
            $table->json('auto_fixed')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qc_reports');
    }
};
