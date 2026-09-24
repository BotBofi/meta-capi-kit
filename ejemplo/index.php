<?php require_once __DIR__ . '/../meta/meta.php'; ?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Ejemplo · Kit Meta</title>
  <?= Meta::head() ?>
  <style>
    body{font:16px/1.5 system-ui,sans-serif;max-width:640px;margin:32px auto;padding:0 16px;color:#1c1e21}
    section{border:1px solid #dadde1;border-radius:10px;padding:16px;margin:16px 0}
    button,a.btn{display:inline-block;padding:8px 14px;border-radius:6px;border:1px solid #1877f2;color:#1877f2;background:#fff;text-decoration:none;cursor:pointer;font:inherit;margin:4px 4px 4px 0}
    input{font:inherit;padding:7px;border:1px solid #ccc;border-radius:6px;width:100%;box-sizing:border-box;margin:4px 0}
    code{background:#f0f2f5;padding:1px 4px;border-radius:4px;font-size:14px}
  </style>
</head>
<body>
  <h1>Ejemplo del Kit Meta</h1>
  <p>Abre la consola del navegador: cada acción imprime <code>[Meta]</code> con su event_id.</p>

  <section>
    <h2>1. Clics con atributos (cero JavaScript)</h2>
    <button data-meta-evento="ViewContent" data-meta-datos='{"content_name":"Depto 2D","content_category":"depto-2d","proyecto":"torre-norte"}'>Ver depto 2D</button>
    <button data-meta-evento="ViewContent" data-meta-datos='{"content_name":"Depto 3D","content_category":"depto-3d","proyecto":"torre-norte"}'>Ver depto 3D</button>
    <button data-meta-evento="Schedule" data-meta-datos='{"content_name":"Agendar visita"}'>Agendar visita</button>
    <button data-meta-evento="UsarSimulador">Evento propio: UsarSimulador</button>
  </section>

  <section>
    <h2>2. Contacto automático</h2>
    <a class="btn" href="https://wa.me/56900000000">WhatsApp</a>
    <a class="btn" href="tel:+56900000000">Llamar</a>
    <a class="btn" href="mailto:hola@ejemplo.cl">Correo</a>
  </section>

  <section>
    <h2>3. Formulario procesado por PHP (patrón recomendado)</h2>
    <p>El navegador dispara el píxel; <code>procesar.php</code> manda el evento por servidor con el mismo event_id y los datos reales.</p>
    <form action="procesar.php" method="post" data-meta-evento="Lead" data-meta-servidor data-meta-datos='{"content_category":"depto-2d"}'>
      <input name="nombre" placeholder="Nombre" required>
      <input name="email" type="email" placeholder="Correo" required>
      <input name="telefono" type="tel" placeholder="Celular">
      <button>Quiero que me contacten</button>
    </form>
  </section>

  <section>
    <h2>4. Desde tu propio JavaScript</h2>
    <button onclick="metaKit.track('Search', {search_string: 'departamento ñuñoa'})">metaKit.track('Search', …)</button>
  </section>
</body>
</html>
