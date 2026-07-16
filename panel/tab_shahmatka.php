<?php
// Вкладка «Шахматка» — сетка + боковая панель квартиры с выбором статуса и историей.
// Клик по квартире → панель справа: инфо + статусы (свободна/бронь/продано) + журнал изменений.
// Пустые ячейки (нет квартиры в фиде) = «нет в продаже» (красные, без данных).
$catalog = load_catalog();
$flats   = merged_flats($catalog);
$statuses = $catalog['statuses'] ?? [
  'free'     => ['label' => 'Свободна', 'color' => '#3f9d58'],
  'reserved' => ['label' => 'Бронь',    'color' => '#e0a312'],
  'promo'    => ['label' => 'Акция',    'color' => '#8e44ad'],
  'tech'     => ['label' => 'Тех. бронь', 'color' => '#6b7a8c'],
  'sold'     => ['label' => 'Продана',  'color' => '#c0392b'],
];
// планировки показываем со СВОЕГО хостинга (/flats/plans/) — туда же смотрит витрина
// и туда же загружаются новые файлы. plans_base (GitHub Pages) остаётся только в экспортных фидах.
$plansBase = '/flats';

$bnames = [];
foreach (($catalog['genplan']['buildings'] ?? []) as $b) $bnames[$b['id']] = $b['name'] ?? $b['id'];

// сгруппировать по подъезду
$byBld = [];
foreach ($flats as $f) $byBld[$f['building'] ?? '—'][] = $f;
uksort($byBld, fn($a,$b)=>strnatcmp($a,$b));

// счётчики
$tally = array_fill_keys(array_keys($statuses), 0);
$emptyCount = 0;
foreach ($flats as $f) { $s = $f['status'] ?? 'free'; if (isset($tally[$s])) $tally[$s]++; }

// данные квартир для JS
$flatsJs = [];
foreach ($flats as $f) {
  $flatsJs[$f['id']] = [
    'num' => $f['number'] ?? '', 'sec' => $bnames[$f['building']] ?? ($f['building'] ?? ''),
    'floor' => (int)($f['floor'] ?? 0), 'riser' => (int)($f['riser'] ?? 0),
    'rooms' => (int)($f['rooms'] ?? 0), 'area' => $f['area'] ?? null, 'price' => (int)($f['price'] ?? 0),
    'price_manual' => !empty($f['price_manual']),
    'status' => $f['status'] ?? 'free', 'nodata' => !empty($f['nodata']),
    'plan' => (isset($f['plan']) && $plansBase) ? $plansBase . '/' . ltrim($f['plan'], '/') : '',
    'plan_raw' => (string)($f['plan'] ?? ''),
    'meta_manual' => !empty($f['meta_manual']),
  ];
}

// варианты планировок для редактора данных: все файлы из flats/plans/ + пути из каталога
$planOptions = [];
foreach (glob(panel_docroot() . '/flats/plans/*.{webp,jpg,jpeg,png}', GLOB_BRACE) ?: [] as $pf) {
  $bn = basename($pf);
  if (strpos($bn, 'floor_') === 0) continue;   // поэтажные планы — не планировки квартир
  $planOptions['plans/' . $bn] = 1;
}
foreach ($flats as $f) if (!empty($f['plan'])) $planOptions[(string)$f['plan']] = 1;
$planOptions = array_keys($planOptions);
sort($planOptions, SORT_NATURAL);
?>
<div class="row" style="display:flex;justify-content:space-between;align-items:baseline;flex-wrap:wrap;gap:10px">
  <h2 style="margin:6px 0">Шахматка · <?=count($flats)?> кв.</h2>
</div>

<div class="sh-toolbar">
  <div class="sh-legend">
    <?php foreach ($statuses as $k=>$s): ?>
      <span><i style="background:<?=h($s['color'])?>"></i><?=h($s['label'])?> · <b style="color:var(--ink)"><?=$tally[$k]?? 0?></b></span>
    <?php endforeach; ?>
    <span><i class="nocom-i"></i>Коммерция / нет квартиры</span>
  </div>
  <div class="sh-hint">Клик по квартире — карточка со статусом справа. Проданные без номера в фиде помечены «нет данных».</div>
</div>

<?php foreach ($byBld as $bld => $list):
  $floors = array_map(fn($x)=>(int)$x['floor'], $list);
  $fmax = max($floors); $fmin = min($floors);
  $risers = array_values(array_unique(array_map(fn($x)=>(int)$x['riser'], $list)));
  sort($risers);
  $idx = [];
  foreach ($list as $f) $idx[(int)$f['floor']][(int)$f['riser']] = $f;
