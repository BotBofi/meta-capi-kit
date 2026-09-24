# Kit Meta: Pixel + Conversions API para cualquier web PHP

Una carpeta (`meta/`) que se copia al sitio, un `config.php` donde se pegan los datos de Meta,
y una línea en el `<head>`. Después le pides a Claude Code que enchufe las acciones de tu web a
eventos, y con esos eventos armas **públicos y anuncios micro-segmentados**.

Funciona en hosting compartido común (cPanel, PHP 7.4+). No necesita variables de entorno,
Composer ni Node.

---

## Qué hace, en una imagen

```
Visitante hace clic en «Ver depto 2D»
        │
        ├─► Píxel (navegador)  ─── fbq('track','ViewContent', {...}, {eventID: X}) ───┐
        │                                                                              ├─► Meta cuenta UNO
        └─► meta/track.php ─► Conversions API (servidor, con el token) ─ event_id: X ─┘   (deduplica por X)
```

- **El píxel** trae la cookie del navegador (`_fbp`, `_fbc` del clic en el anuncio).
- **La Conversions API** llega aunque el visitante tenga bloqueador de anuncios o Safari recorte cookies.
- **El mismo `event_id`** en los dos: Meta se queda con uno. Mejor cruce, sin inflar números.
- Correo, teléfono y nombre se **normalizan y hashean (SHA-256)** en tu servidor antes de salir.
  El log guarda sólo *qué* campos se mandaron, nunca el valor.

## Archivos

| | |
|---|---|
| `meta/config.example.php` | la plantilla del config: se copia como `config.php` |
| `meta/meta.php` | la librería (PHP): `Meta::head()`, `Meta::track()`, `Meta::pixel()` |
| `meta/meta.js` | el lado navegador: atributos `data-meta-*`, `metaKit.track()`, automáticos |
| `meta/track.php` | el endpoint que recibe los eventos del navegador y los manda a la API |
| `meta/diagnostico.php` | página de prueba: revisa el config, manda un evento, muestra el log |
| `meta/INSTRUCCIONES-CLAUDE.md` | lo que lee Claude Code para cablear tu sitio |
| `ejemplo/` | una página de muestra con cada mecanismo funcionando |

---

## Parte 1: Meta (lo hacemos juntos)

### 1.1 El conjunto de datos (píxel)

