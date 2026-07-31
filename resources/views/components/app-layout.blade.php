<!DOCTYPE html>
<html lang="zh-CN" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>慧根堂 · 商用短视频智能工作台</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600&display=swap" rel="stylesheet" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-screen bg-gradient-to-br from-slate-50 via-white to-brand-50 text-slate-800 antialiased
             dark:from-[#0b0d1a] dark:via-[#11132a] dark:to-[#1a1c3a] dark:text-slate-100">
    {{ $slot }}

    @livewireScripts
    @fluxScripts
    <script>
        function toggleTheme(){
            const root = document.documentElement;
            const isDark = root.classList.toggle('dark');
            try { localStorage.setItem('theme', isDark ? 'dark' : 'light'); } catch(e){}
        }
        (function(){
            try {
                const saved = localStorage.getItem('theme');
                if(saved === 'dark'){ document.documentElement.classList.add('dark'); }
                else if(saved === 'light'){ document.documentElement.classList.remove('dark'); }
            } catch(e){}
        })();
    </script>
</body>
</html>
