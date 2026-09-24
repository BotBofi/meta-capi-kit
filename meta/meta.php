<?php
/*
 * Kit Meta — Pixel + Conversions API (CAPI) en un solo include, para hosting PHP común.
 *
 * Uso mínimo, en cada página (o en el header común):
 *
 *     <?php require_once __DIR__ . '/meta/meta.php'; ?>
 *     <head>
 *         ...
 *         <?= Meta::head() ?>
 *     </head>
 *
 * Con eso ya hay PageView por navegador y por servidor, deduplicados, y quedan activos
 * los atributos data-meta-evento del HTML (ver README).
 *
 * Desde PHP (por ejemplo, al procesar un formulario):
 *
 *     Meta::track('Lead',
 *         ['email' => $_POST['email'], 'telefono' => $_POST['telefono'], 'nombre' => $_POST['nombre']],
 *         ['content_name' => 'Formulario contacto', 'content_category' => 'depto-2d'],
 *         $_POST['meta_event_id'] ?? null);
 *
 * Cómo se deduplica: cada evento lleva un event_id. El navegador (fbq) y el servidor (CAPI)
 * mandan el MISMO event_id y el MISMO nombre, y Meta cuenta uno solo. El navegador
 * aporta la cookie del píxel; el servidor aporta lo que los bloqueadores no tocan.
 *
 * Requiere PHP 7.4+ (con curl, o allow_url_fopen como respaldo).
 */

final class Meta
{
    /** Eventos estándar de Meta: van con fbq('track'). El resto con fbq('trackCustom'). */
    public const ESTANDAR = [
        'AddPaymentInfo', 'AddToCart', 'AddToWishlist', 'CompleteRegistration', 'Contact',
        'CustomizeProduct', 'Donate', 'FindLocation', 'InitiateCheckout', 'Lead', 'PageView',
        'Purchase', 'Schedule', 'Search', 'StartTrial', 'SubmitApplication', 'Subscribe',
        'ViewContent',
    ];

    /** Alias en español → clave de Meta, para que el formulario del sitio no tenga que traducir. */
    private const ALIAS_USUARIO = [
        'email' => 'em', 'correo' => 'em', 'mail' => 'em',
        'telefono' => 'ph', 'phone' => 'ph', 'fono' => 'ph', 'celular' => 'ph', 'whatsapp' => 'ph',
        'nombre' => 'fn', 'first_name' => 'fn',
        'apellido' => 'ln', 'last_name' => 'ln',
        'ciudad' => 'ct', 'comuna' => 'ct', 'city' => 'ct',
        'region' => 'st', 'state' => 'st',
        'codigo_postal' => 'zp', 'zip' => 'zp',
        'pais' => 'country',
        'genero' => 'ge', 'nacimiento' => 'db',
        'id_usuario' => 'external_id', 'rut' => 'external_id',
    ];

    /** Claves que Meta exige hasheadas con SHA-256 (después de normalizar). */
    private const HASHEAR = ['em', 'ph', 'fn', 'ln', 'ct', 'st', 'zp', 'country', 'ge', 'db', 'external_id'];

    private static $cfg = null;

    // ─────────────────────────────────────────────────────────────────────────────
    // Configuración
    // ─────────────────────────────────────────────────────────────────────────────

    public static function cfg(): array
    {
        if (self::$cfg !== null) return self::$cfg;
        $ruta = defined('META_CONFIG') ? META_CONFIG : __DIR__ . '/config.php';
        if (!is_file($ruta)) {
            throw new RuntimeException("Kit Meta: falta {$ruta}. Copia meta/config.example.php como meta/config.php y llénalo.");
        }
        $c = require $ruta;
        $c += [
            'pixel_id' => '', 'token' => '', 'test_event_code' => '', 'modo' => 'simular',
            'dominios' => [], 'moneda' => 'CLP', 'pais' => 'cl', 'codigo_telefono' => '56',
            'eventos_propios' => [], 'auto' => [], 'esperar_consentimiento' => false,
            'detras_de_proxy' => false, 'version_api' => 'v24.0', 'clave_diagnostico' => '', 'log' => true,
        ];
        $c['auto'] += ['pageview' => true, 'contacto' => true, 'scroll' => false];
        $c['pixel_id'] = preg_replace('/\D/', '', (string) $c['pixel_id']);
        return self::$cfg = $c;
    }