?>
<div class="sh-ent">
  <h3><?=h($bnames[$bld] ?? $bld)?></h3>
  <div class="sh-scroll">
    <table class="sh-grid"><tbody>
      <tr><td></td><?php foreach ($risers as $r): ?><td class="sh-hrow"><?=$r?></td><?php endforeach; ?></tr>
      <?php for ($fl = $fmax; $fl >= $fmin; $fl--): ?>
      <tr>
        <td class="sh-frow"><?=$fl?> эт.</td>
        <?php foreach ($risers as $r):
          $f = $idx[$fl][$r] ?? null;
          if (!$f) { echo '<td><div class="sh-empty" title="Коммерция / нет квартиры"></div></td>'; continue; }
          $st = $f['status'] ?? 'free';
          $color = ($statuses[$st]['color'] ?? '#999');
          $nodata = !empty($f['nodata']); ?>
          <td>
            <button class="sh-cell<?=$nodata?' nodata':''?>" style="background:<?=h($color)?>"
                    data-id="<?=h($f['id'])?>" data-status="<?=h($st)?>">
              <span class="num">№<?=h($f['number'])?></span>
              <?php if (!$nodata): ?><span class="meta"><?=roomsShort($f['rooms'])?> · <?=h($f['area'])?></span>
              <?php else: ?><span class="meta">нет данных</span><?php endif; ?>
            </button>
          </td>
        <?php endforeach; ?>
      </tr>
      <?php endfor; ?>
    </tbody></table>
  </div>
</div>
<?php endforeach; ?>

<!-- боковая панель квартиры -->
<div id="sh-overlay"></div>
<aside id="sh-panel" aria-hidden="true">
  <div class="shp-head">
    <div>
      <div class="shp-num">№<span id="shp-num"></span></div>
      <div class="shp-sub" id="shp-sub"></div>
    </div>
    <button id="shp-close" title="Закрыть">✕</button>
  </div>
  <img id="shp-plan" alt="Планировка" style="display:none">
  <div class="shp-specs" id="shp-specs"></div>
  <div class="shp-status-block">
    <div class="shp-label">Статус квартиры</div>
    <div class="shp-status-btns" id="shp-status-btns"></div>
  </div>
  <div class="shp-price-block" id="shp-price-block">
    <div class="shp-label">Цена, ₽</div>
    <div class="shp-price-row">
      <input type="text" inputmode="numeric" autocomplete="off" id="shp-price-input" class="shp-price-input" placeholder="напр. 5 200 000">
      <button id="shp-price-save" class="shp-price-save">Сохранить</button>
    </div>
    <div class="shp-price-note" id="shp-price-note"></div>
  </div>
  <div class="shp-meta-block">
    <div class="shp-label">Данные квартиры</div>
    <div class="shp-meta-grid">
      <label>Комнат
        <select id="shp-meta-rooms" class="shp-meta-inp">
          <option value="0">Студия</option>
          <?php for ($r = 1; $r <= 4; $r++): ?><option value="<?=$r?>"><?=$r?></option><?php endfor; ?>
        </select>
      </label>
      <label>Площадь, м²
        <input type="text" inputmode="decimal" autocomplete="off" id="shp-meta-area" class="shp-meta-inp" placeholder="напр. 44.33">
      </label>
      <label>Планировка
        <select id="shp-meta-plan" class="shp-meta-inp">
          <option value="">— без планировки —</option>
          <?php foreach ($planOptions as $p): ?>
            <option value="<?=h($p)?>"><?=h(basename($p))?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>…или загрузить файл планировки (webp/jpg/png)
        <input type="file" id="shp-meta-planfile" class="shp-meta-inp" accept=".webp,.jpg,.jpeg,.png">
      </label>
    </div>
    <button id="shp-meta-save" class="shp-price-save" style="width:100%">Сохранить данные</button>
    <div class="shp-price-note" id="shp-meta-note"></div>
  </div>
  <div class="shp-history-block">
    <div class="shp-label">История изменений</div>
    <div id="shp-history" class="shp-history"></div>
  </div>
</aside>

<div id="toast"></div>

