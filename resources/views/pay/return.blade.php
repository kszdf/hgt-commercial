<x-app-layout>
<div class="mx-auto flex min-h-[60vh] max-w-md flex-col items-center justify-center p-6 text-center">
    <div class="mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-emerald-50">
        <svg class="h-8 w-8 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
        </svg>
    </div>
    <h2 class="text-xl font-semibold text-slate-800">支付已完成</h2>
    <p class="mt-2 text-sm text-slate-500">
        您的套餐正在生效，稍后将自动跳转至工作台。
    </p>
    <a href="/dashboard" class="mt-6 rounded-lg bg-brand-500 px-5 py-2.5 text-sm font-medium text-white hover:bg-brand-600 transition">
        进入工作台
    </a>
    <script>setTimeout(function(){ window.location.href = '/dashboard'; }, 3000);</script>
</div>
</x-app-layout>
