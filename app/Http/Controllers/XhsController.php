<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Services\PipelineClient;

/**
 * 小红书图文笔记控制器：选题+卖点+受众 → 结构化笔记 → 渲染出图 → 预览/发布。
 *
 * 链路：
 *   前端表单 → POST /studio/xhs/generate → 8500 /xhs_generate (DeepSeek + PIL 出图)
 *   → 返回 base64 图片列表 + 结构化笔记 → 前端预览
 *   → POST /studio/xhs/publish → 8500 /publish mode:image → 小红书开放平台发布
 */
class XhsController extends Controller
{
    public function index()
    {
        $user = Auth::user();
        return view('studio.xhs', [
            'isAdmin' => $user->isGlobalAdmin(),
            'tenant' => $this->studioTenant(request()),
        ]);
    }

    /**
     * 调 8500 /xhs_generate，返回结构化笔记 + 渲染图片（base64）。
     */
    public function generate(Request $request)
    {
        $request->validate([
            'topic' => 'required|string|max:200',
            'selling_points' => 'nullable|string|max:1000',
            'audience' => 'nullable|string|max:300',
            'pages' => 'nullable|integer|min:2|max:8',
            'brand' => 'nullable|string|max:60',
        ]);

        try {
            $client = new PipelineClient();
            $resp = $client->postJson('/xhs_generate', [
                'topic' => $request->input('topic'),
                'selling_points' => $request->input('selling_points', ''),
                'audience' => $request->input('audience', ''),
                'brand' => $request->input('brand', '慧根堂 · 老张讲财税'),
                'pages' => (int) ($request->input('pages') ?? 4),
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
     * 发布图文笔记到小红书（8500 /publish mode=image）。
     */
    public function publish(Request $request)
    {
        $request->validate([
            'image_paths' => 'required|array|min:1',
            'image_paths.*' => 'string',
            'title' => 'nullable|string|max:100',
            'description' => 'nullable|string|max:5000',
            'tags' => 'array',
            'tags.*' => 'string',
        ]);

        try {
            $client = new PipelineClient();
            $resp = $client->postJson('/publish', [
                'mode' => 'image',
                'image_paths' => $request->input('image_paths'),
                'platforms' => ['xiaohongshu'],
                'title' => $request->input('title', ''),
                'description' => $request->input('description', ''),
                'tags' => $request->input('tags', []),
                'tenant_id' => auth()->id(),
            ], 60);
        } catch (\Exception $e) {
            return response()->json(['error' => '出片微服务不可达：' . $e->getMessage()], 503);
        }

        if (!$resp->successful()) {
            return response()->json(['error' => '发布失败：' . $resp->body()], $resp->status());
        }

        return response()->json($resp->json());
    }
}
