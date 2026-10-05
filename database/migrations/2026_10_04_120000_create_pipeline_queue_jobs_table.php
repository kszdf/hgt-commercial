<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 任务队列看板：把 8500 上的长任务（AI 端点）持久化成可批量提交、可看状态、
     * 可失败重试、可排优先级的作业队列。
     *
     * 为什么不做在 8500 里：8500 的 /async 作业存在内存字典且「取走即清」，
     * 无法承载「一次扔 20 条看着它跑完」的看板需求。这里在 Laravel 侧落库保存
     * 提交参数与结果快照，8500 只当执行器，服务重启也不丢看板记录。
     */
    public function up(): void
    {
        Schema::create('pipeline_queue_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->index();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('batch_id', 32)->index();
            $table->string('kind', 32)->index();                 // rewrite/topic/dissect/qc/...
            $table->string('path', 64);                          // 8500 真实端点
            $table->string('title', 255)->nullable();            // 看板显示名（取输入前 30 字）
            $table->longText('input')->nullable();               // 原始输入（便于重试/复查）
            $table->json('payload');                             // 提交到 8500 的 body
            $table->string('status', 16)->default('queued')->index(); // queued/running/done/failed/canceled
            $table->unsignedTinyInteger('priority')->default(5)->index(); // 1 最高，9 最低
            $table->string('remote_job_id', 64)->nullable()->index();
            $table->json('result')->nullable();
            $table->text('error')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pipeline_queue_jobs');
    }
};
