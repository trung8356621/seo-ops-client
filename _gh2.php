<?php
$ch=curl_init('https://api.github.com/repos/trung8356621/wp-seo-ai/releases/tags/1.0.86');
curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_USERAGENT=>'omi',CURLOPT_HTTPHEADER=>['Accept: application/vnd.github+json']]);
$j=json_decode(curl_exec($ch),true); curl_close($ch);
foreach(($j['assets']??[]) as $a){ echo ($a['name']??'')." => ".($a['browser_download_url']??'')."\n"; }
echo "zipball=".($j['zipball_url']??'')."\n";
