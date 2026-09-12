<?php
require __DIR__."/vendor/autoload.php";
$app=require __DIR__."/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\Auth;
use App\Models\SeoDatabaseConnection;
use Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRunItem;
Auth::loginUsingId(2);
$rec=SeoDatabaseConnection::query()->where("is_active",true)->first();
app(SeoDatabaseConnectionService::class)->bootstrapFromConnection($rec);
$i=SeoProjectRunItem::query()->where("run_id",191)->first();
echo "err=".$i->error_message."\n";
echo "msg=".($i->message??"")."\n";
