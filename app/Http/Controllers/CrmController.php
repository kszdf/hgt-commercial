<?php

namespace App\Http\Controllers;

use App\Exceptions\PipelineUnavailableException;
use App\Services\PipelineClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * 客户档案 CRM 控制器。
 *
 * 与 ZhikuController 同构：
 *   - 租户上下文走基类 studioTenant()（超管回退租户，避免 null → 500）；
 *   - 调 8500 一律走 PipelineClient::postJson()（统一超时/重试/降级）；
 *   - 失败统一返回 {ok:false, error}，由页面 fetch 捕获后展示。
 *
 * 8500 契约：POST /crm
 *   - {action:"list"}                          → {ok,total,by_stage,records[]}
 *   - {name,contact,stage,industry,source,note} → {ok,updated,record,total}
 * 注意：/crm 无论业务成功失败都回 HTTP 200，必须看 body 的 ok 字段。
 */
class CrmController extends Controller
{
    private const CRM_TIMEOUT = 15;

    /** 页面渲染：只带租户/品牌上下文，不查库（列表走异步接口）。 */
    public function index(Request $request)
    {
        $tenant = $this->studioTenant($request);

        return view('studio.crm.index', [
            'tenant' => $tenant,
            'brand'  => $this->brand($tenant),
        ]);
    }

    /** 列表：转发到 8500 /crm {action:list}。 */
    public function list(Request $request)
    {
        try {
            $resp = app(PipelineClient::class)->postJson('/crm', ['action' => 'list'], self::CRM_TIMEOUT);
        } catch (PipelineUnavailableException $e) {
            Log::warning('crm list unavailable: ' . $e->getMessage());
            return response()->json([
                'ok'    => false,
                'error' => 'CRM 服务暂时不可用：' . $e->getMessage() . ' 若长时间无响应，请确认 8500 服务已运行。',
            ], 503);
        } catch (\Throwable $e) {
            Log::error('crm list failed: ' . $e->getMessage(), ['trace' => substr($e->getTraceAsString(), 0, 600)]);
            return response()->json(['ok' => false, 'error' => '获取客户列表失败：' . $e->getMessage()], 500);
        }

        if (! $resp->successful()) {
            return response()->json([
                'ok'    => false,
                'error' => 'CRM 返回异常（HTTP ' . $resp->status() . '）',
            ], 502);
        }

        $j = $resp->json() ?: [];
        if (($j['ok'] ?? false) !== true) {
            return response()->json([
                'ok'    => false,
                'error' => (string) ($j['error'] ?? 'CRM 未返回结果'),
            ], 502);
        }

        return response()->json([
            'ok'       => true,
            'total'    => (int) ($j['total'] ?? 0),
            'by_stage' => (array) ($j['by_stage'] ?? []),
            'records'  => (array) ($j['records'] ?? []),
        ]);
    }

    /** 入档：线索写入 8500 /crm（同名+同联系方式视为同一条，升级阶段不重复入档）。 */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name'     => ['required', 'string', 'max:60'],
            'contact'  => ['nullable', 'string', 'max:60'],
            'stage'    => ['nullable', 'string', 'max:20'],
            'industry' => ['nullable', 'string', 'max:60'],
            'source'   => ['nullable', 'string', 'max:60'],
            'note'     => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $resp = app(PipelineClient::class)->postJson('/crm', [
                'name'     => trim((string) $data['name']),
                'contact'  => (string) ($data['contact'] ?? ''),
                'stage'    => (string) ($data['stage'] ?? '线索'),
                'industry' => (string) ($data['industry'] ?? ''),
                'source'   => (string) ($data['source'] ?? ''),
                'note'     => (string) ($data['note'] ?? ''),
            ], self::CRM_TIMEOUT);
        } catch (PipelineUnavailableException $e) {
            Log::warning('crm store unavailable: ' . $e->getMessage());
            return response()->json([
                'ok'    => false,
                'error' => 'CRM 服务暂时不可用：' . $e->getMessage() . ' 若长时间无响应，请确认 8500 服务已运行。',
            ], 503);
        } catch (\Throwable $e) {
            Log::error('crm store failed: ' . $e->getMessage(), ['trace' => substr($e->getTraceAsString(), 0, 600)]);
            return response()->json(['ok' => false, 'error' => '保存客户失败：' . $e->getMessage()], 500);
        }

        if (! $resp->successful()) {
            return response()->json([
                'ok'    => false,
                'error' => 'CRM 返回异常（HTTP ' . $resp->status() . '）',
            ], 502);
        }

        $j = $resp->json() ?: [];
        if (($j['ok'] ?? false) !== true) {
            return response()->json([
                'ok'    => false,
                'error' => (string) ($j['error'] ?? 'CRM 未返回结果'),
            ], 502);
        }

        return response()->json([
            'ok'      => true,
            'updated' => (bool) ($j['updated'] ?? false),
            'record'  => (array) ($j['record'] ?? []),
            'total'   => (int) ($j['total'] ?? 0),
        ]);
    }

    /**
     * 品牌文案：优先租户 settings.brand，回退全局 config('hgt.brand_fallback')。
     * 不在代码里写死任何具体人设/品牌字样。
     */
    private function brand($tenant): string
    {
        $settings = is_array($tenant->settings ?? null) ? $tenant->settings : [];
        $brand = trim((string) ($settings['brand'] ?? ''));
        return $brand !== '' ? $brand : (string) config('hgt.brand_fallback', '');
    }
}
