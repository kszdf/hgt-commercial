<?php

namespace App\Http\Controllers;

use App\Exceptions\PipelineUnavailableException;
use App\Services\PipelineClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * AI 客服自动接待配置控制器。
 *
 * 与 ZhikuController 同构：studioTenant() + PipelineClient::postJson() + 看 ok 字段。
 *
 * 8500 契约：POST /reception-config {platform,industry,greeting,transfer_rule}
 *   → {ok, config, status}
 * 注意：真实自动接待需要在「发布渠道」页完成对应平台的 OAuth 授权后联调，
 *       当前后端只负责持久化配置（诚实返回 status 说明限制）。
 *       /reception-config 无论业务成功失败都回 HTTP 200，必须看 body 的 ok 字段。
 */
class ReceptionController extends Controller
{
    private const RECEPTION_TIMEOUT = 15;

    /** 页面渲染：只带租户/品牌上下文，不查库（配置只读回显由保存后前端展示）。 */
    public function index(Request $request)
    {
        $tenant = $this->studioTenant($request);

        return view('studio.reception.index', [
            'tenant' => $tenant,
            'brand'  => $this->brand($tenant),
        ]);
    }

    /** 保存接待配置：转发到 8500 /reception-config，原样透传字段。 */
    public function save(Request $request)
    {
        $data = $request->validate([
            'platform'      => ['nullable', 'string', 'max:40'],
            'industry'      => ['nullable', 'string', 'max:60'],
            'greeting'      => ['nullable', 'string', 'max:300'],
            'transfer_rule' => ['nullable', 'string', 'max:300'],
        ]);

        try {
            $resp = app(PipelineClient::class)->postJson('/reception-config', [
                'platform'      => (string) ($data['platform'] ?? '全平台'),
                'industry'      => (string) ($data['industry'] ?? ''),
                'greeting'      => (string) ($data['greeting'] ?? ''),
                'transfer_rule' => (string) ($data['transfer_rule'] ?? '意向客户才转(AI 评分≥7)'),
            ], self::RECEPTION_TIMEOUT);
        } catch (PipelineUnavailableException $e) {
            Log::warning('reception save unavailable: ' . $e->getMessage());
            return response()->json([
                'ok'    => false,
                'error' => '接待配置服务暂时不可用：' . $e->getMessage() . ' 若长时间无响应，请确认 8500 服务已运行。',
            ], 503);
        } catch (\Throwable $e) {
            Log::error('reception save failed: ' . $e->getMessage(), ['trace' => substr($e->getTraceAsString(), 0, 600)]);
            return response()->json(['ok' => false, 'error' => '保存接待配置失败：' . $e->getMessage()], 500);
        }

        if (! $resp->successful()) {
            return response()->json([
                'ok'    => false,
                'error' => '接待配置返回异常（HTTP ' . $resp->status() . '）',
            ], 502);
        }

        $j = $resp->json() ?: [];
        if (($j['ok'] ?? false) !== true) {
            return response()->json([
                'ok'    => false,
                'error' => (string) ($j['error'] ?? '接待配置未返回结果'),
            ], 502);
        }

        return response()->json([
            'ok'     => true,
            'config' => (array) ($j['config'] ?? []),
            'status' => (string) ($j['status'] ?? ''),
        ]);
    }

    private function brand($tenant): string
    {
        $settings = is_array($tenant->settings ?? null) ? $tenant->settings : [];
        $brand = trim((string) ($settings['brand'] ?? ''));
        return $brand !== '' ? $brand : (string) config('hgt.brand_fallback', '');
    }
}
