<?php

namespace App\Http\Controllers;

use App\Models\PipelineQueueJob;
use App\Services\PipelineQueue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * 任务队列看板：一次扔一批任务进去，看着它跑完。
 *
 * 这是商用演示里最能打的一屏——客户看到的不是"我点一下等半天"，
 * 而是"我提交 20 条，看板实时跑，失败能重试，我可以去干别的"。
 */
class QueueController extends Controller
{
    private PipelineQueue $queue;

    public function __construct(PipelineQueue $queue)
    {
        $this->queue = $queue;
    }

    /** 看板主页：统计 + 批量提交 + 队列列表。 */
    public function index(Request $request): View
    {
        $tenant = $this->studioTenant($request);
        $this->queue->sync($tenant->id);
        $this->queue->dispatch($tenant->id);

        $jobs = PipelineQueueJob::query()
            ->where('tenant_id', $tenant->id)
            ->orderByRaw("FIELD(status,'running','queued','failed','done','canceled')")
            ->orderByDesc('id')
            ->limit(120)
            ->get();

        return view('studio.queue', [
            'jobs'   => $jobs,
            'stats'  => $this->queue->stats($tenant->id),
            'kinds'  => PipelineQueue::KINDS,
            'maxBatch' => $this->queue->maxBatch(),
        ]);
    }

    /**
     * 看板轮询接口：先同步 8500 状态，再放行排队任务，最后返回全量快照。
     * 前端每 3 秒打一次，比逐个轮询 8500 更省（一次请求拿全量）。
     */
    public function json(Request $request): JsonResponse
    {
        $tenant = $this->studioTenant($request);
        $this->queue->sync($tenant->id);
        $this->queue->dispatch($tenant->id);

        $jobs = PipelineQueueJob::query()
            ->where('tenant_id', $tenant->id)
            ->orderByRaw("FIELD(status,'running','queued','failed','done','canceled')")
            ->orderByDesc('id')
            ->limit(120)
            ->get();

        return response()->json([
            'ok'    => true,
            'stats' => $this->queue->stats($tenant->id),
            'jobs'  => $jobs->map(fn (PipelineQueueJob $j) => [
                'id'         => $j->id,
                'batch_id'   => $j->batch_id,
                'kind'       => $j->kind,
                'kind_label' => PipelineQueue::KINDS[$j->kind]['label'] ?? $j->kind,
                'title'      => $j->title,
                'status'     => $j->status,
                'status_label' => $j->statusLabel(),
                'priority'   => (int) $j->priority,
                'priority_label' => $j->priorityLabel(),
                'attempts'   => (int) $j->attempts,
                'elapsed'    => $j->elapsedSec(),
                'summary'    => $j->resultSummary(),
                'error'      => $j->error,
                'result'     => $j->result,
                'created_at' => optional($j->created_at)->format('H:i:s'),
            ]),
        ]);
    }

    /** 批量提交：body {kind, items(多行文本), priority, industry?, platform?, count?}。 */
    public function submit(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kind'     => ['required', 'string', 'max:32'],
            'items'    => ['required', 'string'],
            'priority' => ['nullable', 'integer', 'between:1,9'],
            'industry' => ['nullable', 'string', 'max:60'],
            'platform' => ['nullable', 'string', 'max:30'],
            'count'    => ['nullable', 'integer', 'between:1,20'],
        ]);

        $tenant = $this->studioTenant($request);
        $items  = preg_split('/\r\n|\r|\n/', (string) $data['items']) ?: [];

        $res = $this->queue->submit(
            $tenant,
            $request->user()?->id,
            (string) $data['kind'],
            $items,
            (int) ($data['priority'] ?? 5),
            [
                'industry' => $data['industry'] ?? null,
                'platform' => $data['platform'] ?? null,
                'count'    => $data['count'] ?? null,
            ]
        );

        if (! ($res['ok'] ?? false)) {
            return response()->json(['ok' => false, 'error' => $res['error'] ?? '提交失败'], 422);
        }
        return response()->json([
            'ok'       => true,
            'batch_id' => $res['batch_id'],
            'count'    => $res['count'],
            'stats'    => $this->queue->stats($tenant->id),
        ]);
    }

    /** 单条重试。 */
    public function retry(Request $request, int $id): JsonResponse
    {
        $tenant = $this->studioTenant($request);
        $res = $this->queue->retry($id, $tenant->id);
        return response()->json($res + ['stats' => $this->queue->stats($tenant->id)],
            ($res['ok'] ?? false) ? 200 : 422);
    }

    /** 整批重试失败项。 */
    public function retryBatch(Request $request): JsonResponse
    {
        $data = $request->validate(['batch_id' => ['required', 'string', 'max:32']]);
        $tenant = $this->studioTenant($request);
        $n = $this->queue->retryBatch($data['batch_id'], $tenant->id);
        return response()->json([
            'ok' => true, 'retried' => $n,
            'stats' => $this->queue->stats($tenant->id),
        ]);
    }

    /** 取消单条。 */
    public function cancel(Request $request, int $id): JsonResponse
    {
        $tenant = $this->studioTenant($request);
        $res = $this->queue->cancel($id, $tenant->id);
        return response()->json($res + ['stats' => $this->queue->stats($tenant->id)],
            ($res['ok'] ?? false) ? 200 : 422);
    }

    /** 清空已结束任务。 */
    public function clear(Request $request): JsonResponse
    {
        $tenant = $this->studioTenant($request);
        $n = $this->queue->clearFinished($tenant->id);
        return response()->json(['ok' => true, 'cleared' => $n, 'stats' => $this->queue->stats($tenant->id)]);
    }
}
