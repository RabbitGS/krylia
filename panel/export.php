<?php
// Экспорт фида квартир для площадок. Доступ:
//   - по ключу ?key=<feed_key>  (площадки забирают фид по постоянному URL, без пароля)
//   - либо Basic-auth (ручное скачивание из панели)
// Параметры: ?format=yrl|csv|json  &  ?include=free|all  (по умолчанию free — только доступные)
//
// Пример URL для площадки: https://krylia-tver.ru/panel/export.php?format=yrl&key=XXXX
require_once __DIR__ . '/_boot.php';

$format  = strtolower($_GET['format'] ?? 'yrl');
$include = ($_GET['include'] ?? 'free') === 'all' ? 'all' : 'free';

// доступ: ключ или Basic
$cfg = panel_cfg();
$key = $_GET['key'] ?? '';
$byKey = isset($cfg['feed_key']) && is_string($key) && $key !== '' && hash_equals((string)$cfg['feed_key'], $key);
if (!$byKey) panel_require_auth();

$catalog = load_catalog();
$flats = merged_flats($catalog);
// квартиры без данных (проданные вне фида) на площадки не выгружаем никогда
$flats = array_values(array_filter($flats, fn($f) => empty($f['nodata'])));
if ($include === 'free') {
  // на площадки уходят доступные к покупке: свободные + акционные (акция = та же продажа со скидкой)
  $flats = array_values(array_filter($flats, fn($f) => in_array(($f['status'] ?? 'free'), ['free', 'promo'], true)));
}
$meta = require __DIR__ . '/export_meta.php';

$bnames = [];
foreach (($catalog['genplan']['buildings'] ?? []) as $b) $bnames[$b['id']] = $b['name'] ?? $b['id'];

function plan_url($f, $meta) {
  return isset($f['plan']) ? rtrim($meta['plans_base'], '/') . '/' . ltrim($f['plan'], '/') : '';
}

// ---------- ГЕНЕРАТОРЫ ----------
function gen_yrl($flats, $meta, $bnames) {
  $x = new XMLWriter();
  $x->openMemory();
  $x->setIndent(true);
  $x->startDocument('1.0', 'UTF-8');
  $x->startElement('realty-feed');
  $x->writeAttribute('xmlns', 'http://webmaster.yandex.ru/schemas/feed/realty/2010-06');
  $x->writeElement('generation-date', date('c'));
  foreach ($flats as $f) {
    $x->startElement('offer');
    $x->writeAttribute('internal-id', (string)($f['id'] ?? ''));
    $x->writeElement('type', 'продажа');
    $x->writeElement('property-type', 'жилая');
    $x->writeElement('category', 'квартира');
    $x->writeElement('url', $meta['site']);
    $x->writeElement('creation-date', date('c'));
    $x->writeElement('new-flat', 'да');
    // локация
    $x->startElement('location');
    $x->writeElement('country', $meta['country']);
    $x->writeElement('region', $meta['region']);
    $x->writeElement('locality-name', $meta['locality']);
    $x->writeElement('address', $meta['address']);
    $x->writeElement('latitude', $meta['latitude']);
    $x->writeElement('longitude', $meta['longitude']);
    $x->endElement();
    // цена
    $x->startElement('price');
    $x->writeElement('value', (string)(int)($f['price'] ?? 0));
    $x->writeElement('currency', 'RUB');
    $x->endElement();
    // площадь
    $x->startElement('area');
    $x->writeElement('value', (string)($f['area'] ?? ''));
    $x->writeElement('unit', 'кв.м');
    $x->endElement();
    $x->writeElement('rooms', (string)(int)($f['rooms'] ?? 0));
    $x->writeElement('floor', (string)(int)($f['floor'] ?? 0));
    $x->writeElement('floors-total', (string)(int)$meta['floors_total']);
    $x->writeElement('building-name', $meta['complex']);
    if (isset($f['building']) && isset($bnames[$f['building']])) $x->writeElement('building-section', $bnames[$f['building']]);
    $x->writeElement('deal-status', $meta['deal_status']);
    $x->writeElement('building-state', 'unfinished');
    $x->writeElement('ready-quarter', '4');
    $x->writeElement('built-year', '2027');
    $plan = plan_url($f, $meta);
    if ($plan) { $x->startElement('image'); $x->writeAttribute('tag', 'plan'); $x->text($plan); $x->endElement(); }
    // агент/застройщик
    $x->startElement('sales-agent');
    $x->writeElement('phone', $meta['phone']);
    $x->writeElement('organization', $meta['developer']);
    $x->writeElement('category', 'developer');
    $x->endElement();
    $x->endElement(); // offer
  }
  $x->endElement(); // realty-feed
  $x->endDocument();
  return $x->outputMemory();
}

function gen_csv($flats, $meta, $bnames) {
  $fh = fopen('php://temp', 'r+');
  fputcsv($fh, ['id','номер','подъезд','этаж','комнат','площадь','цена','статус','планировка','адрес','телефон'], ',', '"', '');
  foreach ($flats as $f) {
    fputcsv($fh, [
      $f['id'] ?? '', $f['number'] ?? '', $bnames[$f['building']] ?? ($f['building'] ?? ''),
      $f['floor'] ?? '', $f['rooms'] ?? '', $f['area'] ?? '', $f['price'] ?? '', $f['status'] ?? '',
      plan_url($f, $meta), $meta['locality'] . ', ' . $meta['address'], $meta['phone'],
    ], ',', '"', '');
  }
  rewind($fh);
  $out = stream_get_contents($fh);
  fclose($fh);
  return "\xEF\xBB\xBF" . $out;   // BOM для Excel
}

function gen_json($flats, $meta, $bnames) {
  $out = [];
  foreach ($flats as $f) {
    $out[] = [
      'id' => $f['id'] ?? '', 'number' => $f['number'] ?? '',
      'section' => $bnames[$f['building']] ?? ($f['building'] ?? ''),
      'floor' => (int)($f['floor'] ?? 0), 'rooms' => (int)($f['rooms'] ?? 0),
      'area' => $f['area'] ?? null, 'price' => (int)($f['price'] ?? 0),
      'status' => $f['status'] ?? 'free', 'plan' => plan_url($f, $meta),
    ];
  }
  return json_encode([
    'complex' => $meta['complex'], 'developer' => $meta['developer'],
    'address' => $meta['locality'] . ', ' . $meta['address'],
    'phone' => $meta['phone'], 'generated' => date('c'), 'count' => count($out),
    'offers' => $out,
  ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}

// журнал: факт выгрузки. Заборы площадкой (по ключу) дедуплицируем — не чаще раза в час на формат,
// чтобы частые автозаборы не засоряли ленту. Ручное скачивание (Basic) логируем всегда.
log_event('feed_export', [
  'format' => $format, 'key' => $format . ($byKey ? '|pull' : '|manual'),
  'count' => count($flats), 'src' => $byKey ? 'площадка (авто)' : 'вручную',
], $byKey ? 3600 : 0);

// ---------- ОТДАЧА ----------
if ($format === 'csv') {
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="krylia-feed.csv"');
  echo gen_csv($flats, $meta, $bnames);
} elseif ($format === 'json') {
  header('Content-Type: application/json; charset=utf-8');
  echo gen_json($flats, $meta, $bnames);
} else { // yrl по умолчанию
  header('Content-Type: application/xml; charset=utf-8');
  echo gen_yrl($flats, $meta, $bnames);
}
