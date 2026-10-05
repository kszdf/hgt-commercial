<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 链式任务：一条跑完自动派生下一条（如「拆爆款 → 仿写成自己的稿」）。
     * 有了它，客户粘一个链接进去，出来直接是可用的稿，不用手动两步走。
     */
    public function up(): void
    {
        Schema::table('pipeline_queue_jobs', function (Blueprint $table) {
            $table->string('chain', 32)->nullable()->after('kind');
            $table->unsignedBigInteger('parent_id')->nullable()->after('chain');
        });
    }

    public function down(): void
    {
        Schema::table('pipeline_queue_jobs', function (Blueprint $table) {
            $table->dropColumn(['chain', 'parent_id']);
        });
    }
};
