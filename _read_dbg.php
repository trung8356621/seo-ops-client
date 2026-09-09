<?php
$lines = file('D:/work/omnichannel-client/debug-16b727.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
foreach ($lines as $l) {
  $j = json_decode($l, true);
  if (!is_array($j)) continue;
  $m = (string)($j['message'] ?? '');
  if (!str_contains($m, 'handoff') && !str_contains($m, 'shape_') && !str_contains($m, 'content_shape') && !str_contains($m, 'outline_shape')) continue;
  $d = $j['data'] ?? [];
  echo $m
    .' vocab='.($d['vocabulary_len'] ?? '-')
    .' outline='.($d['outline_len'] ?? '-')
    .' hasH2='.json_encode($d['outline_has_h2'] ?? null)
    .' preview='.substr((string)($d['outline_preview'] ?? ''), 0, 70)
    .' reused='.json_encode($d['reused'] ?? null)
    .' profile='.($d['profile'] ?? '')
    .PHP_EOL;
}