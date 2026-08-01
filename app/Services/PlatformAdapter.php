<?php

namespace App\Services;

use App\Models\PlatformAccount;
use App\Models\Tenant;
use App\Models\VideoJob;
use App\Models\MetricDaily;
use Illuminate\Support\Facades\Auth;

/**
 * 平台数据适配器抽象层。
 *
 * 各短视频平台（视频号/抖音/小红书）的数据拉取方式不同：
 *   - wechat/douyin/xiaohongshu 需要 OAuth 授权后调用开放平台接口；
 *   - manual 为手动录入（无需授权）。
 *
 * 当前仅 manual 落地，其余平台返回「需授权」占位，待接入真实开放平台 SDK。
 */
abstract class PlatformAdapter
{
    /** 平台标识，与 platform_accounts.platform 一致。 */
    abstract public function platform(): string;

    /** 是否已具备拉取条件（如已授权）。 */
    abstract public function isReady(PlatformAccount $account): bool;

    /** 拉取某出片任务在 [start,end] 区间的每日指标，返回待写入数组。 */
    abstract public function fetchDaily(PlatformAccount $account, VideoJob $job, string $start, string $end): array;

    /** 授权入口 URL（OAuth 跳转）。未实现返回 null。 */
    public function oauthUrl(PlatformAccount $account): ?string
    {
        return null;
    }

    /**
     * 工厂：按平台返回对应适配器实例。
     * 未知/未实现平台统一回退到 ManualAdapter（标注需授权）。
     */
    public static function make(string $platform): self
    {
        return match ($platform) {
            'wechat', 'douyin', 'xiaohongshu' => new ManualAdapter($platform),
            default => new ManualAdapter('manual'),
        };
    }

    /**
     * 将一批指标 upsert 进 metrics_daily（按 video × platform × date 唯一键）。
     * 采用 firstOrNew + 覆盖，避免重复导入报错。
     */
    protected function upsert(Tenant $tenant, array $rows): int
    {
        $count = 0;
        foreach ($rows as $r) {
            $row = MetricDaily::firstOrNew([
                'video_job_id' => $r['video_job_id'] ?? null,
                'platform'     => $r['platform'],
                'metric_date'  => $r['metric_date'],
            ]);
            $row->tenant_id = $tenant->id;
            $row->views    = $r['views']    ?? 0;
            $row->shares   = $r['shares']   ?? 0;
            $row->comments = $r['comments'] ?? 0;
            $row->likes    = $r['likes']    ?? 0;
            $row->save();
            $count++;
        }
        return $count;
    }

    /** 当前登录租户（便捷方法）。 */
    protected function tenant(): Tenant
    {
        return Auth::user()->tenant;
    }
}
