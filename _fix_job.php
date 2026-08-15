<?php
define('LARAVEL_START', microtime(true));
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
$j = \App\Models\VideoJob::where('job_id', '8a8f8afb62e7434ebd0451606ab1427a')->first();
if (!$j) { echo "NOT_FOUND"; exit; }
$j->status = 'done';
if (is_null($j->publish_status)) $j->publish_status = 'draft';
$j->save();
echo "FIXED id=" . $j->id
   . " status=" . $j->status
   . " publish=" . $j->publish_status . "\n";
