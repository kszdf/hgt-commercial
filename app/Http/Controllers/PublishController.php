<?php

namespace App\Http\Controllers;

use App\Exceptions\PipelineUnavailableException;
use App\Models\CoverAsset;
use App\Models\PlatformAccount;
use App\Models\PublishRecord;
use App\Models\VideoJob;
use App\Services\PipelineClient;
use App\Services\PublishRunner;
use Illuminate\Http\Request;

/**
 * 发布助手：一键发布 + 导出素材。
 *
 * 一键发布按平台特点分级（诚实透明）：
 *  - 自动：公众号（入草稿箱）、抖音（OAuth）、小红书（OAuth 笔记）—— 直接分发；
 *  - 人工：视频号 / B站 / 快手 / YouTube —— 无稳定公开 API，存入「待人工发布」清单，
 *          成片仍可下载，到各平台 App 手动发表。
 */
class PublishController extends Controller
{
    /** 发布助手：已完成成片 + 可发布账号 + 各视频发布记录。 */
    public function index()
    {
        $tenant = $this->studioTenant(request());

        $videos = VideoJob::where('tenant_id', $tenant->id)
            ->where('status', 'done')
            ->orderByDesc('updated_at')
            ->get();

        // 可发布账号：自动平台需已授权，人工平台恒可
        $accounts = PlatformAccount::where('tenant_id', $tenant->id)
            ->orderBy('platform')
            ->get()
            ->filter(fn ($a) => $a->isPublishable());

        // 各视频的发布记录（按 video_job_id 分组，供前端展示已发/待人工）
        $publishRecords = PublishRecord::where('tenant_id', $tenant->id)
            ->whereIn('video_job_id', $videos->pluck('id'))
            ->orderByDesc('created_at')
            ->get()
            ->groupBy('video_job_id');

        return view('studio.publish', compact('videos', 'accounts', 'publishRecords'));
    }

    /** 一键发布：视频 × 账号 → PublishRunner → 自动/待人工/失败。 */
    public function publish(Request $request, VideoJob $videoJob)
    {
        $tenant = $this->studioTenant($request);
        $this->assertTenantOwner($request, $videoJob->tenant_id);

        // 审核门槛：只有「已通过审核」的成片可外发
        if (! $videoJob->canPublish()) {
            return redirect()->route('studio.publish')
                ->with('error', '该视频尚未审核通过，请先到「人工审核」通过后再发布。');
        }

        $account = PlatformAccount::where('tenant_id', $tenant->id)
            ->where('id', $request->input('platform_account_id'))
            ->first();

        if (! $account) {
            return redirect()->route('studio.publish')->with('error', '请选择要发布的账号。');
        }
        if (! $account->isPublishable()) {
            return redirect()->route('studio.publish')
                ->with('error', '该账号尚未授权，请先在「发布渠道」完成授权。');
        }

        $r = app(PublishRunner::class)->run($videoJob, $account, $tenant);

        if (! empty($r['ok'])) {
            $extra = ! empty($r['simulated']) ? '（模拟发布，未真正发出）' : '';
            return redirect()->route('studio.publish')
                ->with('success', "已发布到 {$account->platformLabel()} · {$account->account_name}{$extra}");
        }
        if (! empty($r['manual'])) {
            return redirect()->route('studio.publish')
                ->with('info', "{$account->platformLabel()} 无自动发布接口，已存入「待人工发布」清单，请下载成片后到 App 手动发表。");
        }
        return redirect()->route('studio.publish')
            ->with('error', '发布失败：' . ($r['reason'] ?? '未知错误'));
    }

    /** 公众号图文文章发布页：标题 + 正文 + 封面（从封面库选）。 */
    public function article()
    {
        $tenant = $this->studioTenant(request());

        $wechatAccounts = PlatformAccount::where('tenant_id', $tenant->id)
            ->where('platform', 'wechat')
            ->where('status', 'authorized')
            ->get();

        $covers = CoverAsset::where(function ($q) use ($tenant) {
            $q->where('tenant_id', $tenant->id)->orWhere('is_preset', true);
        })->orderByDesc('is_preset')->orderByDesc('updated_at')->get();

        return view('studio.article', compact('wechatAccounts', 'covers'));
    }

    /** 提交公众号图文文章 → 8500 mode=article → 入草稿箱。 */
    public function articleSend(Request $request)
    {
        $tenant = $this->studioTenant($request);

        $data = $request->validate([
            'platform_account_id' => ['required', 'integer'],
            'title' => ['required', 'string', 'max:64'],
            'content' => ['required', 'string', 'max:20000'],
            'cover_asset_id' => ['nullable', 'integer'],
        ]);

        $account = PlatformAccount::where('tenant_id', $tenant->id)
            ->where('id', $data['platform_account_id'])
            ->where('platform', 'wechat')
            ->first();

        if (! $account || ! $account->isAuthorized()) {
            return redirect()->route('studio.article')->with('error', '请先选择已授权的公众号账号（在「发布渠道」填好 AppID/AppSecret）。');
        }

        // 封面：封面库（容器内路径）→ 宿主绝对路径，供 8500 读取
        $coverPath = '';
        if (! empty($data['cover_asset_id'])) {
            $cover = CoverAsset::where('tenant_id', $tenant->id)->find($data['cover_asset_id']);
            if ($cover) {
                $coverPath = $this->hostPath($cover->path());
            }
        }
        if ($coverPath === '') {
            return redirect()->route('studio.article')->with('error', '请选择封面图（公众号草稿必须指定封面）。');
        }

        // 公众号凭证经 extra 下发（明文不出 Laravel 容器，同视频发布链路）
        $payload = [
            'mode' => 'article',
            'platforms' => ['wechat'],
            'account_key' => 'wechat:' . $account->id,
            'title' => $data['title'],
            'description' => $data['content'],
            'cover_path' => $coverPath,
        ];
        $payload = array_merge($payload, app(PublishRunner::class)->credentialsExtra($account));

        try {
            $resp = app(PipelineClient::class)->postJson('/publish', $payload, 180);
        } catch (PipelineUnavailableException $e) {
            return redirect()->route('studio.article')->with('error', '出片服务不可达：' . $e->getMessage());
        }
        if ($resp->failed()) {
            return redirect()->route('studio.article')->with('error', '发布失败：' . substr((string) $resp->body(), 0, 300));
        }

        $results = $resp->json('results') ?: [];
        $result = collect($results)->firstWhere('platform', 'wechat') ?: ($results[0] ?? []);
        $status = $result['status'] ?? 'failed';
        $simulated = ! empty($result['simulated'])
            || ($status === 'published' && empty($result['post_id']) && empty($result['url']));

        if ($status === 'published') {
            return redirect()->route('studio.article')->with('success',
                $simulated
                    ? '模拟发布成功（未真正入草稿箱，请确认公众号 AppID/AppSecret 与 IP 白名单已配好）。'
                    : '已入公众号草稿箱，请到 mp.weixin.qq.com 后台确认后群发。');
        }
        return redirect()->route('studio.article')->with('error', '发布失败：' . ($result['error'] ?? '未知错误'));
    }

    /** 封面库容器路径 → 宿主绝对路径（8500 跑在 Windows 宿主，读 D:/heygem_data/...）。 */
    private function hostPath(string $containerPath): string
    {
        return str_replace(
            [storage_path('app'), '/var/www/storage/app'],
            'D:/heygem_data/hgt-commercial/storage/app',
            $containerPath
        );
    }
}
