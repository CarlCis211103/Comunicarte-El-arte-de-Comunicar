<?php
declare(strict_types=1);
$dir = __DIR__ . '/data';
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS'])]);
session_name('mkses');
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function out(int $code, array $a): void { http_response_code($code); echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }
function rd(string $f): array { $j = @file_get_contents($f); $d = $j ? json_decode($j, true) : null; return is_array($d) ? $d : []; }
function wr(string $f, array $d): bool {
  $tmp = $f . '.tmp';
  if (file_put_contents($tmp, json_encode($d, JSON_UNESCAPED_UNICODE), LOCK_EX) === false) return false;
  return rename($tmp, $f);
}

$a = $_GET['a'] ?? '';
$raw = file_get_contents('php://input') ?: '';
if (strlen($raw) > 3000000) out(413, ['err' => 'Datos demasiado grandes']);
$in = $raw !== '' ? (json_decode($raw, true) ?: []) : [];
$users = rd("$dir/users.json");
if (!$users) out(500, ['err' => 'Falta ejecutar setup.php']);

// ---- Login (con límite de intentos por IP) ----
if ($a === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
  $ip = $_SERVER['REMOTE_ADDR'] ?? '?'; $ff = "$dir/fails.json"; $F = rd($ff); $now = time();
  $F[$ip] = array_values(array_filter($F[$ip] ?? [], fn($t) => $t > $now - 900));
  if (count($F[$ip]) >= 5) out(429, ['err' => 'Demasiados intentos. Espera 15 minutos.']);
  $u = mb_strtolower(trim((string)($in['u'] ?? ''))); $p = (string)($in['p'] ?? '');
  $usr = $users[$u] ?? null;
  if ($usr && password_verify($p, $usr['hash'])) {
    session_regenerate_id(true);
    $_SESSION['u'] = $u; $_SESSION['csrf'] = bin2hex(random_bytes(16));
    unset($F[$ip]); wr($ff, $F);
    out(200, ['ok' => true]);
  }
  $F[$ip][] = $now; wr($ff, $F); usleep(400000);
  out(401, ['err' => 'Usuario o contraseña incorrectos']);
}

// ---- Todo lo demás requiere sesión ----
$u = $_SESSION['u'] ?? null;
if (!$u || !isset($users[$u])) out(401, ['err' => 'auth']);
$me = $users[$u];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !hash_equals((string)($_SESSION['csrf'] ?? ''), (string)($_SERVER['HTTP_X_CSRF'] ?? ''))) out(403, ['err' => 'csrf']);

if ($a === 'logout') { $_SESSION = []; session_destroy(); out(200, ['ok' => true]); }

if ($a === 'me') {
  $mg = [];
  if ($me['role'] === 'admin') foreach ($users as $x) if ($x['role'] === 'mgr') $mg[] = $x['name'];
  out(200, ['role' => $me['role'], 'name' => $me['name'], 'csrf' => $_SESSION['csrf'], 'mgrs' => $mg]);
}

$sf = "$dir/state.json";

// Vista del comisionado: se filtra AQUÍ en el servidor (sin precios, costos, cobros ni notas)
function ops_for(array $st, string $name): array {
  $nm = mb_strtolower(trim($name));
  $svc = []; foreach (($st['services'] ?? []) as $s) $svc[$s['id'] ?? ''] = $s['n'] ?? '';
  $cs = array_values(array_filter($st['clients'] ?? [], fn($c) => !empty($c['active']) && mb_strtolower(trim((string)($c['enc'] ?? ''))) === $nm));
  $ids = array_map(fn($c) => $c['id'] ?? '', $cs);
  $clients = array_map(fn($c) => [
    'id' => $c['id'] ?? '', 'nombre' => $c['nombre'] ?? '', 'negocio' => $c['negocio'] ?? '', 'contacto' => $c['contacto'] ?? '',
    'ini' => $c['ini'] ?? '', 'meses' => $c['meses'] ?? 0, 'active' => true,
    'svcs' => array_map(fn($x) => ['n' => $svc[$x['sid'] ?? ''] ?? ''], $c['svcs'] ?? []),
  ], $cs);
  $tasks = array_values(array_map(fn($t) => [
    'id' => $t['id'] ?? '', 'cid' => $t['cid'] ?? '', 'n' => $t['n'] ?? '', 'd' => $t['d'] ?? '', 's' => $t['s'] ?? 'pendiente', 'av' => $t['av'] ?? '',
  ], array_filter($st['tasks'] ?? [], fn($t) => in_array($t['cid'] ?? '', $ids, true))));
  return ['n' => $name, 'f' => date('Y-m-d'), 'clients' => $clients, 'tasks' => $tasks];
}

if ($a === 'state' && $_SERVER['REQUEST_METHOD'] === 'GET') {
  $st = rd($sf);
  out(200, ['data' => $me['role'] === 'admin' ? (object)$st : ops_for($st, $me['name'])]);
}

if ($a === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
  if ($me['role'] !== 'admin') out(403, ['err' => 'Solo el administrador puede guardar']);
  $st = $in['state'] ?? null;
  if (!is_array($st) || !isset($st['clients'], $st['tasks'])) out(400, ['err' => 'Datos inválidos']);
  unset($st['mgrs']);
  $b = "$dir/backup-" . date('Ymd') . '.json';
  if (is_file($sf) && !is_file($b)) @copy($sf, $b);
  if (!wr($sf, $st)) out(500, ['err' => 'No se pudo guardar en el servidor']);
  out(200, ['ok' => true]);
}

out(404, ['err' => 'No encontrado']);
