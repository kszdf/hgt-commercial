<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Services\PipelineClient;

/**
 * 小红书图文笔记控制器：选题+卖点+受众 → 结构化笔记 → 渲染出图 → 预览/下载。
 *
 * 链路：
 *   前端表单 → POST /studio/xhs/generate → 8500 /xhs_generate (DeepSeek + PIL 出图)
 *   → 返回 base64 图片列表 + 结构化笔记 → 前端预览/下载。
 *   暂不提供自动发布，生成后请在小红书 App 手动发布。
 */
class XhsController extends Controller
{
    /**
     * 出图品牌：优先取租户 settings.brand，回退租户名。
     * 多租户平台严禁硬编码单一租户品牌（此前写死单一品牌会打到别的租户图上）。
     */
    private function defaultBrand(): string
    {
        $tenant = $this->studioTenant(request());
        $settings = is_array($tenant->settings) ? $tenant->settings : [];
        $brand = trim((string) ($settings['brand'] ?? ''));
        if ($brand !== '') {
            return $brand;
        }
        return $tenant->name ?: '追梦短视频';
    }

    public function index()
    {
        $user = Auth::user();
        return view('studio.xhs', [
            'isAdmin' => $user->isGlobalAdmin(),
            'tenant' => $this->studioTenant(request()),
        ]);
    }

    /**
     * 调 8500 /xhs_build_note，仅生成结构化笔记（不出图）。
     */
    public function buildNote(Request $request)
    {
        $request->validate([
            'topic' => 'required|string|max:200',
            'selling_points' => 'nullable|string|max:5000',
            'audience' => 'nullable|string|max:300',
            'pages' => 'nullable|integer|min:2|max:8',
            'raw_body' => 'nullable|string|max:8000',
        ]);

        try {
            $client = new PipelineClient();
            $resp = $client->postJson('/xhs_build_note', [
                'topic' => $request->input('topic'),
                'selling_points' => $request->input('selling_points', ''),
                'audience' => $request->input('audience', ''),
                'pages' => (int) ($request->input('pages') ?? 4),
                'raw_body' => $request->input('raw_body', ''),
            ], 120);
        } catch (\Exception $e) {
            return response()->json(['error' => '出片微服务不可达：' . $e->getMessage()], 503);
        }

        if (!$resp->successful()) {
            return response()->json(['error' => '生成失败：' . $resp->body()], $resp->status());
        }

        return response()->json($resp->json());
    }

    /**
     * 调 8500 /xhs_generate，基于结构化笔记渲染图片（base64）。
     * 支持传入完整 note，也支持 topic+raw_body 让 8500 先整理再渲染。
     */
    public function generate(Request $request)
    {
        $request->validate([
            'topic' => 'nullable|string|max:200',
            'selling_points' => 'nullable|string|max:5000',
            'audience' => 'nullable|string|max:300',
            'pages' => 'nullable|integer|min:2|max:8',
            'brand' => 'nullable|string|max:60',
            'note' => 'nullable|array',
            'raw_body' => 'nullable|string|max:8000',
        ]);

        if (!$request->input('note') && !$request->input('raw_body') && !$request->input('topic')) {
            return response()->json(['error' => '请填写选题，或先生成/粘贴正文'], 400);
        }

        $payload = [
            'brand' => $request->input('brand', $this->defaultBrand()),
            'pages' => (int) ($request->input('pages') ?? 4),
        ];
        if ($request->has('note')) {
            $payload['note'] = $request->input('note');
        } else {
            $payload['topic'] = $request->input('topic', '');
            $payload['selling_points'] = $request->input('selling_points', '');
            $payload['audience'] = $request->input('audience', '');
            $payload['raw_body'] = $request->input('raw_body', '');
        }

        try {
            $client = new PipelineClient();
            $resp = $client->postJson('/xhs_generate', $payload, 180);
        } catch (\Exception $e) {
            return response()->json(['error' => '出片微服务不可达：' . $e->getMessage()], 503);
        }

        if (!$resp->successful()) {
            return response()->json(['error' => '生成失败：' . $resp->body()], $resp->status());
        }

        return response()->json($resp->json());
    }

    /**
     * 仅重新生成封面（换背景/配色），文字不变。
     */
    public function regenCover(Request $request)
    {
        $request->validate([
            'cover' => 'required|array',
            'cover.title' => 'nullable|string|max:60',
            'cover.subtitle' => 'nullable|string|max:80',
            'cover.tag' => 'nullable|string|max:20',
            'seed' => 'nullable|integer',
            'topic' => 'nullable|string|max:200',
            'selling_points' => 'nullable|string|max:1000',
            'audience' => 'nullable|string|max:300',
            'brand' => 'nullable|string|max:60',
        ]);

        try {
            $client = new PipelineClient();
            $resp = $client->postJson('/xhs_regen_cover', [
                'cover' => $request->input('cover'),
                'seed' => (int) ($request->input('seed') ?? random_int(0, 999999)),
                'topic' => $request->input('topic', ''),
                'selling_points' => $request->input('selling_points', ''),
                'audience' => $request->input('audience', ''),
                'brand' => $request->input('brand', $this->defaultBrand()),
            ], 120);
        } catch (\Exception $e) {
            return response()->json(['error' => '出片微服务不可达：' . $e->getMessage()], 503);
        }

        if (!$resp->successful()) {
            return response()->json(['error' => '重新生成封面失败：' . $resp->body()], $resp->status());
        }

        return response()->json($resp->json());
    }

    /**
     * 打包下载已生成的小红书图文图片（ZIP）。
     * 入参 images 为前端已有的 base64 data URL 数组（含封面与内文页），
     * 直接在后端解码打包，避免依赖磁盘路径（8500 写在 Windows 路径、容器读不到）。
     */
    public function download(Request $request)
    {
        $images = $request->input('images', []);
        if (!is_array($images) || count($images) === 0) {
            return response()->json(['error' => '没有可下载的图片'], 400);
        }
        if (!class_exists(\ZipArchive::class)) {
            return response()->json(['error' => '服务器未启用 Zip 支持'], 500);
        }
        $tmp = tempnam(sys_get_temp_dir(), 'xhs_') . '.zip';
        $zip = new \ZipArchive();
        if ($zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            return response()->json(['error' => '无法创建压缩包'], 500);
        }
        $added = 0;
        foreach ($images as $i => $dataUrl) {
            if (!is_string($dataUrl)) {
                continue;
            }
            if (!preg_match('/^data:image\/png;base64,(.+)$/s', $dataUrl, $m)) {
                continue;
            }
            $bin = base64_decode($m[1], true);
            if ($bin === false || $bin === '') {
                continue;
            }
            $name = ($i === 0 ? 'cover' : 'page_' . $i) . '.png';
            $zip->addFromString($name, $bin);
            $added++;
        }
        $zip->close();
        if ($added === 0) {
            @unlink($tmp);
            return response()->json(['error' => '图片数据无效'], 400);
        }
        return response()->download($tmp, 'xiaohongshu_' . date('Ymd_His') . '.zip')->deleteFileAfterSend(true);
    }
}
