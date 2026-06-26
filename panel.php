<?php
// Крылья — панель заявок и происхождения (read-only). Прототип агентского кокпита.
// Источник данных: ../lead_log.jsonl (пишет lead.php). Доступ: HTTP Basic, секрет в ../panel_secret.php.
// Ничего не меняет на сайте — только читает лог и показывает.

// ---------- АВТОРИЗАЦИЯ ----------
function panel_cfg() {
  $dir = $_SERVER['DOCUMENT_ROOT'] ?: __DIR__;
  for ($i = 0; $i < 4; $i++) {
    $p = $dir . '/panel_secret.php';
    if (is_file($p)) return include $p;
    $dir = dirname($dir);
  }
  return [];
}
function auth_creds() {
  // при FastCGI PHP_AUTH_* может не заполняться — парсим заголовок Authorization вручную
  $u = $_SERVER['PHP_AUTH_USER'] ?? null;
  $p = $_SERVER['PHP_AUTH_PW'] ?? null;
  if ($u === null) {
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (stripos($h, 'basic ') === 0) {
      $d = base64_decode(substr($h, 6));
      if ($d !== false && strpos($d, ':') !== false) list($u, $p) = explode(':', $d, 2);
    }
  }
  return [$u, $p];
}
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

// ---------- ДАННЫЕ ----------
$range = $_GET['range'] ?? '7';            // 1 | 7 | 30 | all
$validRanges = ['1', '7', '30', 'all'];
if (!in_array($range, $validRanges, true)) $range = '7';
$now = time();
$since = $range === 'all' ? 0 : $now - ((int)$range) * 86400;

$logPath = (($_SERVER['DOCUMENT_ROOT'] ?? __DIR__) . '/../lead_log.jsonl');
$rows = [];
if (is_readable($logPath)) {
  $fh = fopen($logPath, 'r');
  if ($fh) {
    while (($line = fgets($fh)) !== false) {
      $line = trim($line);
      if ($line === '') continue;
      $o = json_decode($line, true);
      if (!is_array($o)) continue;
      $ts = isset($o['ts']) ? strtotime($o['ts']) : 0;
      if ($ts && $ts < $since) continue;
      $o['_ts'] = $ts;
      $rows[] = $o;
    }
    fclose($fh);
  }
}

// агрегаты
$cnt = ['accepted' => 0, 'honeypot' => 0, 'timetrap' => 0, 'bad_phone' => 0];
$suspect = 0;                              // прошли как accepted, но без поля t — вероятно прямой POST бота
$byday = [];
$bySource = [];
foreach ($rows as $r) {
  $d = $r['decision'] ?? '?';
  if (isset($cnt[$d])) $cnt[$d]++;
  $isSus = ($d === 'accepted' && (($r['t'] ?? '') === ''));
  if ($isSus) $suspect++;
  $day = $r['_ts'] ? date('Y-m-d', $r['_ts']) : '—';
  if (!isset($byday[$day])) $byday[$day] = ['accepted' => 0, 'bots' => 0];
  if ($d === 'accepted') $byday[$day]['accepted']++;
  elseif ($d === 'honeypot' || $d === 'timetrap') $byday[$day]['bots']++;
  if ($d === 'accepted') {
    $src = trim((string)($r['source'] ?? '')) ?: (trim((string)($r['form'] ?? '')) ?: '—');
    $bySource[$src] = ($bySource[$src] ?? 0) + 1;
  }
}
krsort($byday);
arsort($bySource);

