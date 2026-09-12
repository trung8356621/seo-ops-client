<?php
require __DIR__."/vendor/autoload.php";
$app=require __DIR__."/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\Auth;
use App\Models\SeoDatabaseConnection;
use Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService;
use Omnichannel\Addons\AiPrompt\Models\SeoTask;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Illuminate\Support\Facades\DB;
Auth::loginUsingId(2);
$rec=SeoDatabaseConnection::query()->where("is_active",true)->first();
app(SeoDatabaseConnectionService::class)->bootstrapFromConnection($rec);
$p=SeoPrompt::find(5);
echo "prompt5 hook=".$p->hook_key." name=".$p->name."\n";
// list active tasks mentioning content generate
$tasks=SeoTask::query()->where("is_active",1)->orderBy("id")->get(["id","name","is_active"]);
foreach($tasks as $t){ echo "task {$t->id} {$t->name}\n"; }
// settings table keys
foreach(["seo_settings","settings","omi_settings"] as $table){
  try {
    $rows=DB::connection("omi_seo_ai")->table($table)->where("key","like","%publish%")->orWhere("key","like","%task%")->limit(30)->get();
    echo "table $table count=".$rows->count()."\n";
    foreach($rows as $r){ echo json_encode($r, JSON_UNESCAPED_UNICODE)."\n"; }
  } catch(Throwable $e){ echo "table $table err=".$e->getMessage()."\n"; }
}
