<?php
// Автозаполнение данных проданных-фантомов (nodata) по стояку — через оверлей apply_meta.
// Донор — ближайшая по этажу квартира того же подъезда и стояка с данными из фида.
// Данные ложатся в shahmatka_state.json (переживают импорт фида, catalog.json не трогается).
// Запуск ТОЛЬКО из CLI: php autofill_meta.php           — dry-run (отчёт, ничего не пишет)
//                       php autofill_meta.php --apply   — записать через apply_meta
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/_boot.php';

$apply = in_array('--apply', $argv, true);
$flats = merged_flats();   // каталог + оверлей: уже заполненные вручную не трогаем

// доноры по ключу подъезд|стояк
$byStack = [];
foreach ($flats as $f) {
  if (!empty($f['nodata']) || !isset($f['rooms'], $f['area'])) continue;
  $byStack[$f['building'] . '|' . $f['riser']][] = $f;
}

$filled = 0; $skipped = [];
foreach ($flats as $f) {
  if (empty($f['nodata'])) continue;
  $donors = $byStack[$f['building'] . '|' . $f['riser']] ?? [];
  if (!$donors) { $skipped[] = $f['number']; continue; }
  usort($donors, fn($a, $b) => abs($a['floor'] - $f['floor']) <=> abs($b['floor'] - $f['floor']));
  $don = $donors[0];
  $meta = ['rooms' => $don['rooms'], 'area' => $don['area'],
           'plan' => $don['plan'] ?? '', 'finishing' => $don['finishing'] ?? ''];
  printf("№%-4s %-4s эт %-2d ← №%s (эт %d): %dк · %s м² · %s\n",
    $f['number'], $f['building'], $f['floor'],
    $don['number'], $don['floor'], $don['rooms'], $don['area'], $don['plan'] ?? '—');
  if ($apply) {
    list($ok, $err) = apply_meta($f['id'], $meta, 'автозаполнение по стояку');
    if (!$ok) { echo "  !! ошибка: $err\n"; continue; }
  }
  $filled++;
}

echo "\nЗаполнено: $filled" . ($skipped ? '; без донора: ' . implode(', ', $skipped) : '') . "\n";
echo $apply ? "ЗАПИСАНО в оверлей (shahmatka_state.json)\n" : "(dry-run, ничего не записано)\n";
if ($apply) log_event('meta_autofill', ['count' => $filled, 'by' => 'cli']);
