<?php

namespace App\Services;

use App\Exceptions\PipelineUnavailableException;
use App\Models\PipelineQueueJob;
use App\Models\Tenant;
use Illuminate\Support\Str;

/**
 * 任务队列：把 8500 的长任务（AI 端点）做成「批量提交 → 排队 → 并发受控执行 → 看板可查」。
 *
 * 三条硬约束（都是为了在单机 + Cloudflare Tunnel 环境下不翻车）：
 *  1) 并发闸：8500 单进程、AI 端点实测 120~280 秒，同时扔 20 条必然打爆。
 *     所以 queued → running 的放行数量受 PIPELINE_QUEUE_CONCURRENCY 限制（默认 2）。
 *  2) 结果落库：8500 的 /async 作业「取走即清」且存内存，服务重启即丢。
 *     这里一旦轮询到 done 立刻写库快照，之后看板只读本地，不再依赖 8500。
 *  3) 超时兜底：running 超过 25 分钟仍未完成（或 8500 侧作业已消失）判失败，
 *     标记可重试，避免看板出现永远不会结束的僵尸任务。
 */
class PipelineQueue
{
    /** 支持的批量任务类型：kind => [显示名, 8500 端点, 输入形态]。 */
    public const KINDS = [
        'hot'        => ['label' => '追爆款', 'path' => '/dissect',    'input' => 'text',    'chain' => 'rewrite', 'tip' => '每行一个对标链接/文案：自动拆结构，再仿写成你自己的稿'],
        'rewrite'    => ['label' => '二创改写', 'path' => '/rewrite',   'input' => 'text',    'tip' => '每行一条原始稿，逐条改写'],
        'topic'      => ['label' => '智能选题', 'path' => '/topic',     'input' => 'keyword', 'tip' => '每行一个行业/关键词，逐组出选题'],
        'dissect'    => ['label' => '爆款拆解', 'path' => '/dissect',   'input' => 'text',    'tip' => '每行一个链接或文案，只做结构拆解不仿写'],
        'qc'         => ['label' => '内容质检', 'path' => '/qc',        'input' => 'text',    'tip' => '每行一条待检稿，逐条过红线'],
        'strategist' => ['label' => '获客军师', 'path' => '/strategist','input' => 'text',    'tip' => '每行一个标题或脚本，逐条评估'],
    ];

    /** running 超过这个分钟数判失败（8500 侧最长任务约 5 分钟，留足余量）。 */
    private const RUNNING_MAX_MINUTES = 25;

    /** 单租户可同时跑的任务数（并发闸）。 */
    private function concurrency(): int
    {
        return max(1, min(10, (int) env('PIPELINE_QUEUE_CONCURRENCY', 2)));
    }

    /** 单批最多多少条，防一次灌爆。 */
    public function maxBatch(): int
    {
        return max(1, min(100, (int) env('PIPELINE_QUEUE_MAX_BATCH', 30)));
    }

