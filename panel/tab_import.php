<?php
// Вкладка «Импорт фида» — загрузка xlsx застройщика → каталог квартир.
// Двухшаговый: загрузка формирует предпросмотр (pending), «Применить» пишет каталог.
$cat = load_catalog();
$curFlats = count($cat['flats'] ?? []);
$curFrom = $cat['_imported_from'] ?? null;
$curAt = $cat['_imported_at'] ?? null;

$pj = @file_get_contents(pending_path());
$pending = $pj ? json_decode($pj, true) : null;
$hasPending = is_array($pending) && !empty($pending['flats']);

$msg = $_GET['msg'] ?? '';
$err = $_GET['err'] ?? '';

// сводка по набору квартир (для предпросмотра/текущего)
function feed_summary($flats) {
  $ents = []; $rooms = []; $prices = []; $noplan = 0;
  foreach ($flats as $f) {
    $ents[$f['building']] = ($ents[$f['building']] ?? 0) + 1;
    $rooms[(int)$f['rooms']] = ($rooms[(int)$f['rooms']] ?? 0) + 1;
    $prices[] = (int)$f['price'];
    if (!isset($f['plan'])) $noplan++;
  }
  ksort($rooms);
  uksort($ents, 'strnatcmp');
  return ['ents' => $ents, 'rooms' => $rooms,
          'pmin' => $prices ? min($prices) : 0, 'pmax' => $prices ? max($prices) : 0,
          'noplan' => $noplan];
}
?>
<h2 style="margin:6px 0">Импорт фида</h2>

<?php if ($msg): ?><div class="banner ok"><?=h($msg)?></div><?php endif; ?>
<?php if ($err): ?><div class="banner bad"><?=h($err)?></div><?php endif; ?>

<div class="two" style="align-items:start">
  <div>
    <h2>Текущий каталог</h2>
    <table>
      <tr><th>Квартир</th><td><b><?=$curFlats?></b></td></tr>
      <tr><th>Источник</th><td><?=$curFrom ? h($curFrom) : '<span class="mono">— (стартовый data.json)</span>'?></td></tr>
      <tr><th>Обновлён</th><td><?=$curAt ? h(date('d.m.Y H:i', strtotime($curAt))) : '—'?></td></tr>
    </table>
  </div>
  <div>
    <h2>Загрузить новый фид</h2>
    <form method="post" enctype="multipart/form-data" action="panel/api.php?action=import_upload">
      <div class="uploader">
        <input type="file" name="feed" accept=".xlsx" required>
        <button class="btn" type="submit">Разобрать фид</button>
      </div>
      <p class="mono" style="margin-top:8px">xlsx застройщика (лист «Крылья», шапка в строке 4, данные с 5-й: №, подъезд, этаж, комнат, площадь, цена/м², стоимость).</p>
    </form>
  </div>
</div>

<?php if ($hasPending):
  $s = feed_summary($pending['flats']); ?>
<h2>Предпросмотр импорта <span class="mono" style="text-transform:none;letter-spacing:0">(ещё не применён)</span></h2>
<div class="preview">
  <div class="cards">
    <div class="card ok"><div class="n"><?=count($pending['flats'])?></div><div class="l">Квартир в фиде</div></div>
    <div class="card"><div class="n"><?=count($s['ents'])?></div><div class="l">Подъездов</div></div>
    <div class="card"><div class="n"><?=$s['noplan']?></div><div class="l">Без техплана (SVG)</div></div>
    <div class="card"><div class="n" style="font-size:20px"><?=money($s['pmin'])?><br><span style="font-size:14px;color:var(--muted)">— <?=money($s['pmax'])?></span></div><div class="l">Цены от / до</div></div>
  </div>
  <div class="two">
    <div>
      <h2>По подъездам</h2>
      <table><tr><th>Подъезд</th><th>Квартир</th></tr>
      <?php foreach ($s['ents'] as $b=>$n): ?><tr><td><?=h($b)?></td><td><?=$n?></td></tr><?php endforeach; ?>
      </table>
    </div>
    <div>
      <h2>По комнатности</h2>
      <table><tr><th>Комнат</th><th>Квартир</th></tr>
      <?php foreach ($s['rooms'] as $k=>$n): ?><tr><td><?=roomsShort($k)?></td><td><?=$n?></td></tr><?php endforeach; ?>
      </table>
    </div>
  </div>
  <div class="preview-actions">
    <a class="btn primary" href="panel/api.php?action=import_apply">Применить каталог</a>
    <a class="btn ghost" href="panel/api.php?action=import_cancel">Отменить</a>
    <span class="sh-hint">Файл: <b><?=h($pending['_imported_from'] ?? '—')?></b>. Брони и продажи (статусы) сохранятся — они в отдельном файле состояния.</span>
  </div>
</div>
<?php endif; ?>
