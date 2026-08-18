<?php

namespace App\Http\Controllers;

use App\Exceptions\PipelineUnavailableException;
use App\Services\PipelineClient;
use Illuminate\Http\Request;

/**
 * 内容矩阵（功能包三）：一个选题，一键产出三种形态——视频口播稿 / 小红书图文 / 朋友圈文案。
 *
 * 链路（全部经 8500）：
 *  - 视频稿：POST /rewrite（改写为可配音口播稿，带痛点钩子+留资引导）；
 *  - 小红书图文：POST /xhs_generate（结构化笔记 + PIL 渲染封面/内文，可直接发布）；
 *  - 朋友圈文案：POST /moment（3 版：悬念/数据/故事，各带行动引导）。
 * 三个能力独立触发、独立展示，避免一次等待过长。
 */
class MatrixController extends Controller
{
    public function index()
    {
        return view('studio.matrix');
    }

    /** 生成视频口播稿。 */
    public function generateVideo(Request $request)
    {
        $data = $request->validate([
            'topic' => ['required', 'string', 'max:200'],
            'selling_points' => ['nullable', 'string', 'max:1000'],
            'form' => ['sometimes', 'string', 'in:avatar,scroll_male,scroll_female,scroll_dual'],
        ]);

        $mode = $data['form'] ?? 'scroll_male';
        $text = $data['topic'];
        if (! empty($data['selling_points'])) {
            $text .= "\n\n核心卖点：" . $data['selling_points'];
        }
        $text .= "\n\n（请扩写成可直接配音的口播稿：开头用老板痛点做钩子，中间讲清楚，结尾引导留资咨询）";

        try {
            $resp = app(PipelineClient::class)->post('/rewrite', [
                'text' => $text,
                'mode' => $mode === 'scroll_dual' ? 'dual' : 'single',
                'target_duration' => 60,
            ], 120);
        } catch (PipelineUnavailableException $e) {
            return response()->json(['error' => '出片服务暂时不可用，请稍后重试'], 503);
        }
        if (! $resp->successful()) {
            return response()->json(['error' => '生成失败，请确认微服务已启动'], 502);
        }
        $r = $resp->json();

        return response()->json([
            'ok' => true,
            'mode' => $mode,
            'rewritten' => $r['rewritten'] ?? '',
            'cleaned' => $r['cleaned'] ?? ($r['rewritten'] ?? ''),
            'hits' => $r['hits'] ?? [],
            'meta' => $r['meta'] ?? [],
        ]);
    }

    /** 生成小红书图文（文案 + 渲染图，可直接发布）。 */
    public function generateXhs(Request $request)
    {
        $data = $request->validate([
            'topic' => ['required', 'string', 'max:200'],
            'selling_points' => ['nullable', 'string', 'max:1000'],
            'audience' => ['nullable', 'string', 'max:300'],
            'pages' => ['nullable', 'integer', 'between:2,8'],
        ]);

        try {
            $resp = app(PipelineClient::class)->postJson('/xhs_generate', [
                'topic' => $data['topic'],
                'selling_points' => $data['selling_points'] ?? '',
                'audience' => $data['audience'] ?? '中小企业老板 / 创业者',
                'pages' => (int) ($data['pages'] ?? 4),
            ], 120);
        } catch (PipelineUnavailableException $e) {
            return response()->json(['error' => '图文服务暂时不可用，请稍后重试'], 503);
        }
        if (! $resp->successful()) {
            return response()->json(['error' => '生成失败：' . substr((string) $resp->body(), 0, 200)], 502);
        }
        $r = $resp->json();

        return response()->json([
            'ok' => true,
            'note' => $r['note'] ?? [],
            'images' => $r['images'] ?? [],
            'image_paths' => $r['image_paths'] ?? [],
            'count' => $r['count'] ?? 0,
        ]);
    }

    /** 生成朋友圈文案（3 版）。 */
    public function generateMoment(Request $request)
    {
        $data = $request->validate([
            'topic' => ['required', 'string', 'max:200'],
            'selling_points' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $resp = app(PipelineClient::class)->post('/moment', [
                'topic' => $data['topic'],
                'selling_points' => $data['selling_points'] ?? '',
            ], 90);
        } catch (PipelineUnavailableException $e) {
            return response()->json(['error' => '文案服务暂时不可用，请稍后重试'], 503);
        }
        if (! $resp->successful()) {
            return response()->json(['error' => '生成失败'], 502);
        }
        $r = $resp->json();
        if (($r['ok'] ?? false) === false) {
            return response()->json(['error' => $r['error'] ?? 'AI 文案生成失败'], 200);
        }

        return response()->json(['ok' => true, 'items' => $r['items'] ?? []]);
    }

    /** 直接发布矩阵页生成的小红书图文（复用 xhs 发布链路）。 */
    public function publishXhs(Request $request)
    {
        $data = $request->validate([
            'image_paths' => ['required', 'array', 'min:1'],
            'image_paths.*' => ['string'],
            'title' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:5000'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:20'],
        ]);

        try {
            $resp = app(PipelineClient::class)->postJson('/publish', [
                'mode' => 'image',
                'image_paths' => $data['image_paths'],
                'platforms' => ['xiaohongshu'],
                'title' => $data['title'] ?? '',
                'description' => $data['description'] ?? '',
                'tags' => $data['tags'] ?? [],
                'tenant_id' => (string) auth()->id(),
            ], 60);
        } catch (PipelineUnavailableException $e) {
            return response()->json(['error' => '发布服务暂时不可用'], 503);
        }
        if (! $resp->successful()) {
            return response()->json(['error' => '发布失败：' . substr((string) $resp->body(), 0, 200)], $resp->status());
        }
        $r = $resp->json();
        $simulated = collect(($r['results'] ?? []))->contains(fn ($x) => ! empty($x['simulated']))
            || collect(($r['results'] ?? []))->contains(fn ($x) => ($x['status'] ?? '') === 'published' && empty($x['post_id']) && empty($x['url']));

        return response()->json([
            'ok' => true,
            'simulated' => $simulated,
            'results' => $r['results'] ?? [],
        ]);
    }
}
