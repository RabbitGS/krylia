<?php
// Разбор xlsx-фида застройщика в «сырые» квартиры (порт read_feed из generate_data.py).
// Без внешних библиотек: xlsx = zip, читаем sharedStrings.xml + первый лист.
//
// Колонки фида Крыльев (шапка в строке 4, данные с 5-й):
//   A=№кв  B=подъезд  C=этаж  D=комнат  E=площадь  F=цена/м²  G=стоимость кв-ры (берём как цену)

// буквенная часть ссылки ячейки: "B12" -> "B"
function xlsx_col_letter($ref) {
  $s = '';
  for ($i = 0, $n = strlen($ref); $i < $n; $i++) {
    $c = $ref[$i];
    if ($c >= 'A' && $c <= 'Z') $s .= $c; else break;
  }
  return $s;
}

// Читает xlsx → массив ассоц. ['A'=>.., 'B'=>..] по строкам (r>=$firstRow).
// Возвращает [rows, error]. error='' если ок.
function xlsx_read_cells($path, $firstRow = 5) {
  if (!is_file($path)) return [[], 'файл не найден'];
  if (!class_exists('ZipArchive')) return [[], 'PHP-расширение zip не установлено'];
  $z = new ZipArchive();
  if ($z->open($path) !== true) return [[], 'не удалось открыть xlsx (повреждён?)'];

  // sharedStrings (может отсутствовать)
  $ss = [];
  $ssXml = $z->getFromName('xl/sharedStrings.xml');
  if ($ssXml !== false && $ssXml !== '') {
    $sx = @simplexml_load_string($ssXml);
    if ($sx !== false) {
      foreach ($sx->si as $si) {
        // текст может быть в <t> или в нескольких <r><t>
        $txt = '';
        foreach ($si->xpath('.//*[local-name()="t"]') as $t) $txt .= (string)$t;
        $ss[] = $txt;
      }
    }
  }

  // первый лист
  $sheetName = null;
  for ($i = 0; $i < $z->numFiles; $i++) {
    $n = $z->getNameIndex($i);
    if (strpos($n, 'xl/worksheets/') === 0 && substr($n, -4) === '.xml') { $sheetName = $n; break; }
  }
  if ($sheetName === null) { $z->close(); return [[], 'лист не найден в xlsx']; }
  $wsXml = $z->getFromName($sheetName);
  $z->close();

  $ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
  $ws = @simplexml_load_string($wsXml);
  if ($ws === false) return [[], 'не удалось разобрать лист'];

  $rows = [];
  foreach ($ws->sheetData->row as $row) {
    $rn = (int)$row['r'];
    if ($rn < $firstRow) continue;
    $cells = [];
    foreach ($row->c as $c) {
      $ref = (string)$c['r'];
      $col = xlsx_col_letter($ref);
      $t = (string)$c['t'];
      if ($t === 'inlineStr') {
        $val = '';
        foreach ($c->xpath('.//*[local-name()="t"]') as $tt) $val .= (string)$tt;
      } else {
        $v = $c->v;
        if ($v === null || (string)$v === '') continue;
        $val = ($t === 's') ? ($ss[(int)$v] ?? '') : (string)$v;
      }
      $cells[$col] = $val;
    }
    if (isset($cells['A']) && trim((string)$cells['A']) !== '') $rows[] = $cells;
  }
  return [$rows, ''];
}

// Сырые ячейки → нормализованные квартиры. Возвращает [flats, warnings].
function feed_to_raw($path) {
  list($cells, $err) = xlsx_read_cells($path);
  if ($err !== '') return [[], [$err]];
  $raw = []; $warn = [];
  foreach ($cells as $c) {
    // пропуск строк без числового подъезда/этажа (подзаголовки/итоги)
    if (!isset($c['B'], $c['C'], $c['D'], $c['E'], $c['G'])) { continue; }
    if (!is_numeric($c['B']) || !is_numeric($c['C'])) { continue; }
    $raw[] = [
      'num'   => trim((string)$c['A']),
      'ent'   => (int)round((float)$c['B']),
      'floor' => (int)round((float)$c['C']),
      'rooms' => (int)round((float)$c['D']),
      'area'  => round((float)$c['E'], 2),
      'price' => (int)round((float)$c['G']),
    ];
  }
  if (!$raw) $warn[] = 'квартир не распознано — проверьте формат фида (шапка в строке 4, данные с 5-й)';
  return [$raw, $warn];
}
