<?php
// Вкладка «Планы этажей» — редактор контуров квартир (floors.php) во встроенном фрейме.
// floors.php — самостоятельная страница со своим интерфейсом; здесь показываем её
// внутри панели, чтобы был единый вход (авторизация общая — Basic для домена).
?>
<div class="floors-embed">
  <p class="floors-hint">Разметка контуров квартир на планах этажей. Правки сохраняются на диск и сразу
    попадают на витрину (эндпоинт <code>widget.php</code>). Полноэкранный редактор —
    <a href="panel/floors.php" target="_blank" rel="noopener">открыть в новой вкладке ↗</a>.</p>
  <iframe src="panel/floors.php" title="Редактор планов этажей"></iframe>
</div>
<style>
  .wrap.wrap--full { max-width: none; padding: 14px 20px 24px; }
  .floors-embed { display: flex; flex-direction: column; gap: 8px; }
  .floors-hint { margin: 0 0 4px; font-size: 13px; color: #8295a6; }
  .floors-hint code { color: #48607a; }
  .floors-embed iframe { width: 100%; height: calc(100vh - 165px); min-height: 560px;
    border: 1px solid #24303c; border-radius: 10px; background: #12161c; }
</style>
