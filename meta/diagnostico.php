<?php
/*
 * Diagnóstico del kit: meta/diagnostico.php?clave=LA_CLAVE_DE_CONFIG
 *
 * Revisa la configuración, manda un evento de prueba a Meta y muestra los últimos eventos
 * del log. Se apaga dejando clave_diagnostico vacía en config.php.
 */
require_once __DIR__ . '/meta.php';
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

try { $c = Meta::cfg(); } catch (Throwable $e) { http_response_code(500); exit(htmlspecialchars($e->getMessage())); }

if ($c['clave_diagnostico'] === '' || !hash_equals((string) $c['clave_diagnostico'], (string) ($_GET['clave'] ?? ''))) {
    http_response_code(404);
    exit;
}

$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

// ── Chequeos ──────────────────────────────────────────────────────────────────────
$chequeos = [
    ['pixel_id', preg_match('/^\d{10,20}$/', $c['pixel_id']) === 1, $c['pixel_id'] ?: 'vacío', 'Events Manager → conjunto de datos → Configuración'],
    ['token', strlen($c['token']) > 50, $c['token'] ? substr($c['token'], 0, 6) . '…' . strlen($c['token']) . ' caracteres' : 'vacío', 'API de conversiones → Generar token de acceso'],
    ['modo', true, $c['modo'] === 'enviar' ? 'enviar (de verdad)' : 'simular (no manda nada)', "cámbialo a 'enviar' cuando el log se vea bien"],
    ['test_event_code', true, $c['test_event_code'] ?: 'vacío (producción)', $c['test_event_code'] ? '⚠️ vaciarlo al pasar a producción' : ''],
    ['curl / allow_url_fopen', function_exists('curl_init') || ini_get('allow_url_fopen'), function_exists('curl_init') ? 'curl' : (ini_get('allow_url_fopen') ? 'allow_url_fopen' : 'ninguno'), 'pídele al hosting que active curl'],
    ['HTTPS', (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || in_array($_SERVER['HTTP_HOST'] ?? '', ['localhost', '127.0.0.1'], true) || (strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost:') === 0), $_SERVER['HTTP_HOST'] ?? '', 'el sitio debe ir por https'],
    ['dominio permitido', in_array(strtolower(explode(':', $_SERVER['HTTP_HOST'] ?? '')[0]), array_map('strtolower', $c['dominios']), true), implode(', ', $c['dominios']), 'agrega este dominio a dominios en config.php'],
    ['logs/ escribible', is_writable(__DIR__ . '/logs'), __DIR__ . '/logs', 'permisos 755 o 775 en meta/logs'],
    ['PHP', PHP_VERSION_ID >= 70400, PHP_VERSION, 'se necesita PHP 7.4 o más'],
];

// ── Evento de prueba ──────────────────────────────────────────────────────────────
$prueba = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $evento = in_array($_POST['evento'] ?? '', ['PageView', 'Lead', 'Contact', 'ViewContent'], true) ? $_POST['evento'] : 'PageView';
    $usuario = array_filter(['email' => $_POST['email'] ?? '', 'telefono' => $_POST['telefono'] ?? '']);
    $prueba = Meta::track($evento, $usuario, ['content_name' => 'Prueba desde diagnostico.php']);
}

// ── Log ───────────────────────────────────────────────────────────────────────────
$lineas = [];
if (is_file(Meta::archivoLog())) {
    $todas = array_slice(file(Meta::archivoLog(), FILE_IGNORE_NEW_LINES), 1);
    $lineas = array_reverse(array_slice($todas, -25));
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Diagnóstico Kit Meta</title>
<style>
  :root { --bg:#f6f7f9; --card:#fff; --tx:#1c1e21; --mut:#65676b; --ok:#1a7f37; --mal:#cf222e; --az:#1877f2; --bd:#dadde1; }
  @media (prefers-color-scheme: dark) { :root { --bg:#18191a; --card:#242526; --tx:#e4e6eb; --mut:#b0b3b8; --ok:#3fb950; --mal:#f85149; --bd:#3a3b3c; } }
  body { font: 15px/1.5 system-ui, sans-serif; background: var(--bg); color: var(--tx); margin: 0; padding: 24px 16px; }
  main { max-width: 860px; margin: auto; }
  h1 { font-size: 22px; margin: 0 0 4px } h2 { font-size: 16px; margin: 28px 0 10px }
  .card { background: var(--card); border: 1px solid var(--bd); border-radius: 10px; padding: 16px; overflow-x: auto }
  table { width: 100%; border-collapse: collapse } td { padding: 7px 8px; border-top: 1px solid var(--bd); vertical-align: top }
  tr:first-child td { border-top: 0 } .ok { color: var(--ok) } .mal { color: var(--mal) } .mut { color: var(--mut); font-size: 13px }
  input, select, button { font: inherit; padding: 7px 10px; border: 1px solid var(--bd); border-radius: 6px; background: var(--card); color: var(--tx) }
  button { background: var(--az); color: #fff; border: 0; cursor: pointer }
  form { display: flex; gap: 8px; flex-wrap: wrap } pre { white-space: pre-wrap; word-break: break-all; font-size: 12.5px; margin: 0 }
</style>
</head>
<body><main>
<h1>Diagnóstico del Kit Meta</h1>
<div class="mut">Píxel <?= $h($c['pixel_id'] ?: '—') ?> · Graph API <?= $h($c['version_api']) ?></div>

<h2>1. Configuración</h2>
<div class="card"><table>
<?php foreach ($chequeos as [$nombre, $bien, $valor, $ayuda]): ?>
  <tr><td class="<?= $bien ? 'ok' : 'mal' ?>"><?= $bien ? '✓' : '✗' ?></td><td><strong><?= $h($nombre) ?></strong><br><span class="mut"><?= $h($valor) ?></span></td><td class="mut"><?= $bien && !(strpos($ayuda, '⚠️') === 0) ? '' : $h($ayuda) ?></td></tr>
<?php endforeach; ?>
</table></div>

<h2>2. Mandar un evento de prueba por servidor</h2>
<div class="card">
  <form method="post">
    <select name="evento"><option>PageView</option><option>Lead</option><option>Contact</option><option>ViewContent</option></select>
    <input name="email" type="email" placeholder="tu correo (opcional, mejora el cruce)">
    <input name="telefono" placeholder="tu celular (opcional)">
    <button>Enviar</button>
  </form>
  <p class="mut">Con <code>test_event_code</code> puesto, míralo aparecer en Events Manager → Probar eventos. Usa TUS datos: un correo que no tenga cuenta de Meta se descarta.</p>
  <?php if ($prueba): ?>
    <p class="<?= $prueba['ok'] ? 'ok' : 'mal' ?>"><strong><?= $prueba['ok'] ? '✓ Meta lo recibió' : '✗ No salió' ?></strong> · modo <?= $h($prueba['modo']) ?> · event_id <?= $h($prueba['event_id']) ?></p>
    <pre><?= $h(json_encode($prueba['respuesta'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
  <?php endif; ?>
</div>

<h2>3. Últimos eventos (<?= $h(basename(Meta::archivoLog())) ?>)</h2>
<div class="card">
<?php if (!$lineas): ?><p class="mut">Todavía no hay eventos este mes.</p><?php endif; ?>
<table>
<?php foreach ($lineas as $l): $j = json_decode($l, true); if (!$j) continue; ?>
  <tr>
    <td class="<?= $j['ok'] ? 'ok' : 'mal' ?>"><?= $j['ok'] ? '✓' : '✗' ?></td>
    <td><strong><?= $h($j['evento']) ?></strong> <span class="mut"><?= $h($j['modo']) ?> · <?= $h(substr($j['t'], 11, 8)) ?></span><br>
        <span class="mut"><?= $h($j['url']) ?></span><br>
        <span class="mut">user_data: <?= $h(implode(', ', $j['user_data'] ?? [])) ?><?= !empty($j['custom_data']) ? ' · custom_data: ' . $h(json_encode($j['custom_data'], JSON_UNESCAPED_UNICODE)) : '' ?></span>
        <?php if (!$j['ok']): ?><pre class="mal"><?= $h(json_encode($j['respuesta'], JSON_UNESCAPED_UNICODE)) ?></pre><?php endif; ?>
    </td>
  </tr>
<?php endforeach; ?>
</table>
</div>
</main></body></html>
