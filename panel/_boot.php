<?php
// Ядро админ-панели: авторизация, пути, слой данных (каталог + оверлей статусов).
// Подключается ПЕРВЫМ из panel.php и api.php.
//
// Модель данных:
//   panel/catalog.json          — структура квартир из фида (в репо, версионируется).
//   ../shahmatka_state.json      — живые статусы (ВЫШЕ веб-корня, в .gitignore, деплой не трогает).
//   ../lead_log.jsonl            — лог заявок (пишет lead.php).
//   ../panel_secret.php          — логин + хеш пароля (ВЫШЕ веб-корня, chmod 600).

// Полифиллы для PHP 7.4 (веб-хендлер SprintHost = 7.4, хотя CLI 8.5).
if (!function_exists('str_ends_with')) {
  function str_ends_with($h, $n) { return $n === '' || substr($h, -strlen($n)) === $n; }
}
if (!function_exists('str_starts_with')) {
  function str_starts_with($h, $n) { return strpos($h, $n) === 0; }
}
if (!function_exists('str_contains')) {
  function str_contains($h, $n) { return $n === '' || strpos($h, $n) !== false; }
}

require_once __DIR__ . '/lib.php';

// ---------- ПУТИ ----------
function panel_docroot() { return $_SERVER['DOCUMENT_ROOT'] ?: dirname(__DIR__); }
function catalog_path()  { return __DIR__ . '/catalog.json'; }
function pending_path()  { return __DIR__ . '/catalog.pending.json'; }   // предпросмотр импорта (в .gitignore)
function backup_path()   { return __DIR__ . '/catalog.bak.json'; }       // бэкап перед применением
function state_path()    { return panel_docroot() . '/../shahmatka_state.json'; }
function statuslog_path(){ return panel_docroot() . '/../shahmatka_status_log.jsonl'; }  // журнал смен статуса
function eventlog_path() { return panel_docroot() . '/../shahmatka_events.jsonl'; }      // системные события (импорт/экспорт)
function leadlog_path()  {
  // если в panel_secret.php задан абсолютный путь к боевому логу — читаем его
  // (новая панель на превью-домене смотрит в lead_log боевого krylia-tver.ru — тот же аккаунт).
  $cfg = panel_cfg();
  if (!empty($cfg['leadlog']) && is_file($cfg['leadlog'])) return $cfg['leadlog'];
  return panel_docroot() . '/../lead_log.jsonl';
}

