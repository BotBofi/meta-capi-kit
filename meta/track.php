<?php
/*
 * Endpoint del navegador → Conversions API.
 *
 * meta.js le manda aquí cada evento que dispara el píxel (con el mismo event_id), y este
 * archivo lo reenvía a Meta por servidor. El token nunca sale del servidor.
 *
 * No hay que tocarlo. Defensas: sólo POST, sólo desde los dominios de config.php, sólo
 * eventos permitidos, y un tope de eventos por minuto por IP.
 */
require_once __DIR__ . '/meta.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function responder(int $codigo, array $cuerpo): void
{
    http_response_code($codigo);
    echo json_encode($cuerpo, JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') responder(405, ['ok' => false, 'error' => 'sólo POST']);

$cfg = Meta::cfg();

// ── Origen: sólo tus dominios ──────────────────────────────────────────────────────
$origen = $_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['HTTP_REFERER'] ?? '');
$host = strtolower(parse_url($origen, PHP_URL_HOST) ?: '');
if ($host === '' || !in_array($host, array_map('strtolower', $cfg['dominios']), true)) {
    responder(403, ['ok' => false, 'error' => "origen «{$host}» no está en dominios de config.php"]);
}

// ── Tope: 60 eventos por minuto por IP (un humano no llega; un bot sí) ─────────────
$ip = Meta::ip();
$marca = sys_get_temp_dir() . '/metakit_' . md5($ip . __DIR__) . '_' . date('YmdHi');
$n = (int) @file_get_contents($marca);
if ($n >= 60) responder(429, ['ok' => false, 'error' => 'demasiados eventos']);
@file_put_contents($marca, (string) ($n + 1), LOCK_EX);

// ── Cuerpo ─────────────────────────────────────────────────────────────────────────
$e = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($e) || empty($e['evento']) || !is_string($e['evento'])) {
    responder(400, ['ok' => false, 'error' => 'falta evento']);
}
$evento = $e['evento'];
if (!Meta::permitido($evento)) {
    responder(422, ['ok' => false, 'error' => "evento «{$evento}» no permitido (agrégalo a eventos_propios)"]);
}
$eventId = preg_replace('/[^\w\.\-]/', '', (string) ($e['event_id'] ?? '')) ?: null;

// La URL de la página donde pasó (no la de track.php), y sólo si es de tus dominios.
$url = (string) ($e['url'] ?? '');
$hostUrl = strtolower(parse_url($url, PHP_URL_HOST) ?: '');
if (!in_array($hostUrl, array_map('strtolower', $cfg['dominios']), true)) $url = $origen;

$r = Meta::track(
    $evento,
    is_array($e['usuario'] ?? null) ? $e['usuario'] : [],
    is_array($e['datos'] ?? null) ? $e['datos'] : [],
    $eventId,
    [
        'event_source_url' => $url,
        'fbp' => is_string($e['fbp'] ?? null) ? $e['fbp'] : null,
        'fbc' => is_string($e['fbc'] ?? null) ? $e['fbc'] : null,
    ]
);

// Al navegador sólo le decimos si salió; el detalle de Meta queda en logs/.
$salida = ['ok' => $r['ok'], 'event_id' => $r['event_id'], 'modo' => $r['modo']];
if (!$r['ok'] || $cfg['test_event_code'] !== '' || $cfg['modo'] === 'simular') $salida['detalle'] = $r['respuesta'];
responder($r['ok'] ? 200 : 502, $salida);
