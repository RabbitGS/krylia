<?php
// Эндпоинт данных для витрины-шахматки (на скрытом поддомене / лендинге).
// Отдаёт JSON в формате, который понимает shahmatka.js (как data.json витрины),
// но с ЖИВЫМИ статусами из панели и НОМЕРНОЙ раскладкой стояков из каталога.
//
// Доступ:
//   ?key=<feed_key>   — по ключу (постоянный URL для витрины, без пароля)
//   либо Basic-auth   — для проверки из панели
// Параметры:
//   ?include=all|free (по умолчанию all — витрине нужны и проданные, для сетки)
//
// URL для витрины: https://<поддомен>/panel/widget.php?key=XXXX
require_once __DIR__ . '/_boot.php';

// доступ: ключ или Basic
$cfg = panel_cfg();
$key = $_GET['key'] ?? '';
$byKey = isset($cfg['feed_key']) && is_string($key) && $key !== '' && hash_equals((string)$cfg['feed_key'], $key);
if (!$byKey) panel_require_auth();

$include = ($_GET['include'] ?? 'all') === 'free' ? 'free' : 'all';

$catalog = load_catalog();
$flats = merged_flats($catalog);            // каталог + живые статусы
// техническая бронь — внутренний статус панели: наружу (на сайт) отдаём как «продана»
foreach ($flats as &$tf) if (($tf['status'] ?? '') === 'tech') $tf['status'] = 'sold';
unset($tf);
if ($include === 'free') {
  $flats = array_values(array_filter($flats, fn($f) => in_array(($f['status'] ?? 'free'), ['free', 'promo'], true)));
}

// наложить контуры этажей (floorpin), если разметка перенесена в панель (шаг B)
$zpath = __DIR__ . '/floor_zones.json';
if (is_file($zpath)) {
  require_once __DIR__ . '/floorpins.php';
  attach_floorpins($flats, json_decode(file_get_contents($zpath), true) ?: []);
}

// собрать ответ в формате витрины (data.json). Всё, кроме flats, берём из каталога.
$out = $catalog;
$out['flats'] = $flats;
$out['_source'] = 'panel';
$out['_generated'] = date('c');
unset($out['_imported_from'], $out['_imported_at'], $out['catalog']);
unset($out['statuses']['tech']);   // внутренний статус панели — витрине не нужен

// CORS: витрина на другом (под)домене должна иметь право читать
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
