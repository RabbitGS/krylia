<?php
// Сборка каталога квартир из «сырого» фида (порт основной части generate_data.py).
// Синтез стояков, привязка планировок, пины генплана. Метаданные проекта
// (ипотека/банки/картинка генплана/статусы) берём из текущего catalog.json — не из фида.
require_once __DIR__ . '/feedparse.php';

// секции по подъезду
const FEED_SECTION = [7 => 1, 8 => 1, 9 => 2, 10 => 2];

// планировки: "подъезд|комнаты|площадь" => файл (или '' если техплана нет → SVG-схема)
const FEED_PLANS = [
  '7|1|44.33' => 'plans/p_44_3_p7.webp',
  '7|2|72.52' => 'plans/p_72_5_p7.webp',
  '7|2|83.05' => 'plans/p_83_p7.webp',
  '8|1|45.38' => 'plans/p_45_3_p8.webp',
  '8|2|64.86' => 'plans/p_64_8_p8.webp',
  '8|2|71.07' => 'plans/p_71_07_p8.webp',
  '9|1|41.34' => 'plans/p_41_3.webp',
  '9|1|45.38' => 'plans/p_45_3_p9.webp',
  '9|2|63.59' => 'plans/p_63_5.webp',
  '9|2|64.86' => 'plans/p_64_8_p9.webp',
  '10|1|44.13' => 'plans/p_44_1_p10.webp',
  '10|2|63.59' => 'plans/p_63_5.webp',
  '10|2|69.43' => 'plans/p_69_4_p10.webp',
  '10|3|90.38' => 'plans/p_90_p10.webp',
  '10|3|91.83' => 'plans/p_91_8_p10.webp',
];

// пины подъездов на генплане (xPct/yPct)
const FEED_PIN = [
  7  => ['xPct' => 61.0, 'yPct' => 30.0],
  8  => ['xPct' => 66.0, 'yPct' => 38.0],
  9  => ['xPct' => 68.0, 'yPct' => 45.0],
  10 => ['xPct' => 70.0, 'yPct' => 53.0],
];

// Раскладка квартир по номерам (стояк = позиция по нумерации, а не по типу планировки).
// Пропущенные номера в диапазоне подъезда = проданные квартиры, которых нет в фиде.
// Возвращает: ['K','mode','floors','pos'=>[num=>стояк],'phantom'=>[num=>[этаж,стояк]]].
//   mode: exact (плотная нумерация) | shift (короткий первый этаж) | soft (геометрия не выводится точно).
function feed_positions($rowsEnt, $hardMax = 0) {
  $feedFloor = [];
  foreach ($rowsEnt as $r) $feedFloor[(int)$r['num']] = (int)$r['floor'];
  $nums = array_keys($feedFloor); sort($nums);
  $minN = min($nums); $maxN = max($nums);
  $floors = max($feedFloor);
  $perF = [];
  foreach ($rowsEnt as $r) $perF[(int)$r['floor']] = ($perF[(int)$r['floor']] ?? 0) + 1;
  $K = max($perF);

  // проверка: все реальные этажи совпадают с формулой раскладки $fn(num)=[floor,pos]
  $checkAll = function ($fn) use ($feedFloor) {
    foreach ($feedFloor as $n => $ff) { list($f,) = $fn($n); if ($f != $ff) return false; }
    return true;
  };

  $plain = function ($n) use ($minN, $K) { return [1 + intdiv($n - $minN, $K), ($n - $minN) % $K + 1]; };
  $fn = null; $mode = '';
  if ($checkAll($plain)) { $fn = $plain; $mode = 'exact'; }
  else {
    // короткий первый этаж (например одна ячейка под коммерцию): его размер берём из данных —
    // это сколько номеров идёт до начала 2-го этажа. Средние этажи считаем полными (K).
    $floor2min = null;
    foreach ($rowsEnt as $r) if ((int)$r['floor'] === 2) { $n = (int)$r['num']; if ($floor2min === null || $n < $floor2min) $floor2min = $n; }
    $pf1 = $floor2min !== null ? ($floor2min - $minN) : (($maxN - $minN + 1) - $K * ($floors - 1));
    if ($pf1 >= 1 && $pf1 <= $K) {
      $shift = function ($n) use ($minN, $K, $pf1) {
        if ($n < $minN + $pf1) return [1, $n - $minN + 1];
        $o = $n - $minN - $pf1; return [2 + intdiv($o, $K), $o % $K + 1];
      };
      if ($checkAll($shift)) { $fn = $shift; $mode = 'shift'; }
    }
  }

  $pos = []; $phantom = [];
  if ($fn) {
    // достраиваем хвост подъезда до $hardMax (последний номер, вычисленный по началу
    // следующего подъезда): проданные квартиры за maxN фида — тоже фантомы.
    $topN = max($maxN, (int)$hardMax);
    for ($n = $minN; $n <= $topN; $n++) {
      list($f, $p) = $fn($n);
      if ($f < 1 || $f > $floors) continue;   // за пределами дома — не создаём
      if (isset($feedFloor[$n])) $pos[$n] = $p; else $phantom[$n] = [$f, $p];
    }
    return ['K' => $K, 'mode' => $mode, 'floors' => $floors, 'pos' => $pos, 'phantom' => $phantom];
  }

  // SOFT: реальные держим на их этажах (стояк = порядок в ряду), проданные — в свободные слоты по возрастанию.
  $grid = []; $byF = [];
  foreach ($feedFloor as $n => $f) $byF[$f][] = $n;
  ksort($byF);
  foreach ($byF as $f => $ns) { sort($ns); foreach ($ns as $i => $n) { $pos[$n] = $i + 1; $grid[$f][$i + 1] = 1; } }
  $sold = [];
  for ($n = $minN; $n <= $maxN; $n++) if (!isset($feedFloor[$n])) $sold[] = $n;
  $si = 0;
  for ($f = 1; $f <= $floors && $si < count($sold); $f++) {
    for ($p = 1; $p <= $K && $si < count($sold); $p++) {
      if (!isset($grid[$f][$p])) { $phantom[$sold[$si]] = [$f, $p]; $grid[$f][$p] = 1; $si++; }
    }
  }
  return ['K' => $K, 'mode' => 'soft', 'floors' => $floors, 'pos' => $pos, 'phantom' => $phantom];
}

