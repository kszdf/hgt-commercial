<?php
define('LARAVEL_START', microtime(true));
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
$j = \App\Models\VideoJob::where('job_id', '8a8f8afb62e7434ebd0451606ab1427a')->first();
if (!$j) { echo "NOT_FOUND"; exit; }
echo "id=" . $j->id
   . " job_id=" . $j->job_id
   . " status=" . $j->status
   . " step=" . ($j->step ?? '-')
   . " publish=" . ($j->publish_status ?? '-')
   . " tenant=" . $j->tenant_id . "\n";