1. [business.facebook.com](https://business.facebook.com) → tu portafolio comercial → **Events Manager**.
2. **Conectar orígenes de datos → Web** → nombre (ej. «Web Juan») → crear.
   Lo que Meta hoy llama *conjunto de datos* (dataset) es el píxel: el mismo ID sirve para los dos.
3. Salta el asistente de instalación (no pegamos el código a mano: lo pone el kit).
4. **Configuración** del conjunto de datos → copia el **ID** → `pixel_id` en `config.php`.

### 1.2 El token de la Conversions API

1. En el mismo conjunto de datos → **Configuración → API de conversiones → Generar token de acceso**.
2. Cópialo **en ese momento** (no se vuelve a mostrar) → `token` en `config.php`.
3. Es una contraseña: no va a git, ni a WhatsApp, ni a una captura de pantalla.

### 1.3 La cuenta publicitaria

En **Configuración del negocio → Orígenes de datos → Conjuntos de datos** → el tuyo →
**Asignar activos / Cuentas publicitarias conectadas** → agrega la cuenta publicitaria.
Sin esto, el evento no aparece para elegir al crear el anuncio ni el público.

### 1.4 El dominio

**Configuración del negocio → Seguridad de la marca → Dominios** → agregar y verificar el dominio
(meta-tag o registro DNS). No es obligatorio para empezar a mandar eventos, pero Meta lo pide para
atribuir bien y para que los eventos del dominio queden a tu nombre.

---

## Parte 2: instalar el kit

1. Copia la carpeta `meta/` a la raíz del sitio (al lado de `index.php`).
2. `meta/config.example.php` → cópialo como **`meta/config.php`** → llena `pixel_id`, `token`,
   `dominios` (tu dominio, con y sin `www`) y una `clave_diagnostico` cualquiera.
   **Deja `'modo' => 'simular'`** el primer día.
3. En el `<head>` común del sitio:
   ```php
   <?php require_once __DIR__ . '/meta/meta.php'; ?>
   ...
   <head>
       ...
       <?= Meta::head() ?>
   </head>
   ```
4. Abre `https://tusitio.cl/meta/diagnostico.php?clave=TU_CLAVE`: todo en ✓ salvo lo que falte.
5. Sube el sitio por FTP/SFTP. **`config.php` se sube a mano**: el `.gitignore` lo deja fuera de git a propósito.

> Si tu hosting deja poner archivos fuera de `public_html`, mejor aún: pon el config ahí y antes
> del `require` escribe `define('META_CONFIG', '/home/usuario/meta-config.php');`.

## Parte 3: que Claude Code cablee tu web

Abre Claude Code en la carpeta de tu sitio y pégale esto:

> Lee `meta/INSTRUCCIONES-CLAUDE.md` y síguelo. Primero instala la base en el head común; después
> recorre todo el sitio y muéstrame la tabla de acciones → eventos → parámetros **antes de editar
> nada**. Quiero poder segmentar anuncios por **[lo que te importe: tipo de producto, proyecto,
> comuna, plan…]**.

Lo importante de esa conversación **es la tabla**: ahí decides con qué vas a poder segmentar.
Regla de oro: **pocos eventos, muchos parámetros**. Un `ViewContent` con
`content_category: depto-2d` sirve más que un evento `VerDepto2D`, porque después el público se
filtra por el parámetro.

Lo que puede usar Claude (y tú a mano):

```html
<!-- clic: cero JavaScript -->
<button data-meta-evento="Schedule" data-meta-datos='{"proyecto":"torre-norte"}'>Agendar visita</button>

<!-- formulario que procesa tu PHP: el navegador dispara, tu PHP confirma con los datos reales -->
<form action="enviar.php" method="post" data-meta-evento="Lead" data-meta-servidor>
```
```php
// en enviar.php, después de guardar:
Meta::track('Lead', ['email' => $email, 'telefono' => $tel, 'nombre' => $nombre],
                    ['content_category' => 'depto-2d'], $_POST['meta_event_id'] ?? null);
```
```js
// cualquier otra cosa, desde tu JS
metaKit.track('UsarSimulador', {plazo: 20});
```

Automático, sin escribir nada: `PageView` en cada página, y `Contact` al hacer clic en
`tel:`, `mailto:` o WhatsApp (con `canal`).

## Parte 4: probar

1. **Simular** (`'modo' => 'simular'`): recorre el sitio haciendo cada acción. En la consola del
   navegador cada evento imprime `[Meta] …` y en `diagnostico.php` aparece la lista. Verifica que
   estén todas las filas de la tabla, con sus parámetros, sin duplicados.
2. **Probar eventos en Meta**: Events Manager → tu conjunto de datos → **Probar eventos** → copia
   el código (ej. `TEST12345`) → `test_event_code` en el config → `'modo' => 'enviar'`.
   Navega el sitio: cada evento aparece **dos veces** en la pestaña, una «Navegador» y otra
   «Servidor», marcadas como **deduplicadas**. Si ves sólo navegador, algo falla en el servidor
   (mira `diagnostico.php`). Usa tus propios datos al probar formularios: un correo sin cuenta de
   Meta no cruza.
3. **Producción**: **vacía `test_event_code`** (con él puesto, los eventos no optimizan anuncios).
   Al día siguiente mira en Events Manager la **calidad de coincidencia** (Event Match Quality) de
   cada evento: con `Lead` cargando correo + teléfono tendría que estar alta.

## Parte 5: usar los eventos

### A. Un público personalizado desde un evento

Administrador de anuncios → **Públicos → Crear público → Público personalizado → Sitio web**:
- Origen: tu conjunto de datos.
- Incluir personas que **cumplan con un evento**: `ViewContent`.
- **Refinar por → parámetro** `content_category` **es igual a** `depto-2d`.
- Retención: 30 días (hasta 180).

Resultado: «quienes miraron departamentos de 2 dormitorios en el último mes». Así se hace cada
micro-segmento: el mismo evento, distinto valor de parámetro. También se puede combinar
(incluir `ViewContent` depto-2d **y excluir** quienes ya hicieron `Lead`).

Tarda unas horas en poblarse, y Meta pide un mínimo de personas antes de dejar publicar un anuncio
contra él: con poco tráfico, ampliar la retención o juntar segmentos.

### B. Un anuncio que optimiza por un evento

- **Evento estándar** (ej. `Lead`): campaña con objetivo **Clientes potenciales** (o Ventas) →
  en el conjunto de anuncios, ubicación de la conversión **Sitio web** → conjunto de datos →
  evento `Lead`.
- **Evento propio o un segmento** (ej. «Lead de depto-2d», o `UsarSimulador`): primero
  Events Manager → **Conversiones personalizadas → Crear** → evento + regla de parámetro
  (`content_category = depto-2d`) → y en el conjunto de anuncios eliges esa conversión personalizada.

Para salir de la fase de aprendizaje, Meta necesita del orden de **50 conversiones por semana por
conjunto de anuncios**. Si el evento es raro (pocos `Lead`), optimiza por uno anterior y más
frecuente (`ViewContent` de la categoría, o `Contact`) y mide el `Lead` aparte.

### C. La prueba de punta a punta

1. Crea un público «vio `ViewContent` con `content_category = depto-2d`, últimos 7 días».
2. Genera tráfico real al sitio (tú y el equipo, desde el celular con sesión de Facebook/Instagram).
3. Al otro día, el público tiene tamaño estimado > 0 → el circuito funciona.
4. Una campaña chica con optimización por la conversión personalizada, presupuesto mínimo, y
   verificar en Events Manager que las conversiones atribuidas aparecen.

---

## Cuidados

- **Rubro inmobiliario (o empleo, crédito, política):** Meta exige declarar la **categoría
  especial de anuncios** (Vivienda, etc.) y eso recorta opciones de segmentación (edad, género,
  intereses detallados, similares). Revisar al crear la campaña qué queda disponible antes de
  prometer un segmento.
- **Consentimiento:** si el sitio tiene banner de cookies, pon `'esperar_consentimiento' => true`
  y que el botón «Aceptar» llame a `window.metaKit.consentir()`. Lo anterior queda en cola.
- **Qué nunca va en los parámetros:** correo, teléfono, RUT, nombre, ni nada sensible (salud,
  finanzas personales). Eso va en `usuario`, que se hashea. El kit filtra lo evidente, pero la
  responsabilidad es de quien arma la tabla.
- **Token filtrado:** Events Manager → API de conversiones → se genera otro y se reemplaza en el config.
- **Apagar el diagnóstico en producción:** deja `clave_diagnostico` vacía (responde 404).
- **nginx en vez de Apache:** el `.htaccess` no aplica. `config.php` igual no muestra nada (es PHP
  que sólo devuelve un arreglo) y los logs son `.php` que responden 404, pero conviene poner el
  config fuera de la carpeta pública (`META_CONFIG`).

## Probar el kit en tu computador

```bash
cp meta/config.example.php meta/config.php   # pixel_id cualquiera de 15 dígitos, modo simular
php -S localhost:8799
```
Abre `http://localhost:8799/ejemplo/index.php` con la consola abierta, y
`http://localhost:8799/meta/diagnostico.php?clave=…`.
