<?php
// Ejemplo del patrón servidor: el formulario llega aquí, se valida/guarda, y recién entonces
// se manda el Lead a Meta con el event_id que puso meta.js en el campo oculto.
require_once __DIR__ . '/../meta/meta.php';

$nombre = trim($_POST['nombre'] ?? '');
$email = trim($_POST['email'] ?? '');
$telefono = trim($_POST['telefono'] ?? '');

if ($nombre === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    header('Location: index.php?error=1');
    exit;
}

// … aquí tu sitio guarda el lead, manda el correo, etc.

$datos = ['content_name' => 'Formulario contacto', 'content_category' => 'depto-2d'];
$r = Meta::track('Lead', ['nombre' => $nombre, 'email' => $email, 'telefono' => $telefono], $datos, $_POST['meta_event_id'] ?? null);

header('Location: gracias.php');