// записать системное событие (feed_import / feed_export). $dedupSec — не дублировать
// событие того же типа+ключа, если предыдущее было недавно (для частых заборов фида площадками).
function log_event($type, $data = [], $dedupSec = 0) {
  if ($dedupSec > 0) {
    $last = load_events(1, $type);
    if ($last && !empty($last[0]['ts'])) {
      $prev = strtotime($last[0]['ts']);
      $sameKey = ($last[0]['key'] ?? '') === ($data['key'] ?? '');
      if ($prev && $sameKey && (time() - $prev) < $dedupSec) return;
    }
  }
  $row = array_merge(['ts' => date('c'), 'type' => $type], $data);
  @file_put_contents(eventlog_path(), json_encode($row, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
}

// прочитать системные события (новые сверху); $type — фильтр по типу
function load_events($limit = 0, $type = null) {
  $rows = [];
  $p = eventlog_path();
  if (is_readable($p) && ($fh = fopen($p, 'r'))) {
    while (($ln = fgets($fh)) !== false) {
      $ln = trim($ln); if ($ln === '') continue;
      $o = json_decode($ln, true); if (!is_array($o)) continue;
      if ($type !== null && ($o['type'] ?? '') !== $type) continue;
      $rows[] = $o;
    }
    fclose($fh);
  }
  $rows = array_reverse($rows);
  if ($limit > 0) $rows = array_slice($rows, 0, $limit);
  return $rows;
}

// записать каталог (атомарно)
function save_catalog($catalog, $path = null) {
  $path = $path ?? catalog_path();
  $tmp = $path . '.tmp';
  $ok = @file_put_contents($tmp, json_encode($catalog, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
  if ($ok === false) return false;
  return @rename($tmp, $path);
}

// ---------- АВТОРИЗАЦИЯ (Basic, секрет выше веб-корня) ----------
function panel_cfg() {
  $dir = panel_docroot();
  for ($i = 0; $i < 4; $i++) {
    $p = $dir . '/panel_secret.php';
    if (is_file($p)) return include $p;
    $dir = dirname($dir);
  }
  return [];
}
function auth_creds() {
  $u = $_SERVER['PHP_AUTH_USER'] ?? null;
  $p = $_SERVER['PHP_AUTH_PW'] ?? null;
  if ($u === null) {
    $hh = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (stripos($hh, 'basic ') === 0) {
      $d = base64_decode(substr($hh, 6));
      if ($d !== false && strpos($d, ':') !== false) list($u, $p) = explode(':', $d, 2);
    }
  }
  return [$u, $p];
}
function panel_require_auth() {
  $cfg = panel_cfg();
  list($au, $ap) = auth_creds();
  $ok = $cfg && isset($cfg['user'], $cfg['pass_hash'])
     && is_string($au) && hash_equals($cfg['user'], (string)$au)
     && password_verify((string)$ap, $cfg['pass_hash']);
  if (!$ok) {
    header('WWW-Authenticate: Basic realm="Krylia panel"');
    http_response_code(401);
    echo 'Требуется авторизация';
    exit;
  }
}

// ---------- ДАННЫЕ: КАТАЛОГ + СТАТУСЫ ----------
function load_catalog() {
  $j = @file_get_contents(catalog_path());
  $d = $j ? json_decode($j, true) : null;
  return is_array($d) ? $d : ['flats' => [], 'statuses' => [], 'genplan' => ['buildings' => []]];
}

// живые статусы: { "kr101": {"status":"reserved","ts":1720300000,"by":"vlad"}, ... }
function load_state() {
  $j = @file_get_contents(state_path());
  $d = $j ? json_decode($j, true) : null;
  return is_array($d) ? $d : [];
}
function save_state($state) {
  $tmp = state_path() . '.tmp';
  $ok = @file_put_contents($tmp, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
  if ($ok === false) return false;
  return @rename($tmp, state_path());
}

// каталог с наложенными живыми статусами (для отрисовки и экспорта)
function merged_flats($catalog = null, $state = null) {
  $catalog = $catalog ?? load_catalog();
  $state   = $state ?? load_state();
  $valid = array_keys($catalog['statuses'] ?? ['free' => 1, 'reserved' => 1, 'promo' => 1, 'sold' => 1]);
  $flats = $catalog['flats'] ?? [];
  foreach ($flats as &$f) {
    $id = $f['id'] ?? '';
    if (isset($state[$id]['status']) && in_array($state[$id]['status'], $valid, true)) {
      $f['status'] = $state[$id]['status'];        // живой статус побеждает базовый из каталога
      $f['status_ts'] = $state[$id]['ts'] ?? 0;
    }
    // ручная цена из оверлея побеждает цену из фида (сбрасывается при переимпорте — «фид главнее»)
    if (isset($state[$id]['price']) && (int)$state[$id]['price'] > 0) {
      $f['price'] = (int)$state[$id]['price'];
      $f['price_manual'] = true;
      $f['price_ts'] = $state[$id]['price_ts'] ?? 0;
    }
    // ручные данные квартиры (комнаты/площадь/планировка) из оверлея — для проданных-фантомов,
    // которых нет в фиде. ПЕРЕЖИВАЮТ импорт фида (в отличие от ручной цены): фид эти квартиры
    // не содержит, так что конфликта «фид главнее» тут нет.
    if (!empty($state[$id]['meta']) && is_array($state[$id]['meta'])) {
      $m = $state[$id]['meta'];
      foreach (['rooms', 'area', 'plan', 'finishing'] as $k) {
        if (isset($m[$k]) && $m[$k] !== '') $f[$k] = $m[$k];
      }
      if (isset($f['rooms'], $f['area'])) {
        unset($f['nodata']);
        $f['meta_manual'] = true;
        $f['meta_ts'] = $state[$id]['meta_ts'] ?? 0;
        $f['meta_by'] = $state[$id]['meta_by'] ?? '';
      }
    }
  }
  unset($f);
  return $flats;
}

// применить один статус к state (валидация id и статуса по каталогу) + запись в журнал
function apply_status($id, $status, $by = '') {
  $catalog = load_catalog();
  $flatsById = [];
  foreach ($catalog['flats'] ?? [] as $f) $flatsById[$f['id']] = $f;
  $valid = array_keys($catalog['statuses'] ?? []);
  if (!isset($flatsById[$id])) return [false, 'unknown flat'];
  if (!in_array($status, $valid, true)) return [false, 'unknown status'];
  $state = load_state();
  // прежний статус: из оверлея, иначе базовый из каталога
  $from = $state[$id]['status'] ?? ($flatsById[$id]['status'] ?? 'free');
  if ($from === $status) return [true, ''];                 // без изменений — не логируем
  // merge, а не перезапись — чтобы не затереть ручную цену (price/price_ts) в этой же записи
  $prev = is_array($state[$id] ?? null) ? $state[$id] : [];
  $state[$id] = array_merge($prev, ['status' => $status, 'ts' => time(), 'by' => $by]);
  if (!save_state($state)) return [false, 'save failed'];
  log_status_change($id, $flatsById[$id]['number'] ?? $id, $from, $status, $by);
  return [true, ''];
}

// применить ручную цену к state (валидация id и суммы) + запись в журнал.
// Цена-оверлей побеждает фид, но сбрасывается при следующем импорте (см. api.php import_apply).
function apply_price($id, $price, $by = '') {
  $catalog = load_catalog();
  $flatsById = [];
  foreach ($catalog['flats'] ?? [] as $f) $flatsById[$f['id']] = $f;
  if (!isset($flatsById[$id])) return [false, 'unknown flat'];
  $state = load_state();
  // проданный-фантом без данных — цену не заводим (но если данные заполнены вручную/автозаполнением, можно)
  if (!empty($flatsById[$id]['nodata']) && empty($state[$id]['meta'])) return [false, 'no data flat'];
  $price = (int)$price;
  if ($price <= 0 || $price > 1000000000) return [false, 'bad price'];
  // прежняя цена: из оверлея, иначе базовая из каталога (фид)
  $from = isset($state[$id]['price']) ? (int)$state[$id]['price'] : (int)($flatsById[$id]['price'] ?? 0);
  if ($from === $price) return [true, ''];                   // без изменений — не логируем
  $prev = is_array($state[$id] ?? null) ? $state[$id] : [];
  $state[$id] = array_merge($prev, ['price' => $price, 'price_ts' => time(), 'price_by' => $by]);
  if (!save_state($state)) return [false, 'save failed'];
  log_price_change($id, $flatsById[$id]['number'] ?? $id, $from, $price, $by);
  return [true, ''];
}

// применить ручные данные квартиры (комнаты/площадь/планировка/отделка) к state + журнал.
// Для проданных-фантомов вне фида; переживают импорт фида. $meta: rooms, area, plan, finishing.
function apply_meta($id, $meta, $by = '') {
  $catalog = load_catalog();
  $flatsById = [];
  foreach ($catalog['flats'] ?? [] as $f) $flatsById[$f['id']] = $f;
  if (!isset($flatsById[$id])) return [false, 'unknown flat'];
  $clean = [];
  if (isset($meta['rooms']) && $meta['rooms'] !== '') {
    $r = (int)$meta['rooms'];
    if ($r < 0 || $r > 9) return [false, 'bad rooms'];
    $clean['rooms'] = $r;
  }
  if (isset($meta['area']) && $meta['area'] !== '') {
    $a = (float)str_replace(',', '.', (string)$meta['area']);
    if ($a < 10 || $a > 500) return [false, 'bad area'];
    $clean['area'] = round($a, 2);
  }
  if (isset($meta['plan'])) {
    $p = trim((string)$meta['plan']);
    // только относительный путь внутри plans/ (защита от произвольных строк в src)
    if ($p !== '' && !preg_match('~^plans/[\w.\-]+$~u', $p)) return [false, 'bad plan'];
    $clean['plan'] = $p;
  }
  if (isset($meta['finishing'])) $clean['finishing'] = mb_substr(trim((string)$meta['finishing']), 0, 60);
  if (!isset($clean['rooms'], $clean['area'])) return [false, 'rooms and area required'];
  $state = load_state();
  $prev = is_array($state[$id] ?? null) ? $state[$id] : [];
  $old = $prev['meta'] ?? null;
  if ($old == $clean) return [true, ''];                     // без изменений — не логируем
  $state[$id] = array_merge($prev, ['meta' => $clean, 'meta_ts' => time(), 'meta_by' => $by]);
  if (!save_state($state)) return [false, 'save failed'];
  $sum = ($clean['rooms'] <= 0 ? 'студия' : $clean['rooms'] . 'к') . ' · ' . $clean['area'] . ' м²';
  log_meta_change($id, $flatsById[$id]['number'] ?? $id, $sum, $by);
  return [true, ''];
}

// дописать строку в журнал изменений данных квартиры
function log_meta_change($id, $number, $summary, $by) {
  $row = ['ts' => date('c'), 'id' => $id, 'number' => (string)$number, 'kind' => 'meta',
          'to' => (string)$summary, 'by' => (string)$by];
  @file_put_contents(statuslog_path(), json_encode($row, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
}

// дописать строку в журнал изменений статуса
function log_status_change($id, $number, $from, $to, $by) {
  $row = ['ts' => date('c'), 'id' => $id, 'number' => (string)$number,
          'from' => $from, 'to' => $to, 'by' => (string)$by];
  @file_put_contents(statuslog_path(), json_encode($row, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
}

// дописать строку в журнал изменений цены (тот же файл, поле kind='price' отличает от статусов)
function log_price_change($id, $number, $from, $to, $by) {
  $row = ['ts' => date('c'), 'id' => $id, 'number' => (string)$number, 'kind' => 'price',
          'from' => (int)$from, 'to' => (int)$to, 'by' => (string)$by];
  @file_put_contents(statuslog_path(), json_encode($row, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
}

// история изменений: все или по одной квартире ($id). Новые сверху.
function load_history($id = null, $limit = 0) {
  $rows = [];
  $p = statuslog_path();
  if (is_readable($p) && ($fh = fopen($p, 'r'))) {
    while (($ln = fgets($fh)) !== false) {
      $ln = trim($ln); if ($ln === '') continue;
      $o = json_decode($ln, true); if (!is_array($o)) continue;
      if ($id !== null && ($o['id'] ?? '') !== $id) continue;
      $rows[] = $o;
    }
    fclose($fh);
  }
  $rows = array_reverse($rows);
  if ($limit > 0) $rows = array_slice($rows, 0, $limit);
  return $rows;
}
