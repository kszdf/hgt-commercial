<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', '追梦 · 商用短视频智能工作台')</title>
    <meta name="description" content="追梦 — 面向企业的短视频智能生产 SaaS 平台，提供智能选题、文案二创、数字人出片、配音、字幕与多平台分发能力。">
    <meta name="theme-color" content="#928eea">
    {{-- 对话工作台等页面迭代极快，浏览器缓存旧 HTML/内联 JS 会导致"修复后前端仍空白/没反应"的假象，必须禁止页面缓存。 --}}
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <meta property="og:type" content="website">
    <meta property="og:title" content="追梦 · 商用短视频智能工作台">
    <meta property="og:description" content="面向企业的短视频智能生产 SaaS 平台：选题、二创、出片、配音、字幕、分发一站式。">
    <meta property="og:image" content="/images/logo.jpg">
    <link rel="icon" href="/images/logo.jpg">
    {{-- 2026-09-11 性能修复：移除国外字体站 fonts.bunny.net 引用。
         该域名实测国内访问 1s 起步、网络差时超时 5-15s，会阻塞首屏渲染，
         是"打开网页特别慢"的主因之一。改用系统字体栈（见 app.css --font-sans），
         中文场景反而更清晰，且零外部请求。 --}}
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-screen bg-white text-slate-800 antialiased">
    {{ $slot }}

    @livewireScripts
    @fluxScripts
    @stack('scripts')
    <script>
        // 主题切换
        function toggleTheme(){
            const root = document.documentElement;
            const isDark = root.classList.toggle('dark');
            try {
                localStorage.setItem('theme', isDark ? 'dark' : 'light');
                localStorage.setItem('theme-v', '2'); // 版本号，用于失效旧缓存
            } catch(e){}
        }
        // 初始化：默认强制亮色；仅当版本匹配且用户主动选过 dark 时才恢复
        (function(){
            try {
                const THEME_VERSION = '2'; // 升级主题时改此值，旧 dark 缓存自动失效
                const saved = localStorage.getItem('theme');
                const savedV = localStorage.getItem('theme-v') || '0';
                if (saved === 'dark' && savedV === THEME_VERSION) {
                    document.documentElement.classList.add('dark');
                } else {
                    // 默认亮色：清除残留的 dark class 和旧缓存
                    document.documentElement.classList.remove('dark');
                    localStorage.setItem('theme', 'light');
                    localStorage.setItem('theme-v', THEME_VERSION);
                }
            } catch(e){
                // 无 localStorage 时默认亮色
                document.documentElement.classList.remove('dark');
            }
        })();
    </script>
</body>
</html>