$leads = array_values(array_filter($rows, fn($r) => ($r['decision'] ?? '') === 'accepted'));
$bots  = array_values(array_filter($rows, fn($r) => in_array($r['decision'] ?? '', ['honeypot', 'timetrap'], true)));
usort($leads, fn($a, $b) => ($b['_ts'] ?? 0) <=> ($a['_ts'] ?? 0));
usort($bots,  fn($a, $b) => ($b['_ts'] ?? 0) <=> ($a['_ts'] ?? 0));

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function dt($ts) { return $ts ? date('d.m H:i', $ts) : '—'; }
function clip($s, $n) { $s = (string)$s; return mb_strlen($s) > $n ? mb_substr($s, 0, $n) . '…' : $s; }
$totalReq = count($rows);
$botsCaught = $cnt['honeypot'] + $cnt['timetrap'];
$rangeLabel = ['1' => 'сегодня', '7' => '7 дней', '30' => '30 дней', 'all' => 'всё время'][$range];
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Крылья · панель заявок</title>
<style>
  :root{--navy:#1b2540;--navy2:#243056;--tan:#c9a86a;--ink:#1d1d1f;--muted:#7b8190;--line:#e7e9ee;--bg:#f5f6f8;--ok:#1f9d63;--bad:#c0392b;--warn:#d98a1f}
  *{box-sizing:border-box}
  body{margin:0;font:15px/1.5 -apple-system,Segoe UI,Roboto,Arial,sans-serif;color:var(--ink);background:var(--bg)}
  header{background:var(--navy);color:#fff;padding:20px 28px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px}
  header h1{margin:0;font-size:20px;font-weight:700;letter-spacing:-.01em}
  header .sub{color:#aab2c8;font-size:13px;margin-top:2px}
  .wrap{max-width:1100px;margin:0 auto;padding:24px 28px 60px}
  .ranges{display:flex;gap:8px}
  .ranges a{color:#cdd4e6;text-decoration:none;padding:6px 12px;border-radius:8px;font-size:13px;border:1px solid transparent}
  .ranges a.on{background:var(--tan);color:var(--navy);font-weight:700}
  .ranges a:not(.on):hover{border-color:#3a4566}
  .cards{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin:22px 0}
  .card{background:#fff;border:1px solid var(--line);border-radius:14px;padding:16px 18px}
  .card .n{font-size:30px;font-weight:800;letter-spacing:-.02em}
  .card .l{color:var(--muted);font-size:13px;margin-top:3px}
  .card.ok .n{color:var(--ok)} .card.bad .n{color:var(--bad)} .card.warn .n{color:var(--warn)}
  h2{font-size:15px;text-transform:uppercase;letter-spacing:.08em;color:var(--muted);margin:30px 0 12px}
  table{width:100%;border-collapse:collapse;background:#fff;border:1px solid var(--line);border-radius:14px;overflow:hidden}
  th,td{padding:10px 14px;text-align:left;border-bottom:1px solid var(--line);font-size:13.5px;vertical-align:top}
  th{background:#fafbfc;color:var(--muted);font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:.04em}
  tr:last-child td{border-bottom:none}
  .pill{display:inline-block;padding:2px 9px;border-radius:20px;font-size:11.5px;font-weight:700}
  .pill.hp{background:#fdeaea;color:var(--bad)} .pill.tt{background:#fef3e2;color:var(--warn)}
  .pill.sus{background:#fdeaea;color:var(--bad)} .pill.real{background:#e7f6ee;color:var(--ok)}
  .mono{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12.5px;color:var(--muted)}
  .two{display:grid;grid-template-columns:1fr 1fr;gap:22px}
  .empty{color:var(--muted);padding:18px;background:#fff;border:1px dashed var(--line);border-radius:14px}
  @media(max-width:780px){.cards{grid-template-columns:repeat(2,1fr)}.two{grid-template-columns:1fr}}
</style>
</head>
<body>
<header>
  <div>
    <h1>ЖК «Крылья» · панель заявок</h1>
    <div class="sub">прототип кокпита · данные из lead_log.jsonl · период: <?=h($rangeLabel)?></div>
  </div>
  <div class="ranges">
    <?php foreach (['1'=>'Сегодня','7'=>'7 дней','30'=>'30 дней','all'=>'Всё'] as $k=>$v): ?>
      <a class="<?=$range===$k?'on':''?>" href="?range=<?=h($k)?>"><?=h($v)?></a>
    <?php endforeach; ?>
  </div>
</header>

<div class="wrap">

  <div class="cards">
    <div class="card ok"><div class="n"><?=$cnt['accepted']?></div><div class="l">Заявки (приняты)</div></div>
    <div class="card bad"><div class="n"><?=$botsCaught?></div><div class="l">Боты пойманы (honeypot+time-trap)</div></div>
    <div class="card warn"><div class="n"><?=$suspect?></div><div class="l">Подозрение на прямого бота*</div></div>
    <div class="card"><div class="n"><?=$totalReq?></div><div class="l">Всего обращений</div></div>
  </div>

  <div class="two">
    <div>
      <h2>По дням</h2>
      <?php if ($byday): ?>
      <table>
        <tr><th>Дата</th><th>Заявки</th><th>Боты пойманы</th></tr>
        <?php foreach ($byday as $day=>$v): ?>
          <tr><td><?=h($day)?></td><td><?=$v['accepted']?></td><td><?=$v['bots']?></td></tr>
        <?php endforeach; ?>
      </table>
      <?php else: ?><div class="empty">Пока нет данных за период.</div><?php endif; ?>
    </div>
    <div>
      <h2>Происхождение заявок</h2>
      <?php if ($bySource): ?>
      <table>
        <tr><th>Источник / форма</th><th>Заявок</th></tr>
        <?php foreach ($bySource as $src=>$n): ?>
          <tr><td><?=h(clip($src,48))?></td><td><?=$n?></td></tr>
        <?php endforeach; ?>
      </table>
      <?php else: ?><div class="empty">Пока нет принятых заявок за период.</div><?php endif; ?>
    </div>
  </div>

  <h2>Заявки</h2>
  <?php if ($leads): ?>
  <table>
    <tr><th>Время</th><th>Имя</th><th>Телефон</th><th>Происхождение</th><th>IP</th><th>Статус</th></tr>
    <?php foreach ($leads as $r):
      $sus = (($r['t'] ?? '') === ''); ?>
      <tr>
        <td><?=dt($r['_ts'] ?? 0)?></td>
        <td><?=h(clip($r['name'] ?? '', 30))?></td>
        <td class="mono"><?=h($r['phone'] ?? '')?></td>
        <td><?=h(clip(($r['source'] ?? '') ?: ($r['form'] ?? ''), 32))?><br><span class="mono"><?=h(clip($r['page'] ?? '', 40))?></span></td>
        <td class="mono"><?=h($r['ip'] ?? '')?></td>
        <td><?php if($sus):?><span class="pill sus">прямой POST?</span><?php else:?><span class="pill real">форма</span><?php endif;?></td>
      </tr>
    <?php endforeach; ?>
  </table>
  <p class="mono">* «Подозрение на прямого бота» — заявка прошла как принятая, но без поля <b>t</b> (живая форма всегда шлёт время заполнения). Вероятно, прямой POST на lead.php мимо страницы.</p>
  <?php else: ?><div class="empty">Принятых заявок за период нет.</div><?php endif; ?>

  <h2>Пойманные боты</h2>
  <?php if ($bots): ?>
  <table>
    <tr><th>Время</th><th>Ловушка</th><th>Имя</th><th>Телефон</th><th>IP</th><th>User-Agent</th></tr>
    <?php foreach ($bots as $r):
      $d = $r['decision'] ?? ''; ?>
      <tr>
        <td><?=dt($r['_ts'] ?? 0)?></td>
        <td><?php if($d==='honeypot'):?><span class="pill hp">honeypot</span><?php else:?><span class="pill tt">time-trap</span><?php endif;?></td>
        <td><?=h(clip($r['name'] ?? '', 24))?></td>
        <td class="mono"><?=h($r['phone'] ?? '')?></td>
        <td class="mono"><?=h($r['ip'] ?? '')?></td>
        <td class="mono"><?=h(clip($r['ua'] ?? '', 46))?></td>
      </tr>
    <?php endforeach; ?>
  </table>
  <?php else: ?><div class="empty">Пойманных ботов за период нет.</div><?php endif; ?>

</div>
</body>
</html>
