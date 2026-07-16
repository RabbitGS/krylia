<?php
// Общие функции панели. Подключается из _boot.php.

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function dt($ts) { return $ts ? date('d.m H:i', $ts) : '—'; }
function clip($s, $n) { $s = (string)$s; return mb_strlen($s) > $n ? mb_substr($s, 0, $n) . '…' : $s; }

function device($ua) {
  $ua = (string)$ua;
  if ($ua === '') return '—';
  if (preg_match('/iPad|Tablet/i', $ua)) $t = 'Планшет';
  elseif (preg_match('/Mobi|Android|iPhone/i', $ua)) $t = 'Телефон';
  else $t = 'Ноутбук / ПК';
  $b = '';
  if (preg_match('/YaBrowser/i', $ua)) $b = 'Яндекс';
  elseif (preg_match('/Edg/i', $ua)) $b = 'Edge';
  elseif (preg_match('/OPR|Opera/i', $ua)) $b = 'Opera';
  elseif (preg_match('/Chrome/i', $ua)) $b = 'Chrome';
  elseif (preg_match('/Firefox/i', $ua)) $b = 'Firefox';
  elseif (preg_match('/Safari/i', $ua)) $b = 'Safari';
  return trim($t . ($b ? " · $b" : ''));
}

function howCame($r) {
  $utm = trim(((string)($r['utm_source'] ?? '')) . ' ' . ((string)($r['utm_medium'] ?? '')) . ' ' . ((string)($r['utm_campaign'] ?? '')));
  if ($utm !== '') return 'Реклама: ' . $utm;
  $ref = (string)($r['referrer'] ?? '');
  if ($ref === '') return 'Прямой переход / не определён';
  $host = parse_url($ref, PHP_URL_HOST) ?: $ref;
  if (preg_match('/yandex|google|bing|mail\.ru|duckduck|rambler/i', $host)) return 'Поиск: ' . $host;
  return 'Переход с: ' . $host;
}

// короткая запись комнатности
function roomsShort($n) {
  $n = (int)$n;
  return $n <= 0 ? 'Студия' : $n . '-к';
}

// цена «1 234 567 ₽»
function money($v) {
  return number_format((int)$v, 0, '.', ' ') . ' ₽';
}