<script>
(function(){
  var STATUSES = <?=json_encode($statuses, JSON_UNESCAPED_UNICODE)?>;
  var ORDER = <?=json_encode(array_values(array_intersect(['free','reserved','promo','tech','sold'], array_keys($statuses))) ?: array_keys($statuses))?>;
  var FLATS = <?=json_encode($flatsJs, JSON_UNESCAPED_UNICODE)?>;
  var PLANS_BASE = <?=json_encode($plansBase)?>;
  var curId = null;
  var toast = document.getElementById('toast'), tt;
  var panel = document.getElementById('sh-panel'), overlay = document.getElementById('sh-overlay');

  function say(msg, err){ toast.textContent = msg; toast.className = 'show' + (err?' err':''); clearTimeout(tt); tt = setTimeout(function(){toast.className='';}, 1600); }
  function money(v){ return (v||0).toLocaleString('ru-RU') + ' ₽'; }
  function roomsShort(n){ return n<=0 ? 'Студия' : n+'-к'; }
  function fmtTs(iso){ var d=new Date(iso); if(isNaN(d)) return iso; var p=function(x){return (x<10?'0':'')+x;}; return p(d.getDate())+'.'+p(d.getMonth()+1)+'.'+d.getFullYear()+' '+p(d.getHours())+':'+p(d.getMinutes()); }

  function renderHistory(list){
    var el = document.getElementById('shp-history');
    if(!list || !list.length){ el.innerHTML = '<div class="shp-hist-empty">Изменений пока не было.</div>'; return; }
    el.innerHTML = list.map(function(r){
      if(r.kind==='price'){
        return '<div class="shp-hist"><span class="shp-hist-dt">'+fmtTs(r.ts)+'</span>'+
               '<span class="shp-hist-ch">Цена: '+money(+r.from)+' → <b>'+money(+r.to)+'</b></span></div>';
      }
      if(r.kind==='meta'){
        return '<div class="shp-hist"><span class="shp-hist-dt">'+fmtTs(r.ts)+'</span>'+
               '<span class="shp-hist-ch">Данные: <b>'+r.to+'</b>'+(r.by?' ('+r.by+')':'')+'</span></div>';
      }
      var from = (STATUSES[r.from]||{}).label || r.from, to = (STATUSES[r.to]||{}).label || r.to;
      var cto = (STATUSES[r.to]||{}).color || '#999';
      return '<div class="shp-hist"><span class="shp-hist-dt">'+fmtTs(r.ts)+'</span>'+
             '<span class="shp-hist-ch">'+from+' → <b style="color:'+cto+'">'+to+'</b></span></div>';
    }).join('');
  }

  function renderStatusBtns(id, cur){
    var wrap = document.getElementById('shp-status-btns');
    wrap.innerHTML = ORDER.map(function(k){
      var s = STATUSES[k]||{}, on = (k===cur);
      return '<button class="shp-sbtn'+(on?' on':'')+'" data-status="'+k+'" '+
             'style="'+(on?('background:'+s.color+';border-color:'+s.color+';color:#fff'):('color:'+s.color))+'">'+
             (s.label||k)+'</button>';
    }).join('');
    wrap.querySelectorAll('.shp-sbtn').forEach(function(b){
      b.addEventListener('click', function(){ setStatus(id, b.getAttribute('data-status')); });
    });
  }

  function openPanel(id){
    var f = FLATS[id]; if(!f) return;
    curId = id;
    document.getElementById('shp-num').textContent = f.num;
    document.getElementById('shp-sub').textContent = f.sec + ' · ' + f.floor + ' этаж · стояк ' + f.riser;
    var plan = document.getElementById('shp-plan');
    if(f.plan){ plan.src = f.plan; plan.style.display='block'; } else { plan.style.display='none'; }
    renderSpecs(f);
    // блок цены: только для квартир с данными (у проданных-фантомов цены нет)
    var pb = document.getElementById('shp-price-block');
    if(f.nodata){ pb.style.display = 'none'; }
    else {
      pb.style.display = '';
      document.getElementById('shp-price-input').value = fmtPrice(f.price);
      renderPriceNote(f);
    }
    renderStatusBtns(id, f.status);
    // редактор данных квартиры
    document.getElementById('shp-meta-rooms').value = String(f.rooms || 0);
    document.getElementById('shp-meta-area').value = (f.area != null && f.area !== '') ? String(f.area) : '';
    var ps = document.getElementById('shp-meta-plan');
    ps.value = f.plan_raw || '';
    if (ps.value !== (f.plan_raw || '')) ps.value = '';   // путь не из списка — сброс на «без планировки»
    renderMetaNote(f);
    document.getElementById('shp-history').innerHTML = '<div class="shp-hist-empty">Загрузка…</div>';
    panel.classList.add('open'); overlay.classList.add('show'); panel.setAttribute('aria-hidden','false');
    fetch('panel/api.php?action=history&id='+encodeURIComponent(id))
      .then(function(r){return r.json();}).then(function(res){ renderHistory(res.history); })
      .catch(function(){ renderHistory([]); });
  }
  function spec(l,v){ return '<div class="shp-spec"><b>'+l+'</b><span>'+v+'</span></div>'; }

  function renderSpecs(f){
    var el = document.getElementById('shp-specs');
    if(f.nodata){
      el.innerHTML = spec('Комнат','—') + spec('Площадь','—') + spec('Этаж',f.floor) + spec('Цена','—') +
        '<div class="shp-spec full"><b>Данные</b><span>Нет в фиде — квартира считается проданной. Комнаты и площадь можно заполнить вручную в блоке «Данные квартиры» ниже.</span></div>';
    } else {
      el.innerHTML = spec('Комнат',roomsShort(f.rooms)) + spec('Площадь',f.area+' м²') +
        spec('Этаж',f.floor) + spec('Цена',money(f.price));
    }
  }

  function renderMetaNote(f){
    var n = document.getElementById('shp-meta-note');
    if(f.nodata){
      n.textContent = 'Данных нет (квартиры не было в фиде). Заполните комнаты и площадь — сохранится и переживёт импорт фида.';
      n.className = 'shp-price-note';
    } else if(f.meta_manual){
      n.textContent = 'Данные заданы вручную или автозаполнением. При импорте фида сохранятся.';
      n.className = 'shp-price-note manual';
    } else {
      n.textContent = 'Данные из фида застройщика. Можно переопределить вручную (фид эту квартиру не перезапишет, пока её нет в фиде).';
      n.className = 'shp-price-note';
    }
  }

  function saveMeta(id){
    var f = FLATS[id]; if(!f) return;
    var rooms = document.getElementById('shp-meta-rooms').value;
    var area = (document.getElementById('shp-meta-area').value || '').replace(',', '.').trim();
    var plan = document.getElementById('shp-meta-plan').value;
    if(!area || isNaN(parseFloat(area))){ say('Введите площадь числом', true); return; }
    var btn = document.getElementById('shp-meta-save'); btn.disabled = true;
    fetch('panel/api.php?action=set_meta', {method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({id:id, meta:{rooms:+rooms, area:area, plan:plan}})})
      .then(function(r){return r.json();}).then(function(res){
        btn.disabled = false;
        if(!res.ok){ say('Не сохранилось: '+(res.error||'ошибка'), true); return; }
        var nf = res.flat || {};
        f.rooms = +(nf.rooms||0); f.area = nf.area != null ? nf.area : parseFloat(area);
        f.plan_raw = nf.plan || plan; f.nodata = !!nf.nodata; f.meta_manual = !nf.nodata;
        // картинка планировки в карточке
        var img = document.getElementById('shp-plan');
        if(f.plan_raw && PLANS_BASE){ f.plan = PLANS_BASE + '/' + f.plan_raw.replace(/^\//,''); img.src = f.plan; img.style.display='block'; }
        else { f.plan = ''; img.style.display='none'; }
        // ячейка в сетке: убрать «нет данных», показать комнаты/площадь
        var cell = document.querySelector('.sh-cell[data-id="'+id+'"]');
        if(cell && !f.nodata){
          cell.classList.remove('nodata');
          var mt = cell.querySelector('.meta'); if(mt) mt.textContent = roomsShort(f.rooms)+' · '+f.area;
        }
        // цена стала доступна
        var pb = document.getElementById('shp-price-block');
        if(!f.nodata){ pb.style.display=''; document.getElementById('shp-price-input').value = fmtPrice(f.price); renderPriceNote(f); }
        renderSpecs(f); renderMetaNote(f); renderHistory(res.history);
        say('№'+f.num+': данные сохранены');
      }).catch(function(){ btn.disabled = false; say('Сеть недоступна', true); });
  }

  // «5 200 000» из числа; при вводе форматируем по мере набора
  function fmtPrice(v){ v=parseInt(String(v).replace(/\D/g,''),10)||0; return v ? v.toLocaleString('ru-RU') : ''; }

  function renderPriceNote(f){
    var n = document.getElementById('shp-price-note');
    n.textContent = f.price_manual
      ? 'Цена задана вручную. При импорте фида застройщика сбросится на цену из фида.'
      : 'Цена из фида застройщика. Можно переопределить вручную.';
    n.className = 'shp-price-note' + (f.price_manual ? ' manual' : '');
  }

  function setPrice(id){
    var f = FLATS[id]; if(!f) return;
    var inp = document.getElementById('shp-price-input');
    var val = parseInt((inp.value||'').replace(/\D/g,''),10);
    if(!val || val<=0){ say('Введите цену числом', true); return; }
    if(val===f.price){ say('Цена не изменилась'); return; }
    var btn = document.getElementById('shp-price-save'); btn.disabled = true;
    fetch('panel/api.php?action=set_price', {method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({id:id, price:val})})
      .then(function(r){return r.json();}).then(function(res){
        btn.disabled = false;
        if(!res.ok){ say('Не сохранилось: '+(res.error||'ошибка'), true); return; }
        f.price = res.price; f.price_manual = true;
        inp.value = fmtPrice(res.price);
        renderSpecs(f); renderPriceNote(f); renderHistory(res.history);
        say('№'+f.num+' → '+money(f.price));
      }).catch(function(){ btn.disabled = false; say('Сеть недоступна', true); });
  }

  function closePanel(){ panel.classList.remove('open'); overlay.classList.remove('show'); panel.setAttribute('aria-hidden','true'); curId=null; }

  function setStatus(id, status){
    var f = FLATS[id]; if(!f || f.status===status){ return; }
    fetch('panel/api.php?action=set_status', {method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({id:id, status:status})})
      .then(function(r){return r.json();}).then(function(res){
        if(!res.ok){ say('Не сохранилось: '+(res.error||'ошибка'), true); return; }
        f.status = status;
        // обновить ячейку в сетке
        var cell = document.querySelector('.sh-cell[data-id="'+id+'"]');
        if(cell){ cell.setAttribute('data-status', status); cell.style.background = (STATUSES[status]||{}).color || '#999'; }
        renderStatusBtns(id, status);
        renderHistory(res.history);
        say('№'+f.num+' → '+((STATUSES[status]||{}).label||status));
      }).catch(function(){ say('Сеть недоступна', true); });
  }

  document.querySelectorAll('.sh-cell[data-id]').forEach(function(btn){
    btn.addEventListener('click', function(){ openPanel(btn.getAttribute('data-id')); });
  });
  document.querySelectorAll('.sh-nosale').forEach(function(el){
    el.addEventListener('click', function(){ say('Нет в продаже — данных по этой квартире в фиде нет', true); });
  });
  // цена: форматирование при вводе, сохранение по кнопке и Enter
  (function(){
    var inp = document.getElementById('shp-price-input');
    inp.addEventListener('input', function(){
      var pos = inp.value.length - inp.selectionStart;
      inp.value = fmtPrice(inp.value);
      inp.selectionStart = inp.selectionEnd = Math.max(0, inp.value.length - pos);
    });
    inp.addEventListener('keydown', function(e){ if(e.key==='Enter'){ e.preventDefault(); if(curId) setPrice(curId); } });
    document.getElementById('shp-price-save').addEventListener('click', function(){ if(curId) setPrice(curId); });
  })();
  document.getElementById('shp-meta-save').addEventListener('click', function(){ if(curId) saveMeta(curId); });
  // загрузка файла планировки: сразу шлём на сервер, путь подставляем в select
  document.getElementById('shp-meta-planfile').addEventListener('change', function(){
    var inp = this, file = inp.files && inp.files[0];
    if(!file) return;
    if(file.size > 8*1024*1024){ say('Файл больше 8 МБ', true); inp.value=''; return; }
    var fd = new FormData(); fd.append('plan', file);
    say('Загружаю планировку…');
    fetch('panel/api.php?action=upload_plan', {method:'POST', body: fd})
      .then(function(r){return r.json();}).then(function(res){
        if(!res.ok){ say('Не загрузилось: '+(res.error||'ошибка'), true); inp.value=''; return; }
        var sel = document.getElementById('shp-meta-plan');
        // добавить option, если такого пути ещё нет, и выбрать его
        if(!Array.prototype.some.call(sel.options, function(o){ return o.value===res.plan; })){
          var o = document.createElement('option'); o.value = res.plan;
          o.textContent = res.plan.replace(/^plans\//,'');
          sel.appendChild(o);
        }
        sel.value = res.plan;
        say('Планировка загружена — нажмите «Сохранить данные»');
      }).catch(function(){ say('Сеть недоступна', true); inp.value=''; });
  });
  document.getElementById('shp-close').addEventListener('click', closePanel);
  overlay.addEventListener('click', closePanel);
  document.addEventListener('keydown', function(e){ if(e.key==='Escape') closePanel(); });
  // диплинк: panel.php?tab=shahmatka#flat=kr252 сразу открывает карточку квартиры
  var m = (location.hash||'').match(/flat=([\w-]+)/); if(m && FLATS[m[1]]) openPanel(m[1]);
})();
</script>
