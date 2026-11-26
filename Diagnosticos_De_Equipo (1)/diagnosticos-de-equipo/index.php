<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/db.php';

// If already logged in, redirect
if (isUserLoggedIn())  { header('Location: /diagnosticos-de-equipo/solicitud.php'); exit; }
if (isAdminLoggedIn()) { header('Location: /diagnosticos-de-equipo/admin.php');     exit; }

$regions = getRegions();
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="<?= htmlspecialchars(csrfToken()) ?>">
  <title>Diagnósticos de Equipo Tecnológico — Acceso</title>
  <meta name="description" content="Sistema de diagnóstico de equipo tecnológico — Coordinación General de TI">
  <link rel="stylesheet" href="/diagnosticos-de-equipo/css/neon.css?v=<?= (int)@filemtime(__DIR__ . '/css/neon.css') ?>">
</head>
<body data-page="login">
  <canvas id="particles-canvas"></canvas>

  <div class="login-wrapper relative">
    <div class="glass-card login-card">

      <!-- Logo / Header -->
      <div class="login-logo">
        <span class="icon">💻</span>
        <h1 class="neon-title pulse">DIAGNÓSTICOS</h1>
        <p class="neon-subtitle" style="margin-top:4px">de equipo tecnológico</p>
        <p style="margin-top:10px;font-size:0.8rem;color:var(--text-dim);letter-spacing:1px">
          Coordinación General de Tecnologías de Información
        </p>
      </div>

      <!-- Tabs -->
      <div id="auth-tabs">
        <div class="tabs">
          <button class="tab-btn active"    data-tab="login">🔑 Ingresar</button>
          <button class="tab-btn tab-magenta" data-tab="register">➕ Registrarse</button>
          <button class="tab-btn tab-green"   data-tab="admin">🛡 Admin</button>
        </div>

        <!-- ── Login Tab ── -->
        <div class="tab-panel active" data-panel="login">
          <form id="form-login" autocomplete="on" novalidate>
            <div class="form-group">
              <label class="form-label" for="login-region">Región</label>
              <select class="form-control" name="region" id="login-region" required>
                <option value="">— Selecciona tu región —</option>
                <?php foreach ($regions as $key => $r): ?>
                  <option value="<?= $key ?>"><?= htmlspecialchars($r['label']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label" for="login-correo">Correo electrónico</label>
              <input class="form-control" type="email" name="correo" id="login-correo"
                     placeholder="usuario@ejemplo.com" required autocomplete="email">
            </div>
            <div class="form-group">
              <label class="form-label" for="login-pass">Contraseña</label>
              <input class="form-control" type="password" name="contrasena" id="login-pass"
                     placeholder="••••••••" required autocomplete="current-password">
            </div>
            <button type="submit" class="btn btn-cyan-solid btn-full" style="margin-top:8px">
              🔑 Iniciar Sesión
            </button>
          </form>
          <p style="text-align:center;margin-top:18px;font-size:0.85rem;color:var(--text-dim)">
            ¿No tienes cuenta?
            <a href="#register" style="color:var(--neon-magenta);cursor:pointer"
               onclick="document.querySelector('[data-tab=register]').click()">Regístrate</a>
          </p>
        </div>

        <!-- ── Register Tab ── -->
        <div class="tab-panel" data-panel="register">
          <form id="form-register" autocomplete="on" novalidate>
            <div class="form-row cols-2">
              <div class="form-group">
                <label class="form-label" for="reg-nombre">Nombre</label>
                <input class="form-control" type="text" name="nombre" id="reg-nombre"
                       placeholder="Juan" required autocomplete="given-name">
              </div>
              <div class="form-group">
                <label class="form-label" for="reg-apellido">Apellido</label>
                <input class="form-control" type="text" name="apellido" id="reg-apellido"
                       placeholder="García" required autocomplete="family-name">
              </div>
            </div>
            <div class="form-group">
              <label class="form-label" for="reg-correo">Correo electrónico</label>
              <input class="form-control" type="email" name="correo" id="reg-correo"
                     placeholder="usuario@ejemplo.com" required autocomplete="email">
            </div>
            <div class="form-group">
              <label class="form-label" for="reg-tel">Teléfono</label>
              <input class="form-control" type="tel" name="telefono" id="reg-tel"
                     placeholder="312 000 0000" autocomplete="tel">
            </div>
            <div class="form-group">
              <label class="form-label" for="reg-region">Región</label>
              <select class="form-control" name="region" id="reg-region" required>
                <option value="">— Selecciona tu región —</option>
                <?php foreach ($regions as $key => $r): ?>
                  <option value="<?= $key ?>"><?= htmlspecialchars($r['label']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-row cols-2">
              <div class="form-group">
                <label class="form-label" for="reg-pass">Contraseña</label>
                <input class="form-control" type="password" name="contrasena" id="reg-pass"
                       placeholder="Mín. 6 caracteres" required autocomplete="new-password">
              </div>
              <div class="form-group">
                <label class="form-label" for="reg-confirm">Confirmar</label>
                <input class="form-control" type="password" name="confirmar" id="reg-confirm"
                       placeholder="Repite la contraseña" required autocomplete="new-password">
              </div>
            </div>
            <button type="submit" class="btn btn-magenta btn-full" style="border-width:2px">
              ➕ Crear Cuenta
            </button>
          </form>
        </div>

        <!-- ── Admin Tab ── -->
        <div class="tab-panel" data-panel="admin">
          <div style="text-align:center;margin-bottom:20px">
            <span style="font-size:2.5rem;filter:drop-shadow(0 0 12px var(--neon-green))">🛡</span>
            <p style="font-family:var(--font-heading);color:var(--neon-green);letter-spacing:2px;font-size:0.85rem;margin-top:8px">
              ACCESO ADMINISTRATIVO
            </p>
          </div>
          <form id="form-admin" novalidate>
            <div class="form-group">
              <label class="form-label" for="admin-nombre">Nombre completo</label>
              <input class="form-control" type="text" name="nombre" id="admin-nombre"
                     placeholder="Nombre del administrador" required autocomplete="name">
            </div>
            <div class="form-group">
              <label class="form-label" for="admin-pass">Contraseña</label>
              <input class="form-control" type="password" name="contrasena" id="admin-pass"
                     placeholder="••••••••" required autocomplete="current-password">
            </div>
            <button type="submit" class="btn btn-green-solid btn-full">
              🛡 Acceder como Admin
            </button>
          </form>
        </div>
      </div><!-- end #auth-tabs -->

    </div><!-- end .login-card -->
  </div><!-- end .login-wrapper -->

  <script src="/diagnosticos-de-equipo/js/main.js?v=<?= (int)@filemtime(__DIR__ . '/js/main.js') ?>"></script>
</body>
</html>
