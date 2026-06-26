<?php
// Приёмник заявок с сайта Крылья → amoCRM (API v4).
// PHP-порт бывшей Netlify-функции netlify/functions/lead.js.
//
// Доступы НЕ в коде — читаются из файла lead_secret.php, который лежит НА УРОВЕНЬ ВЫШЕ
// веб-корня (чтобы его нельзя было скачать по HTTP). Формат lead_secret.php:
//   <?php return [
//     'subdomain'   => 'xxxxx',     // то, что до .amocrm.ru
//     'token'       => 'долгий_токен',
//     'pipeline_id' => null,        // необязательно
//     'status_id'   => null,        // необязательно
//   ];
//
// Пока lead_secret.php нет — функция вернёт «не настроено» (это норма до интеграции amoCRM).

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok' => false, 'error' => 'Method Not Allowed']);
  exit;
}

// доступы amoCRM из lead_secret.php. Ищем вверх от docroot — файл лежит ВНЕ веб-корня.
$cfg = [];
$dir = $_SERVER['DOCUMENT_ROOT'] ?: __DIR__;
for ($i = 0; $i < 4; $i++) {
  $p = $dir . '/lead_secret.php';
  if (is_file($p)) { $cfg = include $p; break; }
  $dir = dirname($dir);
}
$SUB   = $cfg['subdomain'] ?? '';
$TOKEN = $cfg['token'] ?? '';
if (!$SUB || !$TOKEN) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'amoCRM не настроен (нет доступов)']);
  exit;
}

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) $data = [];

$name   = mb_substr(trim((string)($data['name']   ?? '')), 0, 200);
$phone  = mb_substr(trim((string)($data['phone']  ?? '')), 0, 50);
$apt    = mb_substr(trim((string)($data['apt']    ?? '')), 0, 200);
$finish = mb_substr(trim((string)($data['finish'] ?? '')), 0, 100);
$source = mb_substr(trim((string)($data['source'] ?? '')), 0, 120);
$formId = mb_substr(trim((string)($data['form']   ?? '')), 0, 50);
$page   = mb_substr(trim((string)($data['page']   ?? '')), 0, 300);

// --- ДИАГНОСТИКА: лог каждого POST в файл вне веб-корня (не блокирует, только пишет) ---
function lead_log($decision, $data) {
  $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? ($_SERVER['REMOTE_ADDR'] ?? '');
  $rec = [
    'ts'       => date('c'),
    'ip'       => $ip,
    'ua'       => $_SERVER['HTTP_USER_AGENT'] ?? '',
    'decision' => $decision,                         // honeypot | timetrap | bad_phone | accepted
    'name'     => (string)($data['name']    ?? ''),
    'phone'    => (string)($data['phone']   ?? ''),
    'company'  => (string)($data['company'] ?? ''),  // honeypot-поле (у людей пусто)
    't'        => $data['t'] ?? '',                  // time-trap, мс с загрузки формы
    'form'     => (string)($data['form']   ?? ''),
    'page'     => (string)($data['page']   ?? ''),
  ];
  $f = (($_SERVER['DOCUMENT_ROOT'] ?? __DIR__) . '/../lead_log.jsonl');
  @file_put_contents($f, json_encode($rec, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
}

// --- Антибот ---
// 1) honeypot: поле "company" скрыто от людей; если заполнено — это бот.
// 2) time-trap: форма отправлена быстрее MIN_FILL_MS после загрузки — это бот.
// Боту отвечаем «успех» (200 ok), чтобы он не подбирал обход, но заявку НЕ создаём.
$honeypot = trim((string)($data['company'] ?? ''));
$elapsed  = isset($data['t']) && is_numeric($data['t']) ? (int)$data['t'] : null;
$MIN_FILL_MS = 2500;
if ($honeypot !== '' || ($elapsed !== null && $elapsed < $MIN_FILL_MS)) {
  lead_log($honeypot !== '' ? 'honeypot' : 'timetrap', $data);
  // тихо отбрасываем (боту отвечаем «успех», заявку НЕ создаём)
  http_response_code(200);
  echo json_encode(['ok' => true]);
  exit;
}

// простая защита: телефон должен содержать минимум 10 цифр
if (strlen(preg_replace('/\D/', '', $phone)) < 10) {
  lead_log('bad_phone', $data);
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'Некорректный телефон']);
  exit;
}

lead_log('accepted', $data);

$base = "https://{$SUB}.amocrm.ru";
$headers = ["Authorization: Bearer {$TOKEN}", "Content-Type: application/json"];

// POST к amoCRM, возвращает [http_code, тело_ответа]
function amo_post($url, $headers, $payload) {
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => $headers,
    CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 20,
  ]);
  $body = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  return [$code, $body];
}

// 1) сделка + контакт одним запросом (/leads/complex)
$leadName = 'Заявка с сайта Крылья';
if ($apt)         $leadName .= ' — ' . explode(',', $apt)[0];
elseif ($finish)  $leadName .= ' — отделка «' . $finish . '»';
elseif ($source)  $leadName .= ' — ' . $source;
$lead = [
  'name' => $leadName,
  '_embedded' => [
    'tags' => [['name' => 'Сайт Крылья']],
    'contacts' => [[
      'name' => $name ?: 'Клиент с сайта',
      'custom_fields_values' => [
        ['field_code' => 'PHONE', 'values' => [['value' => $phone, 'enum_code' => 'WORK']]],
      ],
    ]],
  ],
];
if (!empty($cfg['pipeline_id'])) $lead['pipeline_id'] = (int)$cfg['pipeline_id'];
if (!empty($cfg['status_id']))   $lead['status_id']   = (int)$cfg['status_id'];

list($code, $body) = amo_post("{$base}/api/v4/leads/complex", $headers, [$lead]);
if ($code < 200 || $code >= 300) {
  http_response_code(502);
  echo json_encode(['ok' => false, 'error' => 'amoCRM отклонил запрос', 'status' => $code, 'detail' => mb_substr((string)$body, 0, 500)]);
  exit;
}

// 2) примечание к сделке с деталями (не критично)
$leadId = null;
$parsed = json_decode((string)$body, true);
if (isset($parsed[0]['id'])) $leadId = $parsed[0]['id'];
if ($leadId) {
  $note = "Заявка с сайта\nИмя: {$name}\nТелефон: {$phone}"
    . ($apt    ? "\nКвартира: {$apt}"             : '')
    . ($finish ? "\nФормат отделки: {$finish}"    : '')
    . ($source ? "\nРаздел сайта: {$source}"      : '')
    . ($formId ? "\nФорма: {$formId}"             : '')
    . ($page   ? "\nСтраница: {$page}"            : '');
  amo_post("{$base}/api/v4/leads/{$leadId}/notes", $headers, [['note_type' => 'common', 'params' => ['text' => $note]]]);
}

echo json_encode(['ok' => true]);
