<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 任务队列作业：一条 = 一次 8500 长任务调用（提交参数 + 状态 + 结果快照）。
 *
 * 状态流转：queued → running → done / failed；queued|running 可 → canceled。
 */
class PipelineQueueJob extends Model
{
    protected $table = 'pipeline_queue_jobs';

    protected $fillable = [
        'tenant_id', 'user_id', 'batch_id', 'kind', 'chain', 'parent_id', 'path',
        'title', 'input', 'payload', 'status', 'priority', 'remote_job_id', 'result',
        'error', 'attempts', 'started_at', 'finished_at',
    ];

    protected $casts = [
        'payload'     => 'array',
        'result'      => 'array',
        'priority'    => 'integer',
        'attempts'    => 'integer',
        'started_at'  => 'datetime',
        'finished_at' => 'datetime',
    ];

    public const STATUS_QUEUED   = 'queued';
    public const STATUS_RUNNING  = 'running';
    public const STATUS_DONE     = 'done';
    public const STATUS_FAILED   = 'failed';
    public const STATUS_CANCELED = 'canceled';

    public const STATUS_LABELS = [
        'queued'   => '待处理',
        'running'  => '处理中',
        'done'     => '已完成',
        'failed'   => '失败',
        'canceled' => '已取消',
    ];

    public const PRIORITY_LABELS = [
        1 => '最高',
        3 => '较高',
        5 => '普通',
        7 => '较低',
        9 => '最低',
    ];

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    public function priorityLabel(): string
    {
        return self::PRIORITY_LABELS[(int) $this->priority] ?? '普通';
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [self::STATUS_DONE, self::STATUS_FAILED, self::STATUS_CANCELED], true);
    }

    /** 已耗时（秒）：已完成取提交到完成的时长，进行中取提交到现在。 */
    public function elapsedSec(): int
    {
        $from = $this->started_at ?: $this->created_at;
        $to   = $this->finished_at ?: now();
        return $from ? (int) $from->diffInSeconds($to) : 0;
    }

    /** 结果摘要：不同端点返回的字段不同，这里尽量取人类可读的一句。 */
    public function resultSummary(): string
    {
        $r = $this->result;
        if (! is_array($r) || empty($r)) {
            return '';
        }
        foreach (['summary', 'result', 'text', 'output', 'report', 'analysis', 'script'] as $k) {
            if (! empty($r[$k]) && is_string($r[$k])) {
                return mb_substr(trim($r[$k]), 0, 120);
            }
        }
        if (! empty($r['items']) && is_array($r['items'])) {
            return '共 ' . count($r['items']) . ' 条结果';
        }
        if (! empty($r['topics']) && is_array($r['topics'])) {
            return '共 ' . count($r['topics']) . ' 个选题';
        }
        if (! empty($r['ok'])) {
            return '执行成功';
        }
        return '';
    }
}