// собрать полный каталог. $meta — старый catalog.json (для метаданных проекта).
function build_catalog($raw, $meta = null) {
  // раскладка по номерам для каждого подъезда
  $ents = [];
  foreach ($raw as $r) $ents[$r['ent']] = 1;
  ksort($ents);
  $maxFloor = 0;
  foreach ($raw as $r) $maxFloor = max($maxFloor, $r['floor']);

  // первый номер каждого подъезда — для вычисления границы предыдущего
  // (подъезды нумеруются непрерывно: последний номер п9 = первый номер п10 − 1)
  $entMinNum = [];
  foreach (array_keys($ents) as $ent) {
    $ns = [];
    foreach ($raw as $r) if ($r['ent'] === $ent) $ns[] = (int)$r['num'];
    $entMinNum[$ent] = $ns ? min($ns) : 0;
  }
  $entList = array_keys($ents);

  $flats = [];
  $missPlan = [];
  $layoutModes = [];   // подъезд => режим раскладки (exact/shift/soft)
  foreach ($entList as $idx => $ent) {
    $rowsEnt = array_values(array_filter($raw, fn($r) => $r['ent'] === $ent));
    $nextEnt = $entList[$idx + 1] ?? null;
    $hardMax = ($nextEnt !== null && $entMinNum[$nextEnt] > 0) ? $entMinNum[$nextEnt] - 1 : 0;
    $pos = feed_positions($rowsEnt, $hardMax);
    $layoutModes['p' . $ent] = $pos['mode'];
    // реальные квартиры из фида
    foreach ($rowsEnt as $r) {
      $key = $r['ent'] . '|' . $r['rooms'] . '|' . $r['area'];
      $flat = [
        'id'        => 'kr' . $r['num'],
        'number'    => $r['num'],
        'building'  => 'p' . $r['ent'],
        'floor'     => $r['floor'],
        'riser'     => $pos['pos'][(int)$r['num']] ?? 1,
        'rooms'     => $r['rooms'],
        'area'      => $r['area'],
        'price'     => $r['price'],
        'status'    => 'free',          // фид = доступные; брони/продажи живут в state-оверлее
        'finishing' => 'под ключ',
      ];
      if (array_key_exists($key, FEED_PLANS)) {
        if (FEED_PLANS[$key] !== '') $flat['plan'] = FEED_PLANS[$key];
      } else {
        $missPlan[$key] = 1;
      }
      if ($r['floor'] === $maxFloor) $flat['features'] = ['Последний этаж'];
      $flats[] = $flat;
    }
    // проданные квартиры — пропущенные номера (нет в фиде, данных нет)
    foreach ($pos['phantom'] as $num => $fp) {
      $flats[] = [
        'id'       => 'kr' . $num,
        'number'   => (string)$num,
        'building' => 'p' . $ent,
        'floor'    => $fp[0],
        'riser'    => $fp[1],
        'status'   => 'sold',           // нет в фиде → считаем проданной
        'nodata'   => true,             // нет площади/цены/планировки
      ];
    }
  }
  $buildings = [];
  foreach (array_keys($ents) as $ent) {
    $cnt = 0; foreach ($flats as $f) if ($f['building'] === 'p' . $ent) $cnt++;
    $buildings[] = [
      'id' => 'p' . $ent,
      'name' => 'Подъезд ' . $ent,
      'tag' => 'секция ' . (FEED_SECTION[$ent] ?? '?'),
      'floors_label' => $maxFloor . ' этажей · ' . $cnt . ' кв.',
      'xPct' => FEED_PIN[$ent]['xPct'] ?? 50,
      'yPct' => FEED_PIN[$ent]['yPct'] ?? 50,
    ];
  }

  $meta = is_array($meta) ? $meta : [];
  $catalog = [
    'project'  => $meta['project'] ?? 'ЖК «Крылья»',
    'currency' => $meta['currency'] ?? '₽',
    'deadline' => $meta['deadline'] ?? 'IV кв. 2027',
    'mortgage' => $meta['mortgage'] ?? ['rate' => 0.06, 'years' => 30, 'down' => 0.2],
    'banks'    => $meta['banks'] ?? [],
    'genplan'  => [
      'image' => $meta['genplan']['image'] ?? 'genplan.jpg',
      'buildings' => $buildings,
    ],
    'statuses' => $meta['statuses'] ?? [
      'free'     => ['label' => 'Свободна', 'color' => '#3f9d58'],
      'reserved' => ['label' => 'Бронь',    'color' => '#e0a312'],
      'promo'    => ['label' => 'Акция',    'color' => '#8e44ad'],
      'sold'     => ['label' => 'Продана',  'color' => '#c0392b'],
    ],
    'layout' => $layoutModes,
    'flats' => $flats,
  ];
  return [$catalog, array_keys($missPlan)];
}
