<?php
// 端到端：批量出片（商用卖点）——走 VideoController::generate 的 batch 分支（并发提交）。
// 用法：docker exec hgt-commercial-app-1 php artisan tinker --execute="require '/var/www/_e2e_batch.php';"

use Illuminate\Http\Request;

$u = \App\Models\User::where('email', '28642235@qq.com')->first();
if (!$u) { echo "NO_USER\n"; return; }

$scripts = [
    '老板们注意，公司账上的钱转给自己，年底不还，税务会视同分红，要补20%个税。',
    '个体户不是不用交税，只是增值税有起征点优惠，超过一样要申报，别被误导。',
];

$req = Request::create('/studio/generate', 'POST', [
    'mode'     => 'card',
    'dialogue' => $scripts[0],
    'batch'    => true,
    'scripts'  => $scripts,
    'title'    => '批量测试',
]);
$req->setLaravelSession(app('session')->driver());
$req->setUserResolver(function () use ($u) { return $u; });
app()->instance('request', $req);
app('auth')->guard('web')->setUser($u);

$ctrl = app(\App\Http\Controllers\VideoController::class);
$resp = app()->call([$ctrl, 'generate']);
$json = $resp instanceof \Illuminate\Http\JsonResponse ? $resp->getData(true) : json_decode((string) $resp->getContent(), true);
echo json_encode($json, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
