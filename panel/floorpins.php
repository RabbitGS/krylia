<?php
// Привязка контуров этажей (floor_zones) к квартирам: каждой квартире
// floorpin = {image, poly:[[x,y]...]} (или {image,x,y} для точки-заглушки).
// Логика идентична витринному floorpins.py / save_zones.php.

function fp_parse_label($lbl) {
  preg_match('/п\s*(\d+)/u', $lbl, $m); $ent = (int)($m[1] ?? 0);
  preg_match('/(\d+)\s*,\s*(\d+)/u', $lbl, $a);
  $area = round((float)(($a[1] ?? '0') . '.' . ($a[2] ?? '0')), 2);
  return [$ent, $area];
}
function fp_key($kind, $ent, $area) { return $kind . '|' . $ent . '|' . number_format($area, 2, '.', ''); }

// Дополняет $flats (по ссылке) полем floorpin по разметке $zones.
function attach_floorpins(array &$flats, array $zones) {
  // индекс зон: (kind,ent,area) -> [ {image, poly|x,y}, ... ] в порядке файла
  $zi = [];
  foreach ($zones as $pf => $list) {
    $kind = str_ends_with($pf, '_g.webp') ? 'g' : 'typ';
    foreach ($list as $z) {
      list($ent, $area) = fp_parse_label($z['label']);
      $k = fp_key($kind, $ent, $area);
      $rec = ['image' => $pf];
      if (count($z['pts']) > 1) $rec['poly'] = $z['pts'];
      else { $rec['x'] = $z['pts'][0][0]; $rec['y'] = $z['pts'][0][1]; }
      $zi[$k][] = $rec;
    }
  }
  // порядок стояков внутри (kind,ent,area) — фантомы без площади пропускаем
  $ord = [];
  foreach ($flats as $f) {
    if (!isset($f['area']) || $f['area'] === null) continue;
    $ent = (int)ltrim($f['building'], 'p'); $kind = ($f['floor'] ?? 0) == 1 ? 'g' : 'typ';
    $ord[fp_key($kind, $ent, round($f['area'], 2))][$f['riser'] ?? 0] = true;
  }
  foreach ($ord as $k => &$v) { $v = array_keys($v); sort($v); } unset($v);
  // привязка по (kind,ent,area) + порядковый стояк
  foreach ($flats as &$f) {
    unset($f['floorpin']);
    if (!isset($f['area']) || $f['area'] === null) continue;   // фантом — без контура
    $ent = (int)ltrim($f['building'], 'p'); $kind = ($f['floor'] ?? 0) == 1 ? 'g' : 'typ';
    $k = fp_key($kind, $ent, round($f['area'], 2));
    if (empty($zi[$k])) continue;
    $order = $ord[$k] ?? [];
    $idx = array_search($f['riser'] ?? 0, $order); if ($idx === false) $idx = 0;
    $z = $zi[$k][$idx] ?? end($zi[$k]);
    $f['floorpin'] = isset($z['poly'])
      ? ['image' => $z['image'], 'poly' => $z['poly']]
      : ['image' => $z['image'], 'x' => $z['x'], 'y' => $z['y']];
  }
  unset($f);
}
