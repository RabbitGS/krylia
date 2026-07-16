<?php
// Вкладка «Заявки и боты» — аналитика происхождения заявок + антибот.
// Источник: ../lead_log.jsonl (пишет lead.php). Только чтение.

$range = $_GET['range'] ?? '7';
$validRanges = ['1', '7', '30', 'all'];
if (!in_array($range, $validRanges, true)) $range = '7';
$now = time();
// выбор конкретной даты (?date=YYYY-MM-DD) имеет приоритет над периодом
$date = (string)($_GET['date'] ?? '');
$dateMode = $date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && strtotime($date) !== false;
if ($dateMode) {
  $since = strtotime($date . ' 00:00:00');
  $until = strtotime($date . ' 23:59:59');
} else {
  $since = $range === 'all' ? 0 : $now - ((int)$range) * 86400;
  $until = PHP_INT_MAX;
}

$logPath = leadlog_path();
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
      if ($ts && ($ts < $since || $ts > $until)) continue;
      $o['_ts'] = $ts;
      $rows[] = $o;
    }
    fclose($fh);
  }
}

$cnt = ['accepted' => 0, 'honeypot' => 0, 'timetrap' => 0, 'bad_phone' => 0];
$suspect = 0;
$byday = [];
$bySource = [];
$phoneFreq = [];
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
    $ph = preg_replace('/\D/', '', (string)($r['phone'] ?? ''));
    if ($ph) $phoneFreq[$ph] = ($phoneFreq[$ph] ?? 0) + 1;
  }
}
krsort($byday);
arsort($bySource);

$leads = array_values(array_filter($rows, fn($r) => ($r['decision'] ?? '') === 'accepted'));
$bots  = array_values(array_filter($rows, fn($r) => in_array($r['decision'] ?? '', ['honeypot', 'timetrap'], true)));
usort($leads, fn($a, $b) => ($b['_ts'] ?? 0) <=> ($a['_ts'] ?? 0));
usort($bots,  fn($a, $b) => ($b['_ts'] ?? 0) <=> ($a['_ts'] ?? 0));

$totalReq = count($rows);
$botsCaught = $cnt['honeypot'] + $cnt['timetrap'];
$rangeLabel = $dateMode
  ? ('за ' . date('d.m.Y', $since))
  : ['1' => 'сегодня', '7' => '7 дней', '30' => '30 дней', 'all' => 'всё время'][$range];
?>
<div class="row" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
  <h2 style="margin:6px 0">Заявки · <?=h($rangeLabel)?></h2>
  <div class="ranges">
    <?php foreach (['1'=>'Сегодня','7'=>'7 дней','30'=>'30 дней','all'=>'Всё'] as $k=>$v): ?>
      <a class="<?=(!$dateMode && $range===$k)?'on':''?>" href="?tab=leads&range=<?=h($k)?>"><?=h($v)?></a>
    <?php endforeach; ?>
    <label class="datepick<?=$dateMode?' on':''?>" title="Выбрать конкретную дату">📅
      <span><?=$dateMode ? h(date('d.m.Y', $since)) : 'Выбрать дату'?></span>
      <input type="date" value="<?=$dateMode ? h(date('Y-m-d', $since)) : ''?>" max="<?=date('Y-m-d')?>"
        onchange="if(this.value)location.href='?tab=leads&date='+this.value">
    </label>
  </div>
</div>
<style>
  .ranges { display:flex; align-items:center; gap:6px; flex-wrap:wrap; }
  .datepick { position:relative; display:inline-flex; align-items:center; gap:6px; cursor:pointer;
    padding:6px 12px; border-radius:8px; background:#1a222c; border:1px solid #2a3540; color:#c3ced8;
    font-size:13px; user-select:none; }
  .datepick:hover { border-color:#3a86a8; }
  .datepick.on { background:#1f9fd6; border-color:#1f9fd6; color:#04222f; font-weight:600; }
  .datepick input[type=date] { position:absolute; inset:0; opacity:0; cursor:pointer; width:100%; height:100%; }
</style>

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

<h2>Заявки <span class="mono" style="text-transform:none;letter-spacing:0">(клик по строке — подробно)</span></h2>
<?php if ($leads): ?>
<?php foreach ($leads as $r):
  $sus = (($r['t'] ?? '') === '');
  $ph = preg_replace('/\D/', '', (string)($r['phone'] ?? ''));
  $rep = $ph ? ($phoneFreq[$ph] ?? 1) : 1;
  $utm = trim(((string)($r['utm_source'] ?? '')) . '/' . ((string)($r['utm_medium'] ?? '')) . '/' . ((string)($r['utm_campaign'] ?? '')), '/'); ?>
<details class="lead">
  <summary>
    <span class="s-dt"><?=dt($r['_ts'] ?? 0)?></span>
    <span class="s-name"><?=h(clip(($r['name'] ?? '') ?: 'Без имени', 30))?></span>
    <span class="mono s-phone"><?=h($r['phone'] ?? '')?></span>
    <?php if($sus):?><span class="pill sus">прямой POST?</span><?php else:?><span class="pill real">форма</span><?php endif;?>
    <?php if($rep>1):?><span class="pill rep" title="заявок с этого телефона за период">×<?=$rep?></span><?php endif;?>
  </summary>
  <div class="stat">
    <div><b>Как перешёл на сайт</b><span><?=h(howCame($r))?></span></div>
    <div><b>Устройство</b><span><?=h(device($r['ua'] ?? ''))?></span></div>
    <div><b>Заявка пришла со страницы</b><span class="mono"><?=h(($r['page'] ?? '') ?: '—')?></span></div>
    <div><b>Раздел / форма</b><span><?=h(($r['source'] ?? '') ?: ($r['form'] ?? '—'))?></span></div>
    <div><b>Referrer</b><span class="mono"><?=h(($r['referrer'] ?? '') ?: '—')?></span></div>
    <div><b>UTM (source/medium/campaign)</b><span class="mono"><?=h($utm ?: '—')?></span></div>
    <div><b>Заявок с этого телефона</b><span><?=$rep?> <span class="mono">(за период)</span></span></div>
    <div><b>ClientID Метрики</b><span class="mono"><?=h(($r['cid'] ?? '') ?: '—')?></span></div>
    <div><b>IP</b><span class="mono"><?=h(($r['ip'] ?? '') ?: '—')?></span></div>
    <div><b>Время заполнения формы</b><span><?=($r['t'] ?? '') !== '' ? h($r['t']).' мс' : '— нет (подозрение на прямой POST)'?></span></div>
    <div class="full"><b>User-Agent</b><span class="mono"><?=h(($r['ua'] ?? '') ?: '—')?></span></div>
  </div>
</details>
<?php endforeach; ?>
<p class="mono">* «прямой POST?» — заявка принята, но без поля <b>t</b> (живая форма всегда шлёт время заполнения) → вероятно бот напрямую на lead.php мимо страницы.</p>
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
