<?php
declare(strict_types=1);
$dir = __DIR__ . '/data';
const MAX_COM = 10; // máximo de comisionados
if (is_file("$dir/users.json")) { http_response_code(403); exit('Ya está configurado. Borra setup.php del servidor.'); }
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$msg = ''; $done = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $users = []; $err = '';
  for ($i = 0; $i <= MAX_COM; $i++) {
    $u = mb_strtolower(trim((string)($_POST["u$i"] ?? ''))); $n = trim((string)($_POST["n$i"] ?? '')); $p = (string)($_POST["p$i"] ?? '');
    // Comisionados (1..10) son opcionales: si nombre y contraseña están vacíos, se omiten
    if ($i > 0 && $n === '' && $p === '') continue;
    if (!preg_match('/^[a-z0-9._-]{3,30}$/', $u)) $err = 'Usuario inválido (3-30 letras, números, punto, guion) en: ' . ($i ? "Comisionado $i" : 'Administrador') . '.';
    elseif ($n === '') $err = 'Falta el nombre de: ' . ($i ? "Comisionado $i" : 'Administrador') . '.';
    elseif (strlen($p) < 10) $err = 'La contraseña de ' . ($i ? "Comisionado $i" : 'Administrador') . ' debe tener al menos 10 caracteres.';
    elseif (isset($users[$u])) $err = 'Los usuarios no pueden repetirse.';
    else {
      foreach ($users as $x) if (mb_strtolower($x['name']) === mb_strtolower($n)) { $err = 'Los nombres no pueden repetirse.'; break; }
    }
    if ($err) break;
    $users[$u] = ['role' => $i === 0 ? 'admin' : 'mgr', 'name' => $n, 'hash' => password_hash($p, PASSWORD_DEFAULT)];
  }
  if (!$err) {
    if (!is_dir($dir) && !@mkdir($dir, 0750, true)) $err = 'No se pudo crear la carpeta data.';
    else {
      file_put_contents("$dir/.htaccess", "Require all denied\nDeny from all\n");
      file_put_contents("$dir/index.html", '');
      if (file_put_contents("$dir/users.json", json_encode($users, JSON_UNESCAPED_UNICODE), LOCK_EX) === false) $err = 'No se pudo escribir users.json.';
      else $done = true;
    }
  }
  $msg = $err;
}
?><!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>Instalación</title>
<style>body{font:15px system-ui,sans-serif;max-width:520px;margin:30px auto;padding:0 14px}label{display:block;margin-top:8px;font-size:13px}input{width:100%;padding:8px;box-sizing:border-box}fieldset{margin:14px 0}</style></head><body>
<h2>Instalación: usuarios</h2>
<?php if ($done): ?>
<p><b>Listo.</b> Usuarios creados.</p><p><b>Ahora borra el archivo setup.php</b> desde el Administrador de archivos y entra a <a href="index.html">index.html</a>.</p>
<?php else: ?>
<?php if ($msg) echo '<p style="color:#b42318">' . $h($msg) . '</p>'; ?>
<form method="post" autocomplete="off">
<p style="font-size:13px;color:#555">El administrador es obligatorio. Los comisionados son opcionales: deja en blanco los que no uses (puedes tener hasta <?= MAX_COM ?>).</p>
<?php for ($i = 0; $i <= MAX_COM; $i++): $t = $i ? "Comisionado $i" . ' (opcional)' : 'Administrador'; ?>
<fieldset><legend><b><?= $h($t) ?></b></legend>
<label>Usuario para iniciar sesión</label><input name="u<?= $i ?>" value="<?= $h($_POST["u$i"] ?? ($i ? "com$i" : 'admin')) ?>"<?= $i ? '' : ' required' ?>>
<label>Nombre de la persona<?= $i ? ' (igual al “Comisionado del proyecto” de sus clientes)' : '' ?></label><input name="n<?= $i ?>" value="<?= $h($_POST["n$i"] ?? '') ?>"<?= $i ? '' : ' required' ?>>
<label>Contraseña (mínimo 10 caracteres)</label><input type="password" name="p<?= $i ?>"<?= $i ? '' : ' required' ?>></fieldset>
<?php endfor; ?>
<button>Crear usuarios</button></form>
<?php endif; ?></body></html>
