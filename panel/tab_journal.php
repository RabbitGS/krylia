<?php
// Вкладка «Журнал» — единая лента событий: смены статусов, импорт/экспорт фида, заявки.
$catalog = load_catalog();
$statuses = $catalog['statuses'] ?? [];
$slabel = fn($k) => $statuses[$k]['label'] ?? $k;
$scolor = fn($k) => $statuses[$k]['color'] ?? '#999';

$filter = $_GET['ev'] ?? 'all';   // all|status|feed|lead
$events = [];

// 1) смены статусов
if ($filter === 'all' || $filter === 'status') {
  foreach (load_history(null, 500) as $r) {
    if (($r['kind'] ?? '') === 'price') {
      $events[] = [
        'ts' => strtotime($r['ts'] ?? '') ?: 0, 'kind' => 'price', 'ic' => '₽',
        'title' => '№' . ($r['number'] ?? $r['id'] ?? '') . ': цена ' . money((int)($r['from'] ?? 0)) . ' → ' . money((int)($r['to'] ?? 0)),
        'color' => '#8e44ad', 'sub' => 'изменил: ' . ($r['by'] ?? '—'),
      ];
      continue;
    }
    $events[] = [
      'ts' => strtotime($r['ts'] ?? '') ?: 0, 'kind' => 'status', 'ic' => '⇄',
      'title' => '№' . ($r['number'] ?? $r['id'] ?? '') . ': ' . $slabel($r['from'] ?? '') . ' → ' . $slabel($r['to'] ?? ''),
      'color' => $scolor($r['to'] ?? ''), 'sub' => 'изменил: ' . ($r['by'] ?? '—'),
    ];
  }
}
// 2) импорт/экспорт фида
if ($filter === 'all' || $filter === 'feed') {
  foreach (load_events(500) as $r) {
    if (($r['type'] ?? '') === 'feed_import') {
      $events[] = ['ts' => strtotime($r['ts'] ?? '') ?: 0, 'kind' => 'import', 'ic' => '↧',
        'title' => 'Импорт фида — ' . ($r['count'] ?? '?') . ' кв.', 'color' => '#2b7cd3',
        'sub' => ($r['file'] ?? '') . ' · ' . ($r['by'] ?? '')];
    } elseif (($r['type'] ?? '') === 'feed_export') {
      $events[] = ['ts' => strtotime($r['ts'] ?? '') ?: 0, 'kind' => 'export', 'ic' => '↥',
        'title' => 'Экспорт фида · ' . strtoupper($r['format'] ?? '') . ' — ' . ($r['count'] ?? '?') . ' кв.',
        'color' => '#8a5cd0', 'sub' => $r['src'] ?? ''];
    }
  }
}
// 3) заявки (accepted)
if ($filter === 'all' || $filter === 'lead') {
  $lp = leadlog_path();
  if (is_readable($lp) && ($fh = fopen($lp, 'r'))) {
    while (($ln = fgets($fh)) !== false) {
      $o = json_decode(trim($ln), true); if (!is_array($o)) continue;
      if (($o['decision'] ?? '') !== 'accepted') continue;
      $events[] = ['ts' => strtotime($o['ts'] ?? '') ?: 0, 'kind' => 'lead', 'ic' => '☎',
        'title' => 'Заявка: ' . (($o['name'] ?? '') ?: 'без имени'), 'color' => '#1f9d63',
        'sub' => ($o['phone'] ?? '') . ' · ' . (($o['source'] ?? '') ?: ($o['form'] ?? ''))];
    }
    fclose($fh);
  }
}

usort($events, fn($a, $b) => $b['ts'] <=> $a['ts']);
$events = array_slice($events, 0, 300);

$tabs = ['all' => 'Все', 'status' => 'Статусы', 'feed' => 'Фид (импорт/экспорт)', 'lead' => 'Заявки'];
?>
<div class="row" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
  <h2 style="margin:6px 0">Журнал событий</h2>
  <div class="ranges">
    <?php foreach ($tabs as $k=>$v): ?>
      <a class="<?=$filter===$k?'on':''?>" href="?tab=journal&ev=<?=h($k)?>"><?=h($v)?></a>
    <?php endforeach; ?>
  </div>
</div>

<?php if (!$events): ?>
<div class="empty">Событий пока нет.</div>
<?php else: ?>
<div class="jrnl">
  <?php $lastDay = null; foreach ($events as $e):
    $day = $e['ts'] ? date('d.m.Y', $e['ts']) : '—';
    if ($day !== $lastDay): $lastDay = $day; ?>
      <div class="jrnl-day"><?=h($day)?></div>
    <?php endif; ?>
    <div class="jrnl-row">
      <div class="jrnl-ic" style="background:<?=h($e['color'])?>"><?=$e['ic']?></div>
      <div class="jrnl-body">
        <div class="jrnl-title"><?=h($e['title'])?></div>
        <?php if (!empty($e['sub'])): ?><div class="jrnl-sub"><?=h($e['sub'])?></div><?php endif; ?>
      </div>
      <div class="jrnl-time"><?=$e['ts'] ? date('H:i', $e['ts']) : '—'?></div>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
