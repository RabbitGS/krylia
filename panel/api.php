<?php
// API панели (запись). Пока: смена статуса квартиры.
// POST panel/api.php?action=set_status  тело JSON: {"id":"kr101","status":"reserved"}
require_once __DIR__ . '/_boot.php';
panel_require_auth();

$action = $_GET['action'] ?? '';
list($user) = auth_creds();

// редирект обратно на вкладку (для form-post импорта)
function back_to_import($msg, $err = false) {
  $q = 'tab=import&' . ($err ? 'err=' : 'msg=') . rawurlencode($msg);
  header('Location: ../panel.php?' . $q);
  exit;
}

// ---------- ИМПОРТ ФИДА ----------
if ($action === 'import_upload') {
  require_once __DIR__ . '/feedbuild.php';
  if (empty($_FILES['feed']['tmp_name']) || !is_uploaded_file($_FILES['feed']['tmp_name'])) {
    back_to_import('Файл не получен', true);
  }
  $name = (string)($_FILES['feed']['name'] ?? '');
  if (strtolower(substr($name, -5)) !== '.xlsx') back_to_import('Нужен файл .xlsx', true);
  list($raw, $warn) = feed_to_raw($_FILES['feed']['tmp_name']);
  if (!$raw) back_to_import('Квартир не распознано: ' . implode('; ', $warn), true);
  $meta = load_catalog();                       // метаданные проекта из текущего каталога
  list($catalog, $miss) = build_catalog($raw, $meta);
  $catalog['_imported_from'] = $name;
  $catalog['_imported_at'] = date('c');
  if (!save_catalog($catalog, pending_path())) back_to_import('Не удалось сохранить предпросмотр', true);
  back_to_import('Распознано ' . count($catalog['flats']) . ' кв. — проверьте предпросмотр');
}

if ($action === 'import_apply') {
  $pj = @file_get_contents(pending_path());
  $pending = $pj ? json_decode($pj, true) : null;
  if (!is_array($pending) || empty($pending['flats'])) back_to_import('Нет данных для применения', true);
  @copy(catalog_path(), backup_path());         // бэкап текущего каталога
  if (!save_catalog($pending)) back_to_import('Не удалось записать каталог', true);
  @unlink(pending_path());
  // «фид главнее ручной цены»: сбрасываем ручные цены-оверлеи (статусы/брони/продажи — оставляем)
  $state = load_state();
  $wiped = 0;
  foreach ($state as &$rec) {
    if (is_array($rec) && isset($rec['price'])) { unset($rec['price'], $rec['price_ts'], $rec['price_by']); $wiped++; }
  }
  unset($rec);
  if ($wiped) save_state($state);
  log_event('feed_import', ['count' => count($pending['flats']), 'file' => $pending['_imported_from'] ?? '', 'by' => (string)$user]);
  // статусы в state НЕ трогаем — они наложатся на новый каталог по стабильным id
  $extra = $wiped ? (' Ручных цен сброшено: ' . $wiped . '.') : '';
  back_to_import('Каталог обновлён: ' . count($pending['flats']) . ' кв. Брони/продажи сохранены.' . $extra);
}

if ($action === 'import_cancel') {
  @unlink(pending_path());
  back_to_import('Предпросмотр отменён');
}

header('Content-Type: application/json; charset=utf-8');

if ($action === 'set_status') {
  $raw = file_get_contents('php://input');
  $in = json_decode($raw, true);
  $id = is_array($in) ? (string)($in['id'] ?? '') : '';
  $status = is_array($in) ? (string)($in['status'] ?? '') : '';
  list($ok, $err) = apply_status($id, $status, (string)$user);
  if (!$ok) { http_response_code(400); echo json_encode(['ok' => false, 'error' => $err]); exit; }
  echo json_encode(['ok' => true, 'id' => $id, 'status' => $status, 'history' => load_history($id, 20)]);
  exit;
}