    /**
     * 批量提交：items 为非空行数组，逐条落库为 queued。
     *
     * @return array{ok:bool, batch_id:string, count:int, error?:string}
     */
    public function submit(Tenant $tenant, ?int $userId, string $kind, array $items, int $priority = 5, array $common = []): array
    {
        if (! isset(self::KINDS[$kind])) {
            return ['ok' => false, 'batch_id' => '', 'count' => 0, 'error' => '不支持的任务类型：' . $kind];
        }
        $items = array_values(array_filter(array_map(
            fn ($v) => trim((string) $v),
            $items
        ), fn ($v) => $v !== ''));

        if (empty($items)) {
            return ['ok' => false, 'batch_id' => '', 'count' => 0, 'error' => '没有可提交的内容'];
        }
        if (count($items) > $this->maxBatch()) {
            return ['ok' => false, 'batch_id' => '', 'count' => 0,
                'error' => '一次最多提交 ' . $this->maxBatch() . ' 条，当前 ' . count($items) . ' 条'];
        }

        $spec    = self::KINDS[$kind];
        $batchId = substr(Str::uuid()->toString(), 0, 8);
        $now     = now();

        $rows = [];
        foreach ($items as $item) {
            $rows[] = [
                'tenant_id' => $tenant->id,
                'user_id'   => $userId,
                'batch_id'  => $batchId,
                'kind'      => $kind,
                'path'      => $spec['path'],
                'chain'     => $spec['chain'] ?? null,   // 跑完自动接下一步（如 拆爆款→仿写）
                'title'     => mb_substr($item, 0, 40),
                'input'     => $item,
                'payload'   => json_encode($this->buildPayload($kind, $item, $common), JSON_UNESCAPED_UNICODE),
                'status'    => PipelineQueueJob::STATUS_QUEUED,
                'priority'  => $priority,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        PipelineQueueJob::insert($rows);

        // 提交后立刻尝试放行，第一批不用等前端轮询
        $this->dispatch($tenant->id);

        return ['ok' => true, 'batch_id' => $batchId, 'count' => count($rows)];
    }

    /** 按 kind 组装 8500 需要的 body（参数名与各端点实现严格对应）。 */
    private function buildPayload(string $kind, string $item, array $common = []): array
    {
        $industry = $common['industry'] ?? null;
        $platform = $common['platform'] ?? null;

        return match ($kind) {
            'rewrite'    => array_filter([
                'text'     => $item,
                'industry' => $industry,
                'mode'     => $common['mode'] ?? null,
            ]),
            'topic'      => array_filter([
                'keywords' => $item,
                'industry' => $industry,
                'count'    => (int) ($common['count'] ?? 6),
                'platform' => $platform,
            ]),
            'hot'        => array_filter([
                'text'     => $item,
                'industry' => $industry,
                'platform' => $platform,
            ]),
            'dissect'    => array_filter([
                'text'     => $item,
                'industry' => $industry,
                'platform' => $platform,
            ]),
            'qc'         => array_filter([
                'text'     => $item,
                'platform' => $platform,
            ]),
            'strategist' => array_filter([
                'script'   => $item,
                'industry' => $industry,
                'platform' => $platform,
            ]),
            default      => ['text' => $item],
        };
    }

    /**
     * 调度：把 queued 放行成 running，放行数量受并发闸限制；优先级小的先跑。
     */
    public function dispatch(?int $tenantId = null): void
    {
        $q = PipelineQueueJob::query()->where('status', PipelineQueueJob::STATUS_RUNNING);
        if ($tenantId) {
            $q->where('tenant_id', $tenantId);
        }
        $running = $q->count();
        $slots   = $this->concurrency() - $running;
        if ($slots <= 0) {
            return;
        }

        $pending = PipelineQueueJob::query()
            ->where('status', PipelineQueueJob::STATUS_QUEUED)
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->orderBy('priority')
            ->orderBy('id')
            ->limit($slots)
            ->get();

        $client = app(PipelineClient::class);
        foreach ($pending as $job) {
            try {
                $resp = $client->submitAsync($job->path, $job->payload ?: [], 20);
            } catch (PipelineUnavailableException $e) {
                // 服务不可用：不消耗尝试次数，留在队列里等下一次调度
                return;
            }
            $json = $resp->json() ?: [];
            if (! $resp->successful() || empty($json['job_id'])) {
                $job->update([
                    'status'      => PipelineQueueJob::STATUS_FAILED,
                    'attempts'    => $job->attempts + 1,
                    'error'       => '提交失败（HTTP ' . $resp->status() . '）',
                    'finished_at' => now(),
                ]);
                continue;
            }
            $job->update([
                'status'        => PipelineQueueJob::STATUS_RUNNING,
                'remote_job_id' => (string) $json['job_id'],
                'attempts'      => $job->attempts + 1,
                'started_at'    => now(),
                'error'         => null,
            ]);
        }
    }

    /**
     * 轮询同步：把 running 的作业向 8500 问一次，done/failed 写库。
     */
    public function sync(?int $tenantId = null): void
    {
        $running = PipelineQueueJob::query()
            ->where('status', PipelineQueueJob::STATUS_RUNNING)
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->orderBy('id')
            ->limit(50)
            ->get();

        if ($running->isEmpty()) {
            return;
        }

        $client = app(PipelineClient::class);
        foreach ($running as $job) {
            // 没拿到远程任务号（异常态）：退回队列重新排队
            if (empty($job->remote_job_id)) {
                $job->update(['status' => PipelineQueueJob::STATUS_QUEUED]);
                continue;
            }

            // 超时兜底：跑太久直接判失败，允许人工重试
            if ($job->started_at && $job->started_at->diffInMinutes(now()) > self::RUNNING_MAX_MINUTES) {
                $job->update([
                    'status'      => PipelineQueueJob::STATUS_FAILED,
                    'error'       => '任务超时（超过 ' . self::RUNNING_MAX_MINUTES . ' 分钟未完成），可点重试',
                    'finished_at' => now(),
                ]);
                continue;
            }

            try {
                $resp = $client->statusAsync($job->remote_job_id, 15);
            } catch (PipelineUnavailableException $e) {
                continue;   // 服务抖动：下一轮再问，不误判失败
            }
            if (! $resp->successful()) {
                continue;
            }
            $json = $resp->json() ?: [];
            $st   = $json['status'] ?? '';

            if ($st === 'pending') {
                continue;
            }
            if ($st === 'not_found') {
                // 8500 侧作业没了（多半是服务重启）：开始 90 秒后仍找不到才判失败
                if ($job->started_at && $job->started_at->diffInSeconds(now()) > 90) {
                    $job->update([
                        'status'      => PipelineQueueJob::STATUS_FAILED,
                        'error'       => '后台服务重启导致任务丢失，可点重试',
                        'finished_at' => now(),
                    ]);
                }
                continue;
            }
            if ($st !== 'done') {
                continue;
            }

            $result = $json['result'] ?? null;
            $code   = (int) ($json['code'] ?? 200);
            $ok     = $code >= 200 && $code < 300 && ! (is_array($result) && ($result['ok'] ?? true) === false);

            $job->update([
                'status'      => $ok ? PipelineQueueJob::STATUS_DONE : PipelineQueueJob::STATUS_FAILED,
                'result'      => is_array($result) ? $result : null,
                'error'       => $ok ? null : (is_array($result) ? ($result['error'] ?? ('后台返回 HTTP ' . $code)) : ('后台返回 HTTP ' . $code)),
                'finished_at' => now(),
            ]);

            // 链式：这一步成了才接下一步（拆爆款成功 → 自动仿写成自己的稿）
            if ($ok) {
                $this->spawnChain($job);
            }
        }
    }

    /**
     * 派生链式任务：拿上一步结果里的正文，建下一步作业。
     * 结果里没拿到可用正文就安静结束，不报错——避免看板出现莫名失败。
     */
    private function spawnChain(PipelineQueueJob $job): void
    {
        $next = $job->chain;
        if (! $next) {
            return;
        }
        // 无论成败都先清掉 chain，防止重复派生
        $job->update(['chain' => null]);

        if (! isset(self::KINDS[$next])) {
            return;
        }
        $r = $job->result ?: [];
        $text = null;
        foreach (['text', 'transcript', 'script', 'raw'] as $k) {
            if (! empty($r[$k]) && is_string($r[$k]) && mb_strlen(trim($r[$k])) > 10) {
                $text = trim($r[$k]);
                break;
            }
        }
        if (! $text) {
            return;
        }

        PipelineQueueJob::create([
            'tenant_id'  => $job->tenant_id,
            'user_id'    => $job->user_id,
            'batch_id'   => $job->batch_id,
            'kind'       => $next,
            'path'       => self::KINDS[$next]['path'],
            'chain'      => null,               // 只串一级，不无限接力
            'parent_id'  => $job->id,
            'title'      => mb_substr($text, 0, 40),
            'input'      => $text,
            'payload'    => $this->buildPayload($next, $text, []),
            'status'     => PipelineQueueJob::STATUS_QUEUED,
            'priority'   => $job->priority,
        ]);
    }

    /** 失败重试：重置为排队态，重新排队（优先级提到较高，让重试尽快跑）。 */
    public function retry(int $id, ?int $tenantId = null): array
    {
        $job = $this->findOwned($id, $tenantId);
        if (! $job) {
            return ['ok' => false, 'error' => '任务不存在'];
        }
        if (! in_array($job->status, [PipelineQueueJob::STATUS_FAILED, PipelineQueueJob::STATUS_CANCELED], true)) {
            return ['ok' => false, 'error' => '只有失败或已取消的任务可以重试'];
        }
        $job->update([
            'status'        => PipelineQueueJob::STATUS_QUEUED,
            'remote_job_id' => null,
            'error'         => null,
            'result'        => null,
            'priority'      => min((int) $job->priority, 3),
            'started_at'    => null,
            'finished_at'   => null,
        ]);
        $this->dispatch($job->tenant_id);
        return ['ok' => true];
    }

    /** 取消：仅排队中的可直接取消，运行中的标记为取消（8500 侧无法强杀，等它自然结束不再取结果）。 */
    public function cancel(int $id, ?int $tenantId = null): array
    {
        $job = $this->findOwned($id, $tenantId);
        if (! $job) {
            return ['ok' => false, 'error' => '任务不存在'];
        }
        if ($job->isFinished()) {
            return ['ok' => false, 'error' => '任务已结束，无需取消'];
        }
        $job->update([
            'status'      => PipelineQueueJob::STATUS_CANCELED,
            'finished_at' => now(),
        ]);
        $this->dispatch($job->tenant_id);
        return ['ok' => true];
    }

    /** 批量重试一整批失败项。 */
    public function retryBatch(string $batchId, ?int $tenantId = null): int
    {
        $q = PipelineQueueJob::query()
            ->where('batch_id', $batchId)
            ->whereIn('status', [PipelineQueueJob::STATUS_FAILED, PipelineQueueJob::STATUS_CANCELED]);
        if ($tenantId) {
            $q->where('tenant_id', $tenantId);
        }
        $n = 0;
        foreach ($q->get() as $job) {
            if ($this->retry($job->id, $tenantId)['ok'] ?? false) {
                $n++;
            }
        }
        return $n;
    }

    /** 清空已结束的任务（看板保持清爽）。 */
    public function clearFinished(?int $tenantId = null): int
    {
        $q = PipelineQueueJob::query()->whereIn('status', [
            PipelineQueueJob::STATUS_DONE,
            PipelineQueueJob::STATUS_FAILED,
            PipelineQueueJob::STATUS_CANCELED,
        ]);
        if ($tenantId) {
            $q->where('tenant_id', $tenantId);
        }
        return $q->delete();
    }

    /** 看板统计。 */
    public function stats(?int $tenantId = null): array
    {
        $q = PipelineQueueJob::query();
        if ($tenantId) {
            $q->where('tenant_id', $tenantId);
        }
        $counts = (clone $q)->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status')->all();

        return [
            'queued'   => (int) ($counts[PipelineQueueJob::STATUS_QUEUED] ?? 0),
            'running'  => (int) ($counts[PipelineQueueJob::STATUS_RUNNING] ?? 0),
            'done'     => (int) ($counts[PipelineQueueJob::STATUS_DONE] ?? 0),
            'failed'   => (int) ($counts[PipelineQueueJob::STATUS_FAILED] ?? 0),
            'canceled' => (int) ($counts[PipelineQueueJob::STATUS_CANCELED] ?? 0),
            'concurrency' => $this->concurrency(),
        ];
    }

    private function findOwned(int $id, ?int $tenantId): ?PipelineQueueJob
    {
        $q = PipelineQueueJob::query()->where('id', $id);
        if ($tenantId) {
            $q->where('tenant_id', $tenantId);
        }
        return $q->first();
    }
}
