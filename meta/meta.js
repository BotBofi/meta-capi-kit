/*
 * Kit Meta — lado navegador. Lo carga Meta::head(); no se incluye a mano.
 *
 * Cada evento sale DOS veces con el mismo event_id:
 *   1. por el píxel (fbq)            → Meta lo asocia a la cookie del navegador
 *   2. por track.php (Conversions API) → llega aunque haya bloqueador de anuncios
 * Meta deduplica por (nombre + event_id) y cuenta uno.
 *
 * API pública (window.metaKit):
 *   metaKit.track('ViewContent', {content_name: 'Depto 2D', content_category: 'depto-2d'})
 *   metaKit.track('Lead', {content_name: 'Cotizar'}, {email: 'a@b.cl', telefono: '912345678'})
 *   metaKit.consentir()   // si esperar_consentimiento = true, lo llama tu banner de cookies
 *
 * Sin JavaScript propio, con atributos en el HTML:
 *   <a href="..." data-meta-evento="ViewContent" data-meta-datos='{"content_name":"Torre Norte"}'>
 *   <button data-meta-evento="Schedule">Agendar visita</button>
 *   <form data-meta-evento="Lead" data-meta-datos='{"content_category":"depto-2d"}'>   → navegador + servidor
 *   <form data-meta-evento="Lead" data-meta-servidor>  → sólo navegador; tu PHP llama a Meta::track()
 *                                                        con $_POST['meta_event_id']
 */
