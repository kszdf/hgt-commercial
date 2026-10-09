<?php
// 端到端：逐页真实渲染（走 Controller，不走裸 view），验证出片相关页面可用。
// 用法：docker exec hgt-commercial-app-1 php artisan tinker --execute="require '/var/www/_e2e_pages.php';"

use Illuminate\Http\Request;

$email = '28642235@qq.com';
$u = \App\Models\User::where('email', $email)->first();
if (!$u) {
    echo "NO_USER\n";
    return;
}

$targets = [
    ['/studio/factory',  \App\Http\Controllers\StudioController::class, 'factory'],
    ['/studio/scroll',   \App\Http\Controllers\VideoController::class, 'showScroll'],
    ['/studio/videos',   \App\Http\Controllers\VideoController::class, 'library'],
    ['/studio/queue',    \App\Http\Controllers\QueueController::class, 'index'],
    ['/studio/models',   \App\Http\Controllers\ModelAssetController::class, 'index'],
    ['/studio/voices',   \App\Http\Controllers\VoiceCloneController::class, 'index'],
    ['/studio/footage',  \App\Http\Controllers\FootageController::class, 'index'],
    ['/studio/templates',\App\Http\Controllers\TemplateController::class, 'index'],
    ['/studio/covers',   \App\Http\Controllers\CoverAssetController::class, 'index'],
    ['/studio/dissect',  \App\Http\Controllers\StudioController::class, 'dissect'],
    ['/studio/qc',       \App\Http\Controllers\StudioController::class, 'qc'],
    ['/studio/review',   \App\Http\Controllers\ReviewController::class, 'index'],
    ['/studio/publish',  \App\Http\Controllers\PublishController::class, 'index'],
    ['/studio/accounts', \App\Http\Controllers\AccountController::class, 'index'],
];

$pass = 0;
$fail = 0;
foreach ($targets as [$url, $cls, $method]) {
    try {
        app()->forgetInstance('request');
        $req = Request::create($url, 'GET');
        $req->setLaravelSession(app('session')->driver());
        $req->setUserResolver(function () use ($u) { return $u; });
        app()->instance('request', $req);
        app('auth')->guard('web')->setUser($u);
        $ctrl = app($cls);
        $resp = app()->call([$ctrl, $method]);
        $code = $resp instanceof \Illuminate\Http\Response ? $resp->getStatusCode() : 'view';
        $html = (string) ($resp instanceof \Illuminate\Http\Response ? $resp->getContent() : $resp->render());
        $ok = ($code === 200 || $code === 'view') && strlen($html) > 200;
        if ($ok) { $pass++; } else { $fail++; }
        printf("%-4s %-20s %-6s %d bytes\n", $ok ? 'PASS' : 'FAIL', $url, (string) $code, strlen($html));
        if (!$ok) {
            echo "     └─ " . substr(strip_tags($html), 0, 300) . "\n";
        }
    } catch (\Throwable $e) {
        $fail++;
        printf("%-4s %-20s %s\n", 'FAIL', $url, get_class($e) . ': ' . substr($e->getMessage(), 0, 200));
    }
}
printf("\n页面渲染 %d/%d 通过\n", $pass, $pass + $fail);
