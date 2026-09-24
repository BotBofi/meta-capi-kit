<?php
/*
 * Configuración del kit Meta (Pixel + Conversions API).
 *
 * 1. Copia este archivo como `config.php` (en la misma carpeta `meta/`).
 * 2. Llena los valores. Nada más.
 *
 * `config.php` NUNCA se sube a git ni se comparte: tiene el token de acceso.
 * El kit trae un .htaccess que impide abrirlo desde el navegador, pero si tu hosting
 * te deja poner archivos FUERA de public_html, mejor todavía: pon el config ahí y
 * define META_CONFIG antes de incluir el kit:
 *     define('META_CONFIG', '/home/usuario/meta-config.php');
 */
return [

    // ── Lo obligatorio ────────────────────────────────────────────────────────────
    // Events Manager → tu conjunto de datos (dataset / píxel) → Configuración → «ID del conjunto de datos».
    'pixel_id' => '',

    // Events Manager → el mismo conjunto de datos → Configuración → API de conversiones →
    // «Generar token de acceso». Es largo (empieza con EAA…). Trátalo como una contraseña.
    'token' => '',

    // ── Mientras pruebas ──────────────────────────────────────────────────────────
    // Events Manager → Probar eventos → «Código de prueba» (ej. TEST12345).
    // Con esto los eventos del servidor aparecen en vivo en esa pestaña.
    // ⚠️ Déjalo VACÍO en producción: los eventos con código de prueba no optimizan anuncios.
    'test_event_code' => '',

    // 'enviar'  → manda de verdad a Meta.
    // 'simular' → no manda nada; escribe en logs/ lo que habría mandado. Ideal para el primer día.
    'modo' => 'simular',

    // ── Tu sitio ──────────────────────────────────────────────────────────────────
    // Dominios desde donde se aceptan eventos del navegador (sin https://). Evita que
    // otro sitio te llene el píxel de basura usando tu endpoint.
    'dominios' => ['localhost', '127.0.0.1', 'misitio.cl', 'www.misitio.cl'],

    // Moneda por defecto para eventos con `value` (ISO 4217).
    'moneda' => 'CLP',

    // País por defecto de los visitantes (ISO de 2 letras, minúsculas). Mejora el cruce.
    'pais' => 'cl',

    // Código de país para teléfonos escritos sin él (912345678 → 56912345678).
    'codigo_telefono' => '56',

    // ── Eventos ───────────────────────────────────────────────────────────────────
    // Los eventos ESTÁNDAR de Meta (Lead, Contact, ViewContent, Schedule…) ya están
    // permitidos. Aquí declaras los PROPIOS de tu sitio. Un evento que no esté en la
    // lista se rechaza: así un error de tipeo no crea un evento fantasma en Meta.
    // Convención: PascalCase, en español o inglés pero siempre igual, ≤ 40 caracteres.
    'eventos_propios' => [
        // 'VerProyecto',
        // 'UsarSimulador',
        // 'DescargarBrochure',
    ],

    // Lo que el kit mide solo, sin que escribas código:
    'auto' => [
        'pageview' => true,   // PageView en cada página (navegador + servidor, deduplicado)
        'contacto' => true,   // clic en enlaces tel:, mailto:, wa.me / api.whatsapp.com → Contact
        'scroll'   => false,  // llegó al 75 % de la página → evento propio «Scroll75»
    ],

    // ── Privacidad ────────────────────────────────────────────────────────────────
    // true → el píxel no dispara nada hasta que tu banner de cookies llame a
    //        window.metaKit.consentir(). Lo que pase antes queda en cola y se manda después.
    'esperar_consentimiento' => false,

    // ── Hosting ───────────────────────────────────────────────────────────────────
    // Si el sitio está detrás de Cloudflare u otro proxy, pon true para leer la IP real
    // del visitante (CF-Connecting-IP / X-Forwarded-For). Si no sabes, déjalo en false.
    'detras_de_proxy' => false,

    // Versión de la Graph API. Cámbiala sólo si Meta avisa que caducó.
    'version_api' => 'v24.0',

    // ── Diagnóstico ───────────────────────────────────────────────────────────────
    // Clave para abrir meta/diagnostico.php?clave=… (vacía = diagnóstico apagado).
    'clave_diagnostico' => '',

    // Registrar cada evento en meta/logs/ (sin datos personales: sólo hashes).
    'log' => true,
];