(function () {
  'use strict';
  var K = window.META_KIT;
  if (!K || window.metaKit) return;

  var consentido = !K.esperarConsentimiento;
  var cola = [];

  function log() {
    if (K.debug && window.console) console.log.apply(console, ['%c[Meta]', 'color:#1877f2;font-weight:bold'].concat([].slice.call(arguments)));
  }

  function nuevoId() {
    var r = (window.crypto && crypto.getRandomValues) ? Array.prototype.map.call(crypto.getRandomValues(new Uint8Array(8)), function (b) {
      return ('0' + b.toString(16)).slice(-2);
    }).join('') : Math.random().toString(16).slice(2, 18);
    return r + '.' + Math.floor(Date.now() / 1000);
  }

  function cookie(n) {
    var m = document.cookie.match('(?:^|; )' + n + '=([^;]*)');
    return m ? decodeURIComponent(m[1]) : '';
  }

  // Llegó desde un anuncio (?fbclid=): se guarda _fbc por si el píxel está bloqueado.
  (function () {
    var fbclid = new URLSearchParams(location.search).get('fbclid');
    if (!fbclid) return;
    var actual = cookie('_fbc');
    if (actual && actual.slice(actual.lastIndexOf('.') + 1) === fbclid) return;
    var v = 'fb.1.' + Date.now() + '.' + fbclid;
    document.cookie = '_fbc=' + v + ';path=/;max-age=' + 90 * 86400 + ';samesite=lax' + (location.protocol === 'https:' ? ';secure' : '');
  })();

  function permitido(evento) {
    return K.estandar.indexOf(evento) >= 0 || K.propios.indexOf(evento) >= 0 || (evento === 'Scroll75' && K.auto.scroll);
  }

  /**
   * Dispara un evento. Devuelve el event_id (útil para mandarlo al servidor en un formulario).
   * opciones.soloNavegador = true → no llama a track.php (el servidor lo manda por su cuenta).
   */
  function track(evento, datos, usuario, opciones) {
    datos = datos || {};
    usuario = usuario || {};
    opciones = opciones || {};
    var id = opciones.eventId || nuevoId();
    if (!permitido(evento)) {
      console.warn('[Meta] evento «' + evento + '» no permitido. Si es propio, agrégalo a eventos_propios en meta/config.php');
      return id;
    }
    if (!consentido) {
      cola.push([evento, datos, usuario, Object.assign({}, opciones, { eventId: id })]);
      log('en cola hasta el consentimiento:', evento);
      return id;
    }

    // 1) Píxel
    if (window.fbq) {
      var metodo = K.estandar.indexOf(evento) >= 0 ? 'track' : 'trackCustom';
      fbq(metodo, evento, datos, { eventID: id });
    }

    // 2) Servidor
    if (!opciones.soloNavegador) {
      var cuerpo = JSON.stringify({
        evento: evento, event_id: id, datos: datos, usuario: usuario,
        url: location.href, fbp: cookie('_fbp'), fbc: cookie('_fbc')
      });
      try {
        fetch(K.endpoint, {
          method: 'POST', headers: { 'Content-Type': 'application/json' },
          body: cuerpo, keepalive: true, credentials: 'same-origin'
        }).then(function (r) { return r.json(); })
          .then(function (j) { log(evento, j.ok ? '✓ servidor' : '✗ servidor', j); })
          .catch(function (e) { log(evento, '✗ servidor', e); });
      } catch (e) { /* navegador muy viejo: queda sólo el píxel */ }
    }
    log(evento, datos, 'event_id=' + id);
    return id;
  }

  function consentir() {
    if (consentido) return;
    consentido = true;
    if (window.fbq) fbq('consent', 'grant');
    var pendientes = cola; cola = [];
    pendientes.forEach(function (a) { track.apply(null, a); });
  }

  function leerDatos(el) {
    var t = el.getAttribute('data-meta-datos');
    if (!t) return {};
    try { return JSON.parse(t); } catch (e) { console.warn('[Meta] data-meta-datos no es JSON válido:', t); return {}; }
  }

  // Datos de la persona que se pueden sacar de un formulario, por name/type/autocomplete.
  function usuarioDelFormulario(form) {
    var u = {};
    var mapa = [
      ['email', /e-?mail|correo/i], ['telefono', /tel|fono|phone|celular|whatsapp|movil/i],
      ['apellido', /apellido|last.?name|family/i], ['nombre', /^nombre|name|first/i],
      ['ciudad', /ciudad|comuna|city/i], ['rut', /^rut$/i]
    ];
    Array.prototype.forEach.call(form.elements, function (el) {
      if (!el.name || !el.value || el.type === 'password' || el.type === 'hidden') return;
      var pista = el.type === 'email' ? 'email' : el.type === 'tel' ? 'telefono' : (el.name + ' ' + (el.autocomplete || ''));
      for (var i = 0; i < mapa.length; i++) {
        if (pista === mapa[i][0] || mapa[i][1].test(pista)) { if (!u[mapa[i][0]]) u[mapa[i][0]] = el.value; break; }
      }
    });
    return u;
  }

  // ── Atributos data-meta-evento ──────────────────────────────────────────────────
  document.addEventListener('click', function (ev) {
    var el = ev.target.closest && ev.target.closest('[data-meta-evento]');
    if (el && el.tagName !== 'FORM') {
      track(el.getAttribute('data-meta-evento'), leerDatos(el));
      return;
    }
    // Automático: tel:, mailto:, WhatsApp → Contact
    if (!K.auto.contacto) return;
    var a = ev.target.closest && ev.target.closest('a[href]');
    if (!a) return;
    var h = a.getAttribute('href') || '';
    var canal = /^tel:/i.test(h) ? 'telefono' : /^mailto:/i.test(h) ? 'correo'
      : /(wa\.me|api\.whatsapp\.com|web\.whatsapp\.com)/i.test(h) ? 'whatsapp' : '';
    if (canal) track('Contact', { canal: canal });
  }, true);

  document.addEventListener('submit', function (ev) {
    var form = ev.target;
    if (!form.hasAttribute || !form.hasAttribute('data-meta-evento')) return;
    var soloNavegador = form.hasAttribute('data-meta-servidor');
    var id = track(form.getAttribute('data-meta-evento'), leerDatos(form),
      soloNavegador ? {} : usuarioDelFormulario(form), { soloNavegador: soloNavegador });
    // El event_id viaja con el formulario para que el PHP que lo procesa deduplique.
    var h = form.querySelector('input[name="meta_event_id"]');
    if (!h) { h = document.createElement('input'); h.type = 'hidden'; h.name = 'meta_event_id'; form.appendChild(h); }
    h.value = id;
  }, true);

  // ── Automáticos ─────────────────────────────────────────────────────────────────
  if (K.auto.pageview) track('PageView');

  if (K.auto.scroll) {
    var hecho = false;
    window.addEventListener('scroll', function () {
      if (hecho) return;
      var d = document.documentElement;
      if ((window.scrollY + window.innerHeight) / d.scrollHeight >= 0.75) { hecho = true; track('Scroll75', { pagina: location.pathname }); }
    }, { passive: true });
  }

  window.metaKit = { track: track, consentir: consentir, nuevoId: nuevoId };
  log('listo · píxel', K.pixelId);
})();
