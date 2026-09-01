<?php
$urls=[
  'https://api.github.com/repos/trung8356621/wp-seo-ai/releases/latest',
  'https://api.github.com/repos/trung8356621/wp-seo-ai/releases?per_page=5',
];
foreach($urls as $u){
  $ch=curl_init($u);
  curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_USERAGENT=>'omi-accept',CURLOPT_HTTPHEADER=>['Accept: application/vnd.github+json'],CURLOPT_TIMEOUT=>30]);
  $body=curl_exec($ch); $code=curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
  echo "HTTP $code $u\n".substr((string)$body,0,600)."\n\n";
}
