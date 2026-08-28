<?php
declare(strict_types=1);
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchFoundation\Services\KeywordMetaRepository;
use Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordSourceNormalizer;
use Illuminate\Support\Facades\Schema;
$root = dirname(__DIR__);
require $root."/vendor/autoload.php";
$app = require $root."/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
app(SeoDatabaseConnectionService::class)->bootstrapLegacySharedConnection();

$phrases = [
"Website ACB","Tư vấn phụ kiện thời trang","Công ty may balo vải bố theo yêu cầu",
"Cách giặt balo phao","Vật liệu tái chế trong thời trang","Xu hướng túi oversize","Phối đồ tone-sur-tone",
"Thời trang Techwear","Cách chọn balo gaming","Xu hướng màu neon 2024","Setup góc máy chơi game","Phụ kiện du lịch cho streamer",
"Chiến lược Marketing ngân hàng","Tâm lý học tiêu dùng quà tặng","Xu hướng túi xách 2024","Kỹ năng quản lý tài chính cá nhân","Văn hóa doanh nghiệp ACB",
"Bảo quản phụ kiện thời trang","Thời trang tối giản","Xu hướng thời trang hiện đại",
];
$meta = app(KeywordMetaRepository::class);
$cols = Schema::connection("omi_seo_ai")->getColumnListing("keywords");
echo "keyword columns: ".implode(", ",$cols)."\n\n";
$siteId=7;
echo "candidate|keyword_id|type|source|site_meta|is_seo_keyword?\n";
foreach ($phrases as $p) {
  $prepared = Keyword::preparePhraseForStorage($p);
  $kw = Keyword::query()->whereRaw("phrase COLLATE utf8mb4_unicode_ci = ?", [$prepared])->first();
  if (!$kw) { echo "$prepared|MISSING||||\n"; continue; }
  $has = $meta->keywordHasSiteMeta((int)$kw->id, $siteId) ? "Y":"N";
  $arr = $kw->getAttributes();
  $isSeo = array_key_exists("is_seo_keyword", $arr) ? json_encode($arr["is_seo_keyword"]) : "n/a";
  $hidden = "";
  foreach (["hidden","is_hidden","excluded","is_excluded","deleted_at"] as $h) {
    if (array_key_exists($h, $arr) && $arr[$h] !== null && $arr[$h] !== "" && $arr[$h] !== 0 && $arr[$h] !== false) {
      $hidden .= "$h=".json_encode($arr[$h]).";";
    }
  }
  echo $prepared."|".$kw->id."|".$kw->type."|[".$kw->source."]|".$has."|".$isSeo.($hidden?"|".$hidden:"")."\n";
}
echo "\nCOUNT site7 suggest+ai_generated: ";
$n=0;
foreach (Keyword::query()->where("type",Keyword::TYPE_SUGGEST)->where("source",KeywordSourceNormalizer::AI_GENERATED)->get() as $kw) {
  if ($meta->keywordHasSiteMeta((int)$kw->id,$siteId)) $n++;
}
echo $n."\n";
echo "COUNT type=suggest any source: ".Keyword::query()->where("type",Keyword::TYPE_SUGGEST)->count()."\n";