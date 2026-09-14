<?php

namespace App\Http\Controllers;

use App\Exceptions\PipelineUnavailableException;
use App\Services\PipelineClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * 老张 1v1 视频诊断预约控制器。
 *
 * 与 ZhikuController 同构：studioTenant() + PipelineClient::postJson() + 看 ok 字段。
 *
 * 8500 契约：POST /booking {topic,contact,industry,annual_revenue}
 *   → {ok, booking, queue_position, remaining_this_month, note}
 *     （名额满时 {ok:false, waitlist:true, error}）
 * 注意：/booking 无论业务成功失败都回 HTTP 200，必须看 body 的 ok 字段。
 */
class BookingController extends Controller
{
    private const BOOKING_TIMEOUT = 15;

    /** 页面渲染：只带租户/品牌上下文，不查库。 */
    public function index(Request $request)
    {
        $tenant = $this->studioTenant($request);

        return view('studio.booking.index', [
            'tenant' => $tenant,
            'brand'  => $this->brand($tenant),
        ]);
    }

    /** 提交预约：转发到 8500 /booking，原样透传字段。 */
    public function store(Request $request)
    {
        $data = $request->validate([
            'topic'          => ['required', 'string', 'max:200'],
            'contact'        => ['required', 'string', 'max:60'],
            'industry'       => ['nullable', 'string', 'max:60'],
            'annual_revenue' => ['nullable', 'string', 'max:40'],
        ]);

        try {
            $resp = app(PipelineClient::class)->postJson('/booking', [
                'topic'          => trim((string) $data['topic']),
                'contact'        => trim((string) $data['contact']),
                'industry'       => (string) ($data['industry'] ?? ''),
                'annual_revenue' => (string) ($data['annual_revenue'] ?? ''),
            ], self::BOOKING_TIMEOUT);
        } catch (PipelineUnavailableException $e) {
            Log::warning('booking store unavailable: ' . $e->getMessage());
            return response()->json([
                'ok'    => false,
                'error' => '预约服务暂时不可用：' . $e->getMessage() . ' 若长时间无响应，请确认 8500 服务已运行。',
            ], 503);
        } catch (\Throwable $e) {
            Log::error('booking store failed: ' . $e->getMessage(), ['trace' => substr($e->getTraceAsString(), 0, 600)]);
            return response()->json(['ok' => false, 'error' => '提交预约失败：' . $e->getMessage()], 500);
        }

        if (! $resp->successful()) {
            return response()->json([
                'ok'    => false,
                'error' => '预约返回异常（HTTP ' . $resp->status() . '）',
            ], 502);
        }

        $j = $resp->json() ?: [];
        if (($j['ok'] ?? false) !== true) {
            // 名额满等可预期业务失败：透传 waitlist/error，让页面给出候补提示
            return response()->json([
                'ok'       => false,
                'waitlist' => (bool) ($j['waitlist'] ?? false),
                'error'    => (string) ($j['error'] ?? '预约未返回结果'),
            ], 422);
        }

        return response()->json([
            'ok'                   => true,
            'booking'              => (array) ($j['booking'] ?? []),
            'queue_position'       => (int) ($j['queue_position'] ?? 0),
            'remaining_this_month' => (int) ($j['remaining_this_month'] ?? 0),
            'note'                 => (string) ($j['note'] ?? ''),
        ]);
    }

    private function brand($tenant): string
    {
        $settings = is_array($tenant->settings ?? null) ? $tenant->settings : [];
        $brand = trim((string) ($settings['brand'] ?? ''));
        return $brand !== '' ? $brand : (string) config('hgt.brand_fallback', '');
    }
}
