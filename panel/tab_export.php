<?php
// Вкладка «Экспорт фида» — выгрузка текущей шахматки на площадки.
// Модель: постоянный URL фида + ключ → площадка забирает сама по расписанию.
$catalog = load_catalog();
$flats = merged_flats($catalog);
$free = 0; foreach ($flats as $f) if (($f['status'] ?? 'free') === 'free') $free++;
$busy = count($flats) - $free;

$cfg = panel_cfg();
$key = $cfg['feed_key'] ?? '';
$hasKey = $key !== '';
// абсолютный базовый URL до export.php
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'krylia-tver.ru';
$base = $scheme . '://' . $host . dirname($_SERVER['SCRIPT_NAME'] ?? '/panel.php') . '/panel/export.php';
$base = preg_replace('#/panel/panel/#', '/panel/', $base);
function feed_url($base, $key, $fmt) { return $base . '?format=' . $fmt . '&key=' . rawurlencode($key); }

// площадки: какие берут YRL, какие свой формат
$platforms = [
  ['name' => 'Яндекс Недвижимость', 'fmt' => 'yrl', 'note' => 'Формат YRL. Ссылку вставить в кабинете Яндекс.Недвижимости → фид заберётся автоматически.', 'ready' => true],
  ['name' => 'ЦИАН',                'fmt' => 'yrl', 'note' => 'Принимает YRL (Яндекс-формат). Ссылка та же, указывается в кабинете ЦИАН.', 'ready' => true],
  ['name' => 'Домклик',             'fmt' => 'yrl', 'note' => 'Как правило YRL-совместимый фид. ⚠️ Точный формат/условия уточнить в кабинете Домклик перед подключением.', 'ready' => true],
  ['name' => 'Авито',               'fmt' => '',    'note' => 'Своя XML-схема (Авито Автозагрузка). Отдельный формат — в разработке.', 'ready' => false],
];
?>
<h2 style="margin:6px 0">Экспорт фида</h2>

<div class="cards" style="grid-template-columns:repeat(3,1fr)">
  <div class="card ok"><div class="n"><?=$free?></div><div class="l">В выгрузке (свободные)</div></div>
  <div class="card warn"><div class="n"><?=$busy?></div><div class="l">Скрыты (бронь / продано)</div></div>
  <div class="card"><div class="n"><?=count($flats)?></div><div class="l">Всего в каталоге</div></div>
</div>

<?php if (!$hasKey): ?>
<div class="banner bad">Не задан ключ фида (<span class="mono">feed_key</span> в panel_secret.php). Без него площадки не смогут забирать фид по ссылке.</div>
<?php endif; ?>

<p class="sh-hint" style="margin:6px 0 18px">Как это работает: вы даёте площадке <b>постоянную ссылку</b> на фид. Площадка сама забирает её по расписанию (обычно раз в сутки). Поставили бронь в шахматке — на следующем заборе объявление снимется автоматически, вручную ничего не заливать.</p>

<div class="platforms">
<?php foreach ($platforms as $p):
  $url = ($p['ready'] && $hasKey) ? feed_url($base, $key, $p['fmt']) : ''; ?>
  <div class="pform<?=$p['ready']?'':' off'?>">
    <div class="pform-head">
      <b><?=h($p['name'])?></b>
      <?php if ($p['ready']): ?><span class="pill real"><?=strtoupper($p['fmt'])?></span><?php else: ?><span class="pill rep">скоро</span><?php endif; ?>
    </div>
    <div class="pform-note"><?=h($p['note'])?></div>
    <?php if ($url): ?>
      <div class="pform-url">
        <input type="text" readonly value="<?=h($url)?>" onclick="this.select()">
        <button class="btn" data-copy="<?=h($url)?>">Копировать</button>
        <a class="btn ghost" href="<?=h($url)?>" target="_blank">Открыть</a>
      </div>
    <?php endif; ?>
  </div>
<?php endforeach; ?>
</div>

<h2>Скачать вручную</h2>
<div class="platforms">
  <div class="pform">
    <div class="pform-head"><b>YRL (XML)</b><span class="pill real">Яндекс/ЦИАН/Домклик</span></div>
    <div class="pform-note">Полный фид всех свободных квартир в формате Яндекс.Недвижимости.</div>
    <div class="pform-url"><a class="btn primary" href="panel/export.php?format=yrl">Скачать .xml</a>
      <a class="btn ghost" href="panel/export.php?format=yrl&include=all">Со всеми статусами</a></div>
  </div>
  <div class="pform">
    <div class="pform-head"><b>CSV / JSON</b><span class="pill rep">универсально</span></div>
    <div class="pform-note">Простая выгрузка для Excel или ручной обработки под любой сервис.</div>
    <div class="pform-url"><a class="btn" href="panel/export.php?format=csv">Скачать .csv</a>
      <a class="btn" href="panel/export.php?format=json">Скачать .json</a></div>
  </div>
</div>

<div id="toast"></div>
<script>
document.querySelectorAll('[data-copy]').forEach(function(b){
  b.addEventListener('click', function(){
    var t = b.getAttribute('data-copy');
    navigator.clipboard.writeText(t).then(function(){
      var el = document.getElementById('toast'); el.textContent = 'Ссылка скопирована'; el.className = 'show';
      setTimeout(function(){ el.className = ''; }, 1500);
    });
  });
});
</script>
