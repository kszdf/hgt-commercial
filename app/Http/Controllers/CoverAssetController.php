<?php

namespace App\Http\Controllers;

use App\Models\CoverAsset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Storage;

/**
 * 封面素材管理：上传（格式/大小校验）、预览、删除、重新上传，租户隔离。
 * 图片走纯 Laravel 处理（容器内存储 + Response::file 直出），不依赖宿主 8500。
 */
class CoverAssetController extends Controller
{
    private function tenantId(): int
    {
        return Auth::user()->tenant_id;
    }

    public function index()
    {
        $assets = CoverAsset::where('tenant_id', $this->tenantId())->latest()->get();

        // 平台预设封面：读取 manifest 拿到分类顺序与中文标签，再按分类分组
        $presetCategories = [];
        $manifestPath = Storage::disk('local')->path('covers/presets/manifest.json');
        $order = [];
        if (is_file($manifestPath)) {
            $manifest = json_decode(file_get_contents($manifestPath), true);
            $order = $manifest['categories'] ?? [];
        }
        $presets = CoverAsset::where('is_preset', true)
            ->orderBy('category')->orderBy('id')
            ->get(['id', 'name', 'category', 'file_path', 'width', 'height', 'status']);
        foreach ($order as $cat) {
            $slug = $cat['slug'];
            $items = $presets->where('category', $slug)->values();
            $presetCategories[] = [
                'slug' => $slug,
                'label' => $cat['label'] ?? $slug,
                'covers' => $items,
            ];
        }
        // manifest 未涵盖的分类兜底
        $seen = collect($order)->pluck('slug')->all();
        foreach ($presets->whereNotIn('category', $seen)->groupBy('category') as $slug => $items) {
            $presetCategories[] = ['slug' => $slug, 'label' => $slug, 'covers' => $items->values()];
        }

        return view('studio.covers', compact('assets', 'presetCategories'));
    }

    public function coversJson()
    {
        $mine = CoverAsset::where('tenant_id', $this->tenantId())
            ->latest()
            ->get(['id', 'name', 'scene', 'file_path', 'width', 'height', 'status', 'is_preset']);
        $presets = CoverAsset::where('is_preset', true)
            ->orderBy('category')->orderBy('id')
            ->get(['id', 'name', 'category', 'file_path', 'width', 'height', 'status', 'is_preset']);

        $map = fn ($a) => [
            'id' => $a->id,
            'name' => $a->name,
            'scene' => $a->scene,
            'category' => $a->category,
            'is_preset' => (bool) $a->is_preset,
            'preview' => route('studio.covers.preview', $a),
            'width' => $a->width,
            'height' => $a->height,
        ];

        return response()->json([
            'ok' => true,
            'covers' => $mine->map($map)->values(),
            'presets' => $presets->map($map)->values(),
        ]);
    }

    /**
     * 收藏平台预设封面到「我的封面」：复制图片文件到租户目录并新建一条租户私有记录。
     * 预设封面本身已可在出片时直接选用，此操作用于「想自行改图/重传」的场景。
     */
    public function pickPreset(Request $request, CoverAsset $coverAsset)
    {
        abort_if(! $coverAsset->is_preset, 404);

        $user = $request->user();
        $tenant = $this->studioTenant(request());
        $src = Storage::disk('local')->path(ltrim($coverAsset->file_path, '/'));
        abort_if(! is_file($src), 404);

        $ext = strtolower(pathinfo($coverAsset->file_path, PATHINFO_EXTENSION) ?: 'svg');
        $rel = 'covers/' . $tenant->id . '/cv_' . uniqid() . '.' . $ext;
        Storage::disk('local')->copy(ltrim($coverAsset->file_path, '/'), $rel);
        $abs = Storage::disk('local')->path($rel);

        $asset = CoverAsset::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'name' => $coverAsset->name . '（收藏）',
            'scene' => $coverAsset->category,
            'file_path' => $rel,
            'preview_path' => $rel,
            'width' => $coverAsset->width,
            'height' => $coverAsset->height,
            'size' => filesize($abs),
            'status' => 'ready',
            'is_preset' => false,
        ]);

        $coverAsset->increment('use_count');

        if ($request->wantsJson()) {
            return response()->json(['ok' => true, 'id' => $asset->id, 'name' => $asset->name]);
        }
        return redirect()->route('studio.covers')->with('success', '已收藏到我的封面：' . $asset->name);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $tenant = $this->studioTenant(request());

        $request->validate([
            'file' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
            'name' => ['nullable', 'string', 'max:60'],
            'scene' => ['nullable', 'string', 'max:40'],
        ]);

        $ext = strtolower($request->file('file')->getClientOriginalExtension() ?: 'jpg');
        $rel = 'covers/' . $tenant->id . '/cv_' . uniqid() . '.' . $ext;
        $request->file('file')->storeAs(dirname($rel), basename($rel), 'local');
        $abs = Storage::disk('local')->path($rel);

        [$w, $h] = @getimagesize($abs) ?: [null, null];

        $asset = CoverAsset::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'name' => $request->input('name') ?: ($request->file('file')->getClientOriginalName() ?: '封面'),
            'scene' => $request->input('scene') ?: null,
            'file_path' => $rel,
            'preview_path' => $rel,
            'width' => $w,
            'height' => $h,
            'size' => filesize($abs),
            'status' => 'ready',
        ]);

        return redirect()->route('studio.covers')->with('success', '封面已上传：' . $asset->name);
    }

    public function preview(CoverAsset $coverAsset)
    {
        // 预设封面全局可读；租户封面仅本人可读
        abort_if(! $coverAsset->is_preset && $coverAsset->tenant_id !== $this->tenantId(), 403);
        $path = Storage::disk('local')->path(ltrim($coverAsset->file_path, '/'));
        abort_if(! is_file($path), 404);
        return Response::file($path);
    }

    public function destroy(CoverAsset $coverAsset)
    {
        abort_if($coverAsset->is_preset, 403); // 预设封面不可删
        abort_if($coverAsset->tenant_id !== $this->tenantId(), 403);
        $path = Storage::disk('local')->path(ltrim($coverAsset->file_path, '/'));
        if (is_file($path)) {
            @unlink($path);
        }
        $coverAsset->delete();
        return redirect()->route('studio.covers')->with('success', '封面已删除');
    }

    public function reupload(Request $request, CoverAsset $coverAsset)
    {
        abort_if($coverAsset->is_preset, 403); // 预设封面不可改
        abort_if($coverAsset->tenant_id !== $this->tenantId(), 403);
        $request->validate([
            'file' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
        ]);

        $ext = strtolower($request->file('file')->getClientOriginalExtension() ?: 'jpg');
        $rel = 'covers/' . $coverAsset->tenant_id . '/cv_' . uniqid() . '.' . $ext;
        $request->file('file')->storeAs(dirname($rel), basename($rel), 'local');
        $abs = Storage::disk('local')->path($rel);
        [$w, $h] = @getimagesize($abs) ?: [null, null];

        $coverAsset->update([
            'file_path' => $rel,
            'preview_path' => $rel,
            'width' => $w,
            'height' => $h,
            'size' => filesize($abs),
            'status' => 'ready',
        ]);

        return redirect()->route('studio.covers')->with('success', '封面已重新上传：' . $coverAsset->name);
    }
}
