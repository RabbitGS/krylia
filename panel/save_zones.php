<?php
// Сохранение разметки контуров этажей из редактора floors.php.
// Пишет panel/floor_zones.json — widget.php накладывает floorpin НА ЛЕТУ,
// поэтому пересобирать ничего не нужно: витрина обновится при следующем запросе.
require_once __DIR__ . '/_boot.php';
panel_require_auth();

header('Content-Type: text/plain; charset=utf-8');
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) { http_response_code(400); echo 'плохой JSON'; exit; }

$path = __DIR__ . '/floor_zones.json';
if (file_exists($path)) @copy($path, __DIR__ . '/floor_zones.bak.json');
$out = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if (@file_put_contents($path, $out) === false) { http_response_code(500); echo 'не удалось записать'; exit; }

log_event('floor_zones_save', ['key' => 'floors', 'zones' => array_sum(array_map('count', $data))], 0);
echo 'сохранено ✓ — витрина обновится сразу';
