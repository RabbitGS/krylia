<?php
// ЖК «Крылья» — админ-панель (хаб). Вкладки: заявки/боты · шахматка · импорт · экспорт.
// Единая авторизация и слой данных — в panel/_boot.php.
require_once __DIR__ . '/panel/_boot.php';
panel_require_auth();

$tabs = [
  'leads'     => 'Заявки',
  'shahmatka' => 'Шахматка',
  'import'    => 'Импорт фида',
  'export'    => 'Экспорт фида',
  'floors'    => 'Планы этажей',
  'journal'   => 'Журнал',
];
$tab = $_GET['tab'] ?? 'leads';
if (!isset($tabs[$tab])) $tab = 'leads';

// бейджи вкладок
$flats = merged_flats();
$occupied = 0;
foreach ($flats as $f) if (in_array($f['status'] ?? '', ['reserved','sold'], true)) $occupied++;
$badge = ['shahmatka' => $occupied . '/' . count($flats)];

// заявок сегодня — быстрый проход лога
$todayLeads = 0; $since = time() - 86400;
$lp = leadlog_path();
if (is_readable($lp) && ($fh = fopen($lp, 'r'))) {
  while (($ln = fgets($fh)) !== false) {
    $o = json_decode(trim($ln), true);
    if (!is_array($o)) continue;
    if (($o['decision'] ?? '') !== 'accepted') continue;
    if ((isset($o['ts']) ? strtotime($o['ts']) : 0) >= $since) $todayLeads++;
  }
  fclose($fh);
}
if ($todayLeads > 0) $badge['leads'] = (string)$todayLeads;
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Крылья · админ-панель</title>
<link rel="stylesheet" href="panel/panel.css">
</head>
<body>
<header class="top">
  <div class="row">
    <div>
      <h1>ЖК «Крылья» · админ-панель</h1>
      <div class="sub">управление сайтом: заявки · шахматка · фид</div>
    </div>
  </div>
  <nav class="tabs">
    <?php foreach ($tabs as $k=>$label): ?>
      <a class="<?=$tab===$k?'on':''?>" href="?tab=<?=h($k)?>"><?=h($label)?><?php
        if (!empty($badge[$k])): ?><span class="c"><?=h($badge[$k])?></span><?php endif; ?></a>
    <?php endforeach; ?>
  </nav>
</header>

<div class="wrap<?= $tab === 'floors' ? ' wrap--full' : '' ?>">
<?php require __DIR__ . '/panel/tab_' . $tab . '.php'; ?>
</div>
</body>
</html>