    /** ¿Está permitido este nombre de evento? (estándar o declarado en eventos_propios) */
    public static function permitido(string $evento): bool
    {
        $propios = self::cfg()['eventos_propios'];
        if (!empty(self::cfg()['auto']['scroll'])) $propios[] = 'Scroll75';
        return in_array($evento, self::ESTANDAR, true) || in_array($evento, $propios, true);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Lo que va en el <head>
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * El código base del píxel + meta.js. Llamarlo una vez por página, dentro de <head>.
     * $usuario (opcional): datos del visitante si ya los conoces (sesión iniciada) → Advanced Matching.
     */
    public static function head(array $usuario = []): string
    {
        $c = self::cfg();
        self::guardarFbcDesdeUrl();
        if ($c['pixel_id'] === '') {
            return "<!-- Kit Meta: falta pixel_id en meta/config.php -->\n";
        }

        $matching = self::matchingNavegador($usuario);
        $opcionesJs = [
            'pixelId' => $c['pixel_id'],
            'endpoint' => self::urlDelKit() . '/track.php',
            'estandar' => self::ESTANDAR,
            'propios' => array_values($c['eventos_propios']),
            'auto' => $c['auto'],
            'esperarConsentimiento' => (bool) $c['esperar_consentimiento'],
            'debug' => $c['modo'] === 'simular' || $c['test_event_code'] !== '',
        ];
        $pid = json_encode($c['pixel_id']);
        $am = $matching ? ', ' . json_encode($matching, JSON_UNESCAPED_UNICODE) : '';
        $revoke = $c['esperar_consentimiento'] ? "fbq('consent','revoke');" : '';
        $js = json_encode($opcionesJs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $src = htmlspecialchars(self::urlDelKit() . '/meta.js?v=' . @filemtime(__DIR__ . '/meta.js'), ENT_QUOTES);

        // El snippet oficial del píxel, sin el PageView automático: el PageView lo manda meta.js
        // con event_id, para poder deduplicarlo con el del servidor.
        return <<<HTML
<!-- Kit Meta: Pixel + Conversions API -->
<script>
!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?
n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;
n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;
t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,
document,'script','https://connect.facebook.net/en_US/fbevents.js');
{$revoke}fbq('init', {$pid}{$am});
window.META_KIT = {$js};
</script>
<script src="{$src}" defer></script>
<noscript><img height="1" width="1" style="display:none" src="https://www.facebook.com/tr?id={$c['pixel_id']}&ev=PageView&noscript=1"/></noscript>

HTML;
    }

    /**
     * Para páginas renderizadas por PHP después de una acción (ej. «gracias.php»):
     * dispara el MISMO evento en el navegador con el event_id que usaste en Meta::track().
     */
    public static function pixel(string $evento, array $datos, string $eventId): string
    {
        $metodo = in_array($evento, self::ESTANDAR, true) ? 'track' : 'trackCustom';
        $d = json_encode(self::limpiarDatos($datos), JSON_UNESCAPED_UNICODE);
        $e = json_encode($evento);
        $id = json_encode(['eventID' => $eventId]);
        return "<script>window.fbq&&fbq('{$metodo}',{$e},{$d},{$id});</script>\n";
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Evento por servidor (Conversions API)
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * Manda un evento a la Conversions API.
     *
     * @param string      $evento   'Lead', 'Contact', o uno de eventos_propios.
     * @param array       $usuario  datos de la persona, en crudo: email, telefono, nombre, apellido,
     *                              ciudad, id… El kit normaliza y hashea. NUNCA salen en claro.
     * @param array       $datos    custom_data: content_name, content_category, value, currency,
     *                              y tus parámetros para segmentar (ej. 'proyecto' => 'torre-norte').
     * @param string|null $eventId  el mismo que usó el navegador, para deduplicar. Si es null, se crea uno.
     * @param array       $extra    event_source_url / event_time / fbp / fbc / ip / ua si no vienen del request.
     * @return array ['ok' => bool, 'event_id' => string, 'modo' => string, 'respuesta' => mixed]
     */
    public static function track(string $evento, array $usuario = [], array $datos = [], ?string $eventId = null, array $extra = []): array
    {
        $c = self::cfg();
        $eventId = $eventId ?: self::nuevoEventId();

        if (!self::permitido($evento)) {
            $r = ['ok' => false, 'event_id' => $eventId, 'modo' => $c['modo'],
                  'respuesta' => "Evento «{$evento}» no permitido: agrégalo a eventos_propios en config.php"];
            self::log($evento, $eventId, [], $r);
            return $r;
        }

        $ud = self::userData($usuario, $extra);
        $evt = [
            'event_name' => $evento,
            'event_time' => (int) ($extra['event_time'] ?? time()),
            'event_id' => $eventId,
            'action_source' => 'website',
            'event_source_url' => $extra['event_source_url'] ?? self::urlActual(),
            'user_data' => $ud,
        ];
        $cd = self::limpiarDatos($datos);
        if (isset($cd['value']) && !isset($cd['currency'])) $cd['currency'] = $c['moneda'];
        if ($cd) $evt['custom_data'] = $cd;

        $cuerpo = ['data' => [$evt]];
        if ($c['test_event_code'] !== '') $cuerpo['test_event_code'] = $c['test_event_code'];

        if ($c['modo'] !== 'enviar') {
            $r = ['ok' => true, 'event_id' => $eventId, 'modo' => 'simular', 'respuesta' => 'no enviado (modo simular)'];
        } elseif ($c['pixel_id'] === '' || $c['token'] === '') {
            $r = ['ok' => false, 'event_id' => $eventId, 'modo' => 'enviar', 'respuesta' => 'falta pixel_id o token en config.php'];
        } else {
            $r = self::post($cuerpo) + ['event_id' => $eventId, 'modo' => 'enviar'];
        }
        self::log($evento, $eventId, $evt, $r);
        return $r;
    }

    public static function nuevoEventId(): string
    {
        return bin2hex(random_bytes(8)) . '.' . time();
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // user_data: normalizar + hashear
    // ─────────────────────────────────────────────────────────────────────────────

    private static function userData(array $usuario, array $extra): array
    {
        $c = self::cfg();
        $ud = [];
        foreach ($usuario as $k => $v) {
            if ($v === null || $v === '' || is_array($v)) continue;
            $clave = self::ALIAS_USUARIO[strtolower((string) $k)] ?? strtolower((string) $k);
            if (!in_array($clave, self::HASHEAR, true)) continue;
            // «Juan Pérez» en el campo nombre y sin apellido: se parte en dos.
            if ($clave === 'fn' && empty($usuario['apellido']) && empty($usuario['ln']) && strpos(trim($v), ' ') !== false) {
                [$v, $resto] = preg_split('/\s+/', trim($v), 2);
                $n = self::normalizar('ln', $resto);
                if ($n !== '') $ud['ln'] = hash('sha256', $n);
            }
            $n = self::normalizar($clave, (string) $v);
            if ($n !== '') $ud[$clave] = hash('sha256', $n);
        }
        if (!isset($ud['country']) && $c['pais'] !== '') $ud['country'] = hash('sha256', strtolower($c['pais']));

        $ud['client_ip_address'] = $extra['ip'] ?? self::ip();
        $ud['client_user_agent'] = $extra['ua'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? '');
        $fbp = $extra['fbp'] ?? ($_COOKIE['_fbp'] ?? '');
        $fbc = $extra['fbc'] ?? ($_COOKIE['_fbc'] ?? self::fbcDesdeUrl());
        if ($fbp && preg_match('/^fb\.\d\.\d+\.\S+$/', $fbp)) $ud['fbp'] = $fbp;
        if ($fbc && preg_match('/^fb\.\d\.\d+\.\S+$/', $fbc)) $ud['fbc'] = $fbc;
        return array_filter($ud, fn($v) => $v !== '' && $v !== null);
    }

    /** Las reglas de normalización de Meta, antes del SHA-256. */
    private static function normalizar(string $clave, string $v): string
    {
        $v = trim(mb_strtolower($v, 'UTF-8'));
        switch ($clave) {
            case 'em':
                return filter_var($v, FILTER_VALIDATE_EMAIL) ? $v : '';
            case 'ph':
                $d = preg_replace('/\D/', '', $v);
                $d = ltrim($d, '0');
                $cod = (string) self::cfg()['codigo_telefono'];
                // Chile: celular de 9 dígitos que empieza con 9 → se antepone 56. E.164 SIN el «+».
                if ($cod === '56' && strlen($d) === 9 && $d[0] === '9') $d = '56' . $d;
                elseif ($cod !== '' && strlen($d) <= 10 && strpos($d, $cod) !== 0) $d = $cod . $d;
                return strlen($d) >= 8 ? $d : '';
            case 'fn': case 'ln':
                return preg_replace('/[^\p{L}]/u', '', $v);
            case 'ct':
                return preg_replace('/[^\p{L}]/u', '', $v);
            case 'st': case 'zp':
                return preg_replace('/[\s\-]/', '', $v);
            case 'country':
                return substr(preg_replace('/[^a-z]/', '', $v), 0, 2);
            case 'ge':
                return in_array($v[0] ?? '', ['f', 'm'], true) ? $v[0] : '';
            case 'db':
                $t = strtotime($v);
                return $t ? date('Ymd', $t) : '';
            case 'external_id':
                return preg_replace('/[\s\.\-]/', '', $v); // RUT 12.345.678-9 → 123456789
        }
        return $v;
    }

    /** Advanced Matching del píxel: el píxel hashea solo, se le pasa normalizado. */
    private static function matchingNavegador(array $usuario): array
    {
        $m = [];
        foreach ($usuario as $k => $v) {
            $clave = self::ALIAS_USUARIO[strtolower((string) $k)] ?? strtolower((string) $k);
            if (!in_array($clave, self::HASHEAR, true) || $v === '' || $v === null) continue;
            $n = self::normalizar($clave, (string) $v);
            if ($n !== '') $m[$clave] = $n;
        }
        return $m;
    }

    /** custom_data: sólo escalares/listas simples, y nunca un correo o teléfono por error. */
    private static function limpiarDatos(array $datos): array
    {
        $out = [];
        foreach ($datos as $k => $v) {
            $k = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $k);
            if ($k === '' || strlen($k) > 40) continue;
            if (is_array($v)) {
                $v = array_values(array_filter($v, 'is_scalar'));
            } elseif (is_string($v)) {
                $v = mb_substr(trim($v), 0, 200);
                if (filter_var($v, FILTER_VALIDATE_EMAIL) || preg_match('/^\+?\d[\d\s]{7,}$/', $v)) continue;
            } elseif (!is_scalar($v)) {
                continue;
            }
            if ($k === 'value') $v = (float) $v;
            $out[$k] = $v;
        }
        return $out;
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // fbc / fbp / IP / URL
    // ─────────────────────────────────────────────────────────────────────────────

    private static function fbcDesdeUrl(): string
    {
        $fbclid = $_GET['fbclid'] ?? '';
        return $fbclid !== '' ? 'fb.1.' . round(microtime(true) * 1000) . '.' . preg_replace('/[^\w\-]/', '', $fbclid) : '';
    }

    /** Si llegó con ?fbclid= (clic en un anuncio), deja la cookie _fbc 90 días desde el servidor. */
    private static function guardarFbcDesdeUrl(): void
    {
        $fbclid = $_GET['fbclid'] ?? '';
        if ($fbclid === '' || headers_sent()) return;
        $actual = $_COOKIE['_fbc'] ?? '';
        if ($actual !== '' && substr($actual, strrpos($actual, '.') + 1) === $fbclid) return;
        $fbc = self::fbcDesdeUrl();
        setcookie('_fbc', $fbc, ['expires' => time() + 90 * 86400, 'path' => '/', 'samesite' => 'Lax', 'secure' => self::https()]);
        $_COOKIE['_fbc'] = $fbc;
    }

    public static function ip(): string
    {
        if (self::cfg()['detras_de_proxy']) {
            foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP'] as $h) {
                if (!empty($_SERVER[$h])) {
                    $ip = trim(explode(',', $_SERVER[$h])[0]);
                    if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
                }
            }
        }
        return $_SERVER['REMOTE_ADDR'] ?? '';
    }

    private static function https(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    }

    private static function urlActual(): string
    {
        if (empty($_SERVER['HTTP_HOST'])) return '';
        // Un POST de formulario (procesar.php): el evento pasó en la página del formulario, no aquí.
        $ref = $_SERVER['HTTP_REFERER'] ?? '';
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $ref !== '' && parse_url($ref, PHP_URL_HOST) === explode(':', $_SERVER['HTTP_HOST'])[0]) {
            return $ref;
        }
        return (self::https() ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . ($_SERVER['REQUEST_URI'] ?? '/');
    }

    /** URL pública de la carpeta meta/ (para meta.js y track.php), calculada desde el disco. */
    private static function urlDelKit(): string
    {
        $raiz = rtrim(str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: ''), '/');
        $kit = str_replace('\\', '/', realpath(__DIR__));
        if ($raiz !== '' && strpos($kit, $raiz) === 0) return substr($kit, strlen($raiz)) ?: '';
        return '/meta'; // respaldo: el kit en /meta del sitio
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // HTTP + log
    // ─────────────────────────────────────────────────────────────────────────────

    private static function post(array $cuerpo): array
    {
        $c = self::cfg();
        $url = "https://graph.facebook.com/{$c['version_api']}/{$c['pixel_id']}/events?access_token=" . urlencode($c['token']);
        $json = json_encode($cuerpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true, CURLOPT_POSTFIELDS => $json, CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 5,
            ]);
            $txt = curl_exec($ch);
            $codigo = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);
        } else {
            $ctx = stream_context_create(['http' => [
                'method' => 'POST', 'header' => "Content-Type: application/json\r\n",
                'content' => $json, 'timeout' => 5, 'ignore_errors' => true,
            ]]);
            $txt = @file_get_contents($url, false, $ctx);
            $cabeceras = function_exists('http_get_last_response_headers') ? (http_get_last_response_headers() ?? []) : ($http_response_header ?? []);
            $codigo = isset($cabeceras[0]) && preg_match('/\s(\d{3})\s/', $cabeceras[0], $m) ? (int) $m[1] : 0;
            $err = $txt === false ? 'sin respuesta (¿allow_url_fopen apagado?)' : '';
        }
        if ($txt === false || $txt === '') return ['ok' => false, 'respuesta' => "error de red: {$err}"];
        $r = json_decode($txt, true);
        return ['ok' => $codigo === 200 && isset($r['events_received']), 'respuesta' => $r ?? $txt];
    }

    public static function archivoLog(): string
    {
        return __DIR__ . '/logs/eventos-' . date('Y-m') . '.log.php';
    }

    private static function log(string $evento, string $eventId, array $evt, array $r): void
    {
        if (!self::cfg()['log']) return;
        $dir = __DIR__ . '/logs';
        if (!is_dir($dir) || !is_writable($dir)) return;
        // .php con un exit arriba: aunque el hosting ignore el .htaccess (nginx), no se puede leer por web.
        $archivo = self::archivoLog();
        if (!is_file($archivo)) @file_put_contents($archivo, "<?php http_response_code(404); exit; ?>\n", LOCK_EX);
        $claves = array_keys($evt['user_data'] ?? []);
        $linea = json_encode([
            't' => date('c'), 'evento' => $evento, 'event_id' => $eventId, 'modo' => $r['modo'] ?? '',
            'ok' => $r['ok'], 'url' => $evt['event_source_url'] ?? '',
            'user_data' => $claves,               // sólo QUÉ se mandó, nunca el valor
            'custom_data' => $evt['custom_data'] ?? null,
            'respuesta' => $r['respuesta'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        @file_put_contents($archivo, $linea . "\n", FILE_APPEND | LOCK_EX);
    }
}
