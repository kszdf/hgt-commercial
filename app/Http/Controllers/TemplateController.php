<?php

namespace App\Http\Controllers;

use App\Models\ContentTemplate;
use Illuminate\Http\Request;

/**
 * 话术模板市场（财税垂类）：留资钩子 / 爆款开头 / 避坑清单 / 结尾引导 / 选题角度。
 *
 * - 平台级模板（tenant_id=null）：所有租户可见，超管维护；
 * - 租户私有模板（"我的模板"）：用户自己添加 / 收藏（复制平台模板）。
 */
class TemplateController extends Controller
{
    public function index(Request $request)
    {
        $tenant = $this->studioTenant($request);
        $isAdmin = $request->user()->isGlobalAdmin();
        $type = $request->input('type');

        $query = ContentTemplate::where('status', 'active')
            ->where(function ($q) use ($tenant, $isAdmin) {
                $q->whereNull('tenant_id');
                if (! $isAdmin) {
                    $q->orWhere('tenant_id', $tenant->id);
                }
            });
        if ($type && array_key_exists($type, ContentTemplate::TYPES)) {
            $query->where('type', $type);
        }

        $templates = $query->orderByDesc('use_count')->orderByDesc('id')->get();

        return view('studio.templates', [
            'templates' => $templates,
            'currentType' => $type,
            'types' => ContentTemplate::TYPES,
            'isAdmin' => $isAdmin,
        ]);
    }

    /** 新增模板（超管=平台级，普通用户=我的模板）。 */
    public function store(Request $request)
    {
        $tenant = $this->studioTenant($request);
        $isAdmin = $request->user()->isGlobalAdmin();

        $data = $request->validate([
            'type' => ['required', 'string', 'in:' . implode(',', array_keys(ContentTemplate::TYPES))],
            'title' => ['required', 'string', 'max:60'],
            'content' => ['required', 'string', 'max:1000'],
            'tags_text' => ['nullable', 'string', 'max:120'],
        ]);

        ContentTemplate::create([
            'tenant_id' => $isAdmin ? null : $tenant->id,
            'type' => $data['type'],
            'title' => $data['title'],
            'content' => $data['content'],
            'tags' => $this->parseTags($data['tags_text'] ?? ''),
            'status' => 'active',
        ]);

        return redirect()->route('studio.templates')->with('success', '模板已添加。');
    }

    /** 逗号/顿号分隔的标签文本 → 数组。 */
    private function parseTags(string $raw): array
    {
        $tags = preg_split('/[,，、]/u', $raw);
        return array_values(array_filter(array_map('trim', $tags), fn ($t) => $t !== '' && mb_strlen($t) <= 20));
    }

    public function update(Request $request, ContentTemplate $template)
    {
        $this->assertTemplateOwner($request, $template);
        $data = $request->validate([
            'type' => ['sometimes', 'string', 'in:' . implode(',', array_keys(ContentTemplate::TYPES))],
            'title' => ['sometimes', 'string', 'max:60'],
            'content' => ['sometimes', 'string', 'max:1000'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:20'],
        ]);
        $template->update($data);
        return redirect()->route('studio.templates')->with('success', '模板已更新。');
    }

    public function destroy(Request $request, ContentTemplate $template)
    {
        $this->assertTemplateOwner($request, $template);
        $template->delete();
        return redirect()->route('studio.templates')->with('success', '模板已删除。');
    }

    /** 收藏：复制为"我的模板"（平台模板不可改删，收藏后可自由编辑）。 */
    public function copy(Request $request, ContentTemplate $template)
    {
        $tenant = $this->studioTenant($request);
        $isAdmin = $request->user()->isGlobalAdmin();

        // 平台模板或自己租户的模板都可收藏
        if (! $template->isPlatform() && $template->tenant_id !== $tenant->id && ! $isAdmin) {
            abort(403);
        }

        $mine = ContentTemplate::create([
            'tenant_id' => $isAdmin ? null : $tenant->id,
            'type' => $template->type,
            'title' => $template->title . '（收藏）',
            'content' => $template->content,
            'tags' => $template->tags ?? [],
            'status' => 'active',
        ]);
        $template->increment('use_count');

        return redirect()->route('studio.templates')->with('success', '已收藏到我的模板：「' . $mine->title . '」。');
    }

    private function assertTemplateOwner(Request $request, ContentTemplate $template): void
    {
        $user = $request->user();
        if ($user->isGlobalAdmin()) {
            return; // 超管可维护平台级与任意模板
        }
        if ($template->isPlatform()) {
            abort(403, '平台模板仅超级管理员可修改');
        }
        if ($template->tenant_id !== $user->tenant_id) {
            abort(403);
        }
    }
}
