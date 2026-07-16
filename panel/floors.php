<?php require_once __DIR__ . '/_boot.php'; panel_require_auth(); ?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Разметчик планов этажей — Шахматка Крылья</title>
<style>
  :root { --bg:#12161c; --panel:#1c232c; --line:#2c3742; --accent:#1f9fd6; --txt:#e6edf3; --muted:#8b98a5; }
  * { box-sizing:border-box; }
  body { margin:0; font:14px/1.4 system-ui,Segoe UI,Roboto,sans-serif; background:var(--bg); color:var(--txt); }
  header { padding:10px 16px; background:var(--panel); border-bottom:1px solid var(--line); display:flex; gap:12px; align-items:center; flex-wrap:wrap; }
  header h1 { font-size:15px; margin:0; font-weight:600; }
  select, input, button { font:inherit; color:var(--txt); background:#0e1319; border:1px solid var(--line); border-radius:6px; padding:6px 10px; }
  button { cursor:pointer; background:#243040; }
  button:hover { background:#2c3a4c; }
  button.primary { background:var(--accent); color:#04222f; border-color:var(--accent); font-weight:600; }
  button.on { background:var(--accent); color:#04222f; border-color:var(--accent); font-weight:600; }
  .stage.drawing { cursor:crosshair; }
  .wrap { display:flex; height:calc(100vh - 52px); }
  .stage { flex:1; overflow:auto; position:relative; background:#0a0d11; }
  .stage-inner { position:relative; margin:16px; width:max-content; }
  .stage img { display:block; max-width:none; user-select:none; -webkit-user-drag:none; }
  svg { position:absolute; inset:0; overflow:visible; }
  polygon { fill:rgba(31,159,214,.18); stroke:var(--accent); stroke-width:2; cursor:pointer; }
  polygon.done { fill:rgba(63,157,88,.20); stroke:#3f9d58; }
  polygon.sel { fill:rgba(224,163,18,.30); stroke:#e0a312; stroke-width:3; }
  .vtx { fill:#fff; stroke:var(--accent); stroke-width:1.5; cursor:grab; }
  .vadd { fill:rgba(31,159,214,.45); stroke:#fff; stroke-width:1; cursor:copy; }
  .vadd:hover { fill:var(--accent); r:7; }
  circle.mk { cursor:grab; }
  .lbl { fill:#fff; font-size:13px; font-weight:600; paint-order:stroke; stroke:#000; stroke-width:3px; pointer-events:none; }
  aside { width:320px; background:var(--panel); border-left:1px solid var(--line); padding:14px; overflow:auto; display:flex; flex-direction:column; gap:12px; }
  aside h2 { font-size:13px; margin:0 0 4px; color:var(--muted); text-transform:uppercase; letter-spacing:.04em; }
  .zrow { display:flex; align-items:center; gap:6px; padding:6px 8px; background:#0e1319; border:1px solid var(--line); border-radius:6px; }
  .zrow.sel { border-color:var(--accent); }
  .zrow span { flex:1; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
  .zrow button { padding:3px 7px; font-size:12px; }
  textarea { width:100%; height:120px; background:#0e1319; color:var(--txt); border:1px solid var(--line); border-radius:6px; font:12px/1.4 monospace; padding:8px; }
  .hint { color:var(--muted); font-size:12.5px; }
  .savestatus { font-size:14px; font-weight:600; padding:9px 12px; border-radius:8px; }
  .savestatus.is-ok { background:rgba(63,157,88,.16); color:#5bd07a; }
  .savestatus.is-dirty { background:rgba(224,163,18,.16); color:#e6b13c; }
  .savestatus.is-saving { background:rgba(31,159,214,.16); color:#4fbde6; }
  .savestatus.is-err { background:rgba(192,57,43,.18); color:#e07a6f; }
  .zoom { display:flex; gap:4px; align-items:center; }
  label.f { display:flex; flex-direction:column; gap:3px; font-size:12px; color:var(--muted); }
  label.f input { width:100%; }
</style>
</head>
<body>
<header>
  <a href="../panel.php" target="_top" style="color:var(--accent);text-decoration:none;font-size:13px">← Панель</a>
  <h1>Разметчик планов этажей</h1>
  <select id="plan">
    <option value="plans/floor_s1_typ.webp">Секция 1 · типовой 2–10</option>
    <option value="plans/floor_s1_g.webp">Секция 1 · 1-й этаж</option>
    <option value="plans/floor_s2_typ.webp">Секция 2 · типовой 2–10</option>
    <option value="plans/floor_s2_g.webp">Секция 2 · 1-й этаж</option>
  </select>
  <div class="zoom">масштаб <button id="zout">−</button><span id="zval">100%</span><button id="zin">+</button></div>
  <button id="undo" title="Ctrl+Z">↶ Отмена</button>
  <span class="hint"><b>Тащи контур</b> — двигать квартиру целиком · <b>тащи белые</b> углы · <b>голубая</b> — добавить угол · <b>ПКМ по белой</b> — удалить · <b>2× клик по пустому</b> — обвести новую · <b>колесо</b> — зум · <b>пробел+тащить</b> — двигать план</span>
</header>
<div class="wrap">
  <div class="stage"><div class="stage-inner" id="inner">
    <img id="img" alt="">
    <svg id="svg"></svg>
  </div></div>
  <aside>
    <div>
      <h2>Метка текущей квартиры</h2>
      <input id="label" placeholder="напр. п7 · 2к 64,86" style="width:100%">
      <p class="hint">Ставится на замыкаемый полигон. Что видишь на плане — комнатность/площадь и подъезд.</p>
    </div>
    <div>
      <h2>Зоны на этом плане</h2>
      <div id="zones" style="display:flex;flex-direction:column;gap:6px"></div>
    </div>
    <div>
      <h2>Сохранение</h2>
      <div id="savestatus" class="savestatus is-ok">всё сохранено ✓</div>
      <p class="hint">Правки сохраняются <b>автоматически</b> и сразу попадают на витрину. Нажимать ничего не нужно.</p>
    </div>
  </aside>
</div>
<script>
(function(){
  var img=document.getElementById('img'), svg=document.getElementById('svg'), inner=document.getElementById('inner');
  var planSel=document.getElementById('plan'), zonesEl=document.getElementById('zones'), jsonEl=document.getElementById('json');
  var labelInp=document.getElementById('label');
  var stage=document.querySelector('.stage');
  var store={};            // { planFile: [ {label, pts:[[xPct,yPct]...]} ] }
  var cur=[];              // текущий незамкнутый полигон (в % )
  var sel=-1;              // индекс выбранной зоны
  var zoom=1, natW=0, natH=0;
  var drawMode=false;      // режим рисования нового контура (иначе клики по плану не плодят точки)

  // --- история для Ctrl+Z / Ctrl+Y ---
  var histStack=[], redoStack=[];
  function snapshot(){ return JSON.stringify(store); }
  function pushHist(){ histStack.push(snapshot()); if(histStack.length>100) histStack.shift(); redoStack.length=0; }
  function undo(){ if(!histStack.length) return; redoStack.push(snapshot()); store=JSON.parse(histStack.pop()); sel=-1;cur=[]; render(); }
  function redo(){ if(!redoStack.length) return; histStack.push(snapshot()); store=JSON.parse(redoStack.pop()); sel=-1;cur=[]; render(); }

  function planKey(){ return planSel.value; }
  function zones(){ return store[planKey()] || (store[planKey()]=[]); }

  function loadPlan(){
    cur=[]; sel=-1;
    img.onload=function(){ natW=img.naturalWidth; natH=img.naturalHeight; applyZoom(); render(); };
    img.src=planKey();
  }
  function applyZoom(){
    img.style.width=(natW*zoom)+'px'; img.style.height=(natH*zoom)+'px';
    svg.setAttribute('width', natW*zoom); svg.setAttribute('height', natH*zoom);
    svg.setAttribute('viewBox','0 0 '+natW+' '+natH);
    document.getElementById('zval').textContent=Math.round(zoom*100)+'%';
  }
  function pct2xy(p){ return [p[0]/100*natW, p[1]/100*natH]; }
  function evt2pct(e){
    var r=img.getBoundingClientRect();
    var x=(e.clientX-r.left)/r.width*100, y=(e.clientY-r.top)/r.height*100;
    return [Math.max(0,Math.min(100,+x.toFixed(2))), Math.max(0,Math.min(100,+y.toFixed(2)))];
  }

  function setZoom(z, cx, cy){
    // cx,cy — точка экрана, к которой «прилипает» зум (по умолчанию центр стейджа)
    var sr=stage.getBoundingClientRect();
    if(cx==null){ cx=sr.left+sr.width/2; cy=sr.top+sr.height/2; }
    var r=img.getBoundingClientRect();
    var fx=(cx-r.left)/r.width, fy=(cy-r.top)/r.height;   // доля внутри картинки
    var old=zoom; zoom=Math.max(.15,Math.min(6,z));
    applyZoom();
    // после ресайза вернуть точку fx,fy под тот же экранный курсор
    var nr=img.getBoundingClientRect();
    stage.scrollLeft += (nr.left + fx*nr.width) - cx;
    stage.scrollTop  += (nr.top  + fy*nr.height) - cy;
  }

  // зум колесом к курсору
  stage.addEventListener('wheel', function(e){
    e.preventDefault();
    setZoom(zoom * (e.deltaY<0 ? 1.15 : 1/1.15), e.clientX, e.clientY);
  }, {passive:false});

  // панорамирование: средняя кнопка ИЛИ пробел+drag
  var spaceDown=false;
  window.addEventListener('keydown', function(e){ if(e.code==='Space'){ spaceDown=true; stage.style.cursor='grab'; } });
  window.addEventListener('keyup',   function(e){ if(e.code==='Space'){ spaceDown=false; stage.style.cursor=''; } });
  stage.addEventListener('mousedown', function(e){
    if(e.button!==1 && !(e.button===0 && spaceDown)) return;
    e.preventDefault();
    var sx=e.clientX, sy=e.clientY, l0=stage.scrollLeft, t0=stage.scrollTop;
    stage.style.cursor='grabbing';
    function mv(ev){ stage.scrollLeft=l0-(ev.clientX-sx); stage.scrollTop=t0-(ev.clientY-sy); }
    function up(){ document.removeEventListener('mousemove',mv); document.removeEventListener('mouseup',up); stage.style.cursor=spaceDown?'grab':''; }
    document.addEventListener('mousemove',mv); document.addEventListener('mouseup',up);
  });

  function render(){
    var s='';
    zones().forEach(function(z,i){
      var isMarker = z.pts.length===1;
      var cx, cy;
      if(isMarker){
        var m=pct2xy(z.pts[0]); cx=m[0]; cy=m[1];
        var rr=(i===sel)?11:8;
        s+='<circle class="mk'+(i===sel?' sel':'')+'" data-i="'+i+'" cx="'+cx+'" cy="'+cy+'" r="'+rr+'" fill="'+(i===sel?'rgba(224,163,18,.85)':'rgba(63,157,88,.85)')+'" stroke="#fff" stroke-width="2"></circle>';
      } else {
        var pts=z.pts.map(function(p){var xy=pct2xy(p);return xy[0]+','+xy[1];}).join(' ');
        var c=(i===sel)?'done sel':'done';
        s+='<polygon class="'+c+'" data-i="'+i+'" points="'+pts+'"></polygon>';
        cx=z.pts.reduce(function(a,p){return a+p[0];},0)/z.pts.length/100*natW;
        cy=z.pts.reduce(function(a,p){return a+p[1];},0)/z.pts.length/100*natH;
        if(i===sel){
          // серединные точки-«добавить угол» (рисуем ПОД вершинами)
          z.pts.forEach(function(p,j){
            var q=z.pts[(j+1)%z.pts.length];
            var xy=pct2xy([(p[0]+q[0])/2,(p[1]+q[1])/2]);
            s+='<circle class="vadd" data-i="'+i+'" data-j="'+j+'" cx="'+xy[0]+'" cy="'+xy[1]+'" r="5"></circle>';
          });
          z.pts.forEach(function(p,j){var xy=pct2xy(p);s+='<circle class="vtx" data-i="'+i+'" data-j="'+j+'" cx="'+xy[0]+'" cy="'+xy[1]+'" r="6"></circle>';});
        }
      }
      s+='<text class="lbl" x="'+cx+'" y="'+(cy-14)+'">'+ (z.label||('#'+i)) +'</text>';
    });
    if(cur.length){
      var pts=cur.map(function(p){var xy=pct2xy(p);return xy[0]+','+xy[1];}).join(' ');
      s+='<polyline points="'+pts+'" fill="rgba(31,159,214,.12)" stroke="#1f9fd6" stroke-width="2" stroke-dasharray="5 4"></polyline>';
      cur.forEach(function(p){var xy=pct2xy(p);s+='<circle class="vtx" cx="'+xy[0]+'" cy="'+xy[1]+'" r="5"></circle>';});
    }
    svg.innerHTML=s;
    bindSvg(); renderList(); syncJson();
  }
  function renderList(){
    zonesEl.innerHTML=zones().map(function(z,i){
      return '<div class="zrow'+(i===sel?' sel':'')+'"><span data-pick="'+i+'">'+(z.label||('#'+i))+' · '+z.pts.length+' т.</span>'+
        '<button data-ren="'+i+'">✎</button><button data-del="'+i+'">✕</button></div>';
    }).join('');
    zonesEl.querySelectorAll('[data-pick]').forEach(function(el){el.onclick=function(){sel=+el.getAttribute('data-pick');labelInp.value=zones()[sel].label||'';render();};});
    zonesEl.querySelectorAll('[data-del]').forEach(function(el){el.onclick=function(){pushHist();zones().splice(+el.getAttribute('data-del'),1);sel=-1;render();};});
    zonesEl.querySelectorAll('[data-ren]').forEach(function(el){el.onclick=function(){var i=+el.getAttribute('data-ren');var v=prompt('Метка:',zones()[i].label||'');if(v!=null){zones()[i].label=v;render();}};});
  }
  function bindSvg(){
    // маркер-точка: клик = выбрать
    svg.querySelectorAll('circle.mk').forEach(function(pg){pg.onclick=function(e){e.stopPropagation();sel=+pg.getAttribute('data-i');labelInp.value=zones()[sel].label||'';render();};});
    // полигон: клик = выбрать, а с зажатием и сдвигом = тащить квартиру ЦЕЛИКОМ
    svg.querySelectorAll('polygon.done').forEach(function(pg){
      pg.onmousedown=function(e){
        if(e.button!==0 || spaceDown || drawMode) return;
        e.preventDefault(); e.stopPropagation();
        var i=+pg.getAttribute('data-i');
        var start=evt2pct(e), moved=false;
        var orig=zones()[i].pts.map(function(p){return p.slice();});
        function mv(ev){
          var c=evt2pct(ev), dx=c[0]-start[0], dy=c[1]-start[1];
          if(!moved){ if(Math.abs(dx)<0.4 && Math.abs(dy)<0.4) return; pushHist(); moved=true; }
          zones()[i].pts=orig.map(function(p){
            return [ +Math.max(0,Math.min(100,p[0]+dx)).toFixed(2), +Math.max(0,Math.min(100,p[1]+dy)).toFixed(2) ];
          });
          render();
        }
        function up(){
          document.removeEventListener('mousemove',mv); document.removeEventListener('mouseup',up);
          if(!moved){ sel=i; labelInp.value=zones()[i].label||''; render(); }   // клик без сдвига = просто выбор
        }
        sel=i; labelInp.value=zones()[i].label||''; render();   // сразу подсветить выбранную
        document.addEventListener('mousemove',mv); document.addEventListener('mouseup',up);
      };
    });
    // drag маркера
    svg.querySelectorAll('circle.mk').forEach(function(c){
      c.onmousedown=function(e){
        if(e.button!==0) return;
        e.preventDefault();e.stopPropagation();
        var i=+c.getAttribute('data-i'), moved=false;
        function mv(ev){ if(!moved){ pushHist(); moved=true; } zones()[i].pts[0]=evt2pct(ev);render();}
        function up(){document.removeEventListener('mousemove',mv);document.removeEventListener('mouseup',up);}
        document.addEventListener('mousemove',mv);document.addEventListener('mouseup',up);
      };
    });
    // drag вершин выбранной зоны + правый клик = удалить угол
    svg.querySelectorAll('circle.vtx[data-j]').forEach(function(c){
      c.onmousedown=function(e){
        if(e.button!==0) return;
        e.preventDefault();e.stopPropagation();
        var i=+c.getAttribute('data-i'), j=+c.getAttribute('data-j'), moved=false;
        function mv(ev){ if(!moved){ pushHist(); moved=true; } zones()[i].pts[j]=evt2pct(ev);render();}
        function up(){document.removeEventListener('mousemove',mv);document.removeEventListener('mouseup',up);}
        document.addEventListener('mousemove',mv);document.addEventListener('mouseup',up);
      };
      c.oncontextmenu=function(e){
        e.preventDefault();e.stopPropagation();
        var i=+c.getAttribute('data-i'), j=+c.getAttribute('data-j');
        if(zones()[i].pts.length>3){ pushHist(); zones()[i].pts.splice(j,1); render(); }
      };
    });
    // клик по серединной точке = вставить новый угол и сразу тащить его
    svg.querySelectorAll('circle.vadd').forEach(function(c){
      c.onmousedown=function(e){
        if(e.button!==0) return;
        e.preventDefault();e.stopPropagation();
        pushHist();
        var i=+c.getAttribute('data-i'), j=+c.getAttribute('data-j'), pts=zones()[i].pts;
        var p=pts[j], q=pts[(j+1)%pts.length];
        pts.splice(j+1,0,[+((p[0]+q[0])/2).toFixed(2),+((p[1]+q[1])/2).toFixed(2)]);
        var nj=j+1; render();
        function mv(ev){zones()[i].pts[nj]=evt2pct(ev);render();}
        function up(){document.removeEventListener('mousemove',mv);document.removeEventListener('mouseup',up);}
        document.addEventListener('mousemove',mv);document.addEventListener('mouseup',up);
      };
    });
  }

  function setDraw(on){
    drawMode=on; cur=[];
    var b=document.getElementById('draw'); if(b) b.classList.toggle('on', on);
    stage.classList.toggle('drawing', on);
    render();
  }
  function closePoly(){
    if(cur.length>=3){ pushHist(); zones().push({label:labelInp.value.trim(), pts:cur.slice()}); sel=zones().length-1; }
    cur=[]; setDraw(false);
  }

  function addMarker(e){ pushHist(); zones().push({label:labelInp.value.trim(), pts:[evt2pct(e)]}); sel=zones().length-1; setDraw(false); }
  // клики по плану РАБОТАЮТ ТОЛЬКО в режиме рисования (иначе не плодим лишние точки)
  img.addEventListener('click',function(e){ if(spaceDown) return; if(!drawMode){ return; } if(e.shiftKey){ addMarker(e); return; } cur.push(evt2pct(e)); render(); });
  svg.addEventListener('click',function(e){ if(e.target===svg && drawMode && !spaceDown){ if(e.shiftKey){ addMarker(e); return; } cur.push(evt2pct(e)); render(); }});
  // двойной клик по ПУСТОМУ месту плана: не рисуем → начать новый контур; рисуем → замкнуть
  function onDbl(e){
    if(spaceDown) return;
    if(!drawMode){
      if(e.target!==img && e.target!==svg) return;   // 2× по существующей зоне/точке — игнор
      e.preventDefault(); setDraw(true); cur.push(evt2pct(e)); render();
    } else {
      e.preventDefault(); if(cur.length){ cur.pop(); } closePoly();
    }
  }
  img.addEventListener('dblclick', onDbl);
  svg.addEventListener('dblclick', onDbl);
  document.addEventListener('keydown',function(e){
    var ctrl=e.ctrlKey||e.metaKey;
    if(ctrl && (e.key==='z'||e.key==='Z')){ e.preventDefault(); undo(); return; }
    if(ctrl && (e.key==='y'||e.key==='Y')){ e.preventDefault(); redo(); return; }
    if(e.target===labelInp) return;
    if(e.key==='Enter'){ closePoly(); }
    else if(e.key==='Escape'){ setDraw(false); }
    else if(e.key==='Backspace' && cur.length){ e.preventDefault(); cur.pop(); render(); }
  });
  labelInp.addEventListener('input',function(){ if(sel>=0){ zones()[sel].label=labelInp.value; renderList(); syncJson(); }});

  document.getElementById('zin').onclick=function(){setZoom(zoom*1.25);};
  document.getElementById('zout').onclick=function(){setZoom(zoom/1.25);};
  document.getElementById('undo').onclick=function(){ undo(); };
  planSel.onchange=function(){ setDraw(false); loadPlan(); };

  // --- АВТОСОХРАНЕНИЕ: правки уходят на сервер сами (debounce), без кнопок ---
  var started=false, saveTimer=null, statusEl=document.getElementById('savestatus');
  function setStatus(txt,cls){ if(statusEl){ statusEl.textContent=txt; statusEl.className='savestatus '+cls; } }
  function doSave(){
    setStatus('сохраняю…','is-saving');
    fetch('save_zones.php',{method:'POST',body:JSON.stringify(store)})
      .then(function(r){ if(!r.ok) throw 0; return r.text(); })
      .then(function(){ setStatus('всё сохранено ✓','is-ok'); })
      .catch(function(){ setStatus('ошибка — повторяю…','is-err'); clearTimeout(saveTimer); saveTimer=setTimeout(doSave,3000); });
  }
  // syncJson теперь = запланировать автосохранение (имя оставлено — вызывается из render)
  function syncJson(){
    if(!started) return;
    if(saveTimer) clearTimeout(saveTimer);
    setStatus('есть несохранённые правки…','is-dirty');
    saveTimer=setTimeout(doSave,900);
  }

  // старт: разметка внедрена сервером (PHP)
  try { store = <?php echo (@file_get_contents(__DIR__ . '/floor_zones.json') ?: '{}'); ?> || {}; }
  catch (e) { store = {}; }
  loadPlan();
  setTimeout(function(){ started=true; }, 600); // после первичной отрисовки — включаем автосейв
})();
</script>
</body>
</html>
