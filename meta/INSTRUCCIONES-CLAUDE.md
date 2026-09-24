# Instrucciones para Claude Code: conectar este sitio a Meta con el Kit Meta

Estás en un sitio web que tiene la carpeta `meta/` (el Kit Meta: Pixel + Conversions API).
Tu trabajo es **enchufar las acciones del sitio a eventos de Meta** para que después se puedan
armar públicos y optimizar anuncios por evento. El kit ya resuelve el transporte, el hash de
datos personales, la deduplicación y la seguridad. No reescribas nada de eso.

## Qué NO se toca

- `meta/meta.php`, `meta/track.php`, `meta/meta.js`, `meta/diagnostico.php`: son el kit. No los edites.
- `meta/config.php`: tiene el token. No lo leas en voz alta, no lo copies a otro archivo, no lo
  subas a git. Sólo puedes agregar nombres a `eventos_propios` (y avisar que lo hiciste).
- No inventes otro mecanismo de envío (otro fetch a graph.facebook.com, otro fbq init, GTM…).

## Paso 1: instalar la base (una vez)

1. Busca el header común del sitio (el `<head>` que comparten todas las páginas: un `header.php`,
   un layout, un include). Si no hay uno común, lista las páginas y agrégalo en cada una.
2. Arriba del archivo: `<?php require_once __DIR__ . '/<ruta>/meta/meta.php'; ?>` (ajusta la ruta).
3. Dentro de `<head>`: `<?= Meta::head() ?>`. Una sola vez por página.
4. **Si el sitio ya tiene un píxel pegado a mano** (`fbq('init'`, `fbevents.js`), quítalo: dos
   `init` duplican todo. Avisa qué quitaste.
5. Si el sitio no es PHP (HTML puro), dile a la persona que las páginas tienen que ser `.php`, o
   que el kit necesita al menos un include PHP en el `<head>`. No lo resuelvas por tu cuenta con otra cosa.

## Paso 2: el inventario, ANTES de editar

Recorre todo el sitio y arma una tabla con **cada acción que dice algo de la intención del
visitante**: botones, enlaces a WhatsApp/teléfono/correo, formularios, descargas, videos,
simuladores o calculadoras, filtros, galerías, cambios de pestaña, páginas clave vistas.

| Acción en el sitio | Dónde (archivo) | Evento | Parámetros (`datos`) | Cómo |
|---|---|---|---|---|
| Clic en «Ver depto 2D» | `proyecto.php` | `ViewContent` | `content_name`, `content_category: depto-2d`, `proyecto` | atributo |
| Formulario de contacto | `contacto.php` → `enviar.php` | `Lead` | `content_category` | servidor |

**Muéstrale la tabla a la persona y espera su OK antes de editar.** Lo que decide aquí es con qué
se van a poder segmentar los anuncios después.

### Reglas para elegir el evento

- Primero un **evento estándar** si calza: `ViewContent` (vio algo concreto), `Lead` (dejó datos),
  `Contact` (inició contacto), `Schedule` (agendó), `Search` (buscó), `CompleteRegistration`,
  `SubmitApplication`, `Subscribe`, `InitiateCheckout`, `Purchase`. Meta optimiza mejor con estándares.
- Un **evento propio** sólo si ningún estándar calza (ej. `UsarSimulador`, `DescargarBrochure`).
  PascalCase, siempre igual, y se agrega a `eventos_propios` en `meta/config.php` (si no, el kit lo rechaza).
- **Pocos eventos, muchos parámetros.** No `VerDepto2D` y `VerDepto3D`: un `ViewContent` con
  `content_category: depto-2d` / `depto-3d`. La segmentación fina se hace por parámetro.
- Los clics de `tel:`, `mailto:` y WhatsApp ya los mide el kit solo como `Contact` (con `canal`).
  No los dupliques.
- `PageView` ya sale solo en cada página. No lo dupliques.

### Reglas para los parámetros (`datos`)

- Vocabulario fijo y en minúsculas-con-guiones: `depto-2d`, no `Depto 2D` una vez y `2 dormitorios` otra.
- Usa los nombres estándar cuando existan: `content_name`, `content_category`, `content_ids`,
  `content_type`, `value`, `currency`, `search_string`. Para lo demás, claves propias cortas
  (`proyecto`, `comuna`, `canal`, `plan`).
- `value` sólo si hay un monto real (precio, plan). La moneda por defecto viene del config.
- **Nunca** datos personales en `datos` (correo, teléfono, RUT, nombre): esos van en `usuario`,
  que el kit hashea. El kit además filtra correos/teléfonos que se cuelen en `datos`.

## Paso 3: cablear, con el mecanismo más simple que sirva

**A. Atributo en el HTML (preferido para clics):**
```html
<a href="/proyecto/torre-norte" data-meta-evento="ViewContent"
   data-meta-datos='{"content_name":"Torre Norte","content_category":"depto-2d","proyecto":"torre-norte"}'>
```
Si el HTML se genera en PHP con datos dinámicos, arma el JSON con
`htmlspecialchars(json_encode($datos), ENT_QUOTES)`.

**B. Formulario que procesa un PHP del sitio (preferido para Lead):**
```html
<form action="enviar.php" method="post" data-meta-evento="Lead" data-meta-servidor
      data-meta-datos='{"content_category":"depto-2d"}'>
```
y en `enviar.php`, **después** de validar y guardar (sólo si salió bien):
```php
require_once __DIR__ . '/meta/meta.php';
Meta::track('Lead',
    ['nombre' => $nombre, 'email' => $email, 'telefono' => $telefono],   // crudos: el kit normaliza y hashea
    ['content_name' => 'Formulario contacto', 'content_category' => 'depto-2d'],
    $_POST['meta_event_id'] ?? null);                                    // el mismo id que usó el navegador
```
`meta.js` agrega solo el campo oculto `meta_event_id`. Sin ese id se cuenta doble.

**C. Formulario que se manda por JavaScript / a un servicio externo (Formspree, etc.):**
`<form data-meta-evento="Lead">` sin `data-meta-servidor`: el kit toma correo/teléfono/nombre
de los campos y lo manda por los dos lados.

**D. Acciones que no son un clic simple** (video que llegó al 50 %, simulador que calculó, paso 3
de un wizard): desde el JS del sitio:
```js
metaKit.track('UsarSimulador', {plazo: 20, pie: 20});
```

Si la página que se muestra DESPUÉS de una acción la genera PHP y quieres que el navegador
también la registre, usa `<?= Meta::pixel('Lead', $datos, $eventId) ?>` con el mismo `$eventId`
que usaste en `Meta::track()`.

## Paso 4: probar

1. Con `'modo' => 'simular'` en el config: abre el sitio, haz cada acción de la tabla y mira la
   consola del navegador (cada evento imprime `[Meta]` con su `event_id`) y
   `meta/diagnostico.php?clave=…` (la lista de eventos recibidos). Tiene que aparecer **cada fila
   de la tabla**, con sus parámetros, y nada repetido.
2. Si falta una o sale doble, corrígelo antes de pasar a `enviar`.
3. El paso a `'modo' => 'enviar'` y el `test_event_code` los hace la persona: no cambies el modo tú.

## Paso 5: dejar el mapa escrito

Crea `meta/MAPA-EVENTOS.md` con la tabla final (acción → evento → parámetros → valores posibles de
cada parámetro). Es lo que se usa después para armar públicos personalizados y conversiones
personalizadas en Meta, así que tiene que decir exactamente qué valores puede tomar cada parámetro.
Cuando se agregue una acción nueva al sitio, se agrega aquí también.