if ($action === 'set_price') {
  $raw = file_get_contents('php://input');
  $in = json_decode($raw, true);
  $id = is_array($in) ? (string)($in['id'] ?? '') : '';
  $price = is_array($in) ? ($in['price'] ?? 0) : 0;
  list($ok, $err) = apply_price($id, $price, (string)$user);
  if (!$ok) { http_response_code(400); echo json_encode(['ok' => false, 'error' => $err]); exit; }
  // вернуть актуальную (наложенную) цену — на случай если не изменилась
  $cur = (int)$price;
  foreach (merged_flats() as $mf) { if (($mf['id'] ?? '') === $id) { $cur = (int)($mf['price'] ?? $cur); break; } }
  echo json_encode(['ok' => true, 'id' => $id, 'price' => $cur, 'history' => load_history($id, 20)]);
  exit;
}

if ($action === 'upload_plan') {
  // загрузка картинки планировки → flats/plans/ (там же живут планировки витрины)
  if (empty($_FILES['plan']['tmp_name']) || !is_uploaded_file($_FILES['plan']['tmp_name'])) {
    http_response_code(400); echo json_encode(['ok' => false, 'error' => 'файл не получен']); exit;
  }
  $pf = $_FILES['plan'];
  if ($pf['size'] > 8 * 1024 * 1024) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'больше 8 МБ']); exit; }
  $ext = strtolower(pathinfo($pf['name'], PATHINFO_EXTENSION));
  if ($ext === 'jpeg') $ext = 'jpg';
  if (!in_array($ext, ['webp', 'jpg', 'png'], true)) {
    http_response_code(400); echo json_encode(['ok' => false, 'error' => 'только webp/jpg/png']); exit;
  }
  $info = @getimagesize($pf['tmp_name']);   // проверка, что это реально картинка
  if (!$info || !in_array($info[2], [IMAGETYPE_WEBP, IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) {
    http_response_code(400); echo json_encode(['ok' => false, 'error' => 'файл не похож на картинку']); exit;
  }
  // безопасное имя: латиница/цифры/точка/дефис/подчёркивание + защита от коллизий
  $base = preg_replace('~[^A-Za-z0-9._\-]~', '_', pathinfo($pf['name'], PATHINFO_FILENAME));
  $base = trim($base, '._-') !== '' ? trim($base, '._-') : 'plan';
  $dir = panel_docroot() . '/flats/plans';
  if (!is_dir($dir)) { http_response_code(500); echo json_encode(['ok' => false, 'error' => 'нет папки flats/plans']); exit; }
  $name = $base . '.' . $ext;
  if (is_file("$dir/$name")) $name = $base . '_' . date('Ymd-His') . '.' . $ext;
  if (!move_uploaded_file($pf['tmp_name'], "$dir/$name")) {
    http_response_code(500); echo json_encode(['ok' => false, 'error' => 'не удалось сохранить']); exit;
  }
  @chmod("$dir/$name", 0644);
  log_event('plan_upload', ['file' => 'plans/' . $name, 'by' => (string)$user]);
  echo json_encode(['ok' => true, 'plan' => 'plans/' . $name], JSON_UNESCAPED_UNICODE);
  exit;
}

if ($action === 'set_meta') {
  $raw = file_get_contents('php://input');
  $in = json_decode($raw, true);
  $id = is_array($in) ? (string)($in['id'] ?? '') : '';
  $meta = is_array($in) ? (array)($in['meta'] ?? []) : [];
  list($ok, $err) = apply_meta($id, $meta, (string)$user);
  if (!$ok) { http_response_code(400); echo json_encode(['ok' => false, 'error' => $err]); exit; }
  // вернуть квартиру уже с наложенными данными — фронт перерисует карточку и ячейку
  $flat = null;
  foreach (merged_flats() as $mf) { if (($mf['id'] ?? '') === $id) { $flat = $mf; break; } }
  echo json_encode(['ok' => true, 'id' => $id, 'flat' => $flat, 'history' => load_history($id, 20)], JSON_UNESCAPED_UNICODE);
  exit;
}

if ($action === 'history') {
  $id = (string)($_GET['id'] ?? '');
  echo json_encode(['ok' => true, 'history' => $id !== '' ? load_history($id, 20) : load_history(null, 100)]);
  exit;
}

http_response_code(404);
echo json_encode(['ok' => false, 'error' => 'unknown action']);
