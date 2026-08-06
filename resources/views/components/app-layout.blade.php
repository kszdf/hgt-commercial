<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>追梦 · 商用短视频智能工作台</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600&display=swap" rel="stylesheet" />
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
