<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/db.php';
requireUser();

$user    = getCurrentUser();
$regions = getRegions();
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="<?= htmlspecialchars(csrfToken()) ?>">
  <title>Nueva Solicitud — Diagnósticos de Equipo</title>
  <meta name="description" content="Formulario de diagnóstico de equipo tecnológico">
  <link rel="stylesheet" href="/diagnosticos-de-equipo/css/neon.css?v=<?= (int)@filemtime(__DIR__ . '/css/neon.css') ?>">
</head>
<body data-page="solicitud">
  <canvas id="particles-canvas"></canvas>

  <!-- Navbar -->
  <nav class="navbar relative">
    <a class="navbar-brand" href="/diagnosticos-de-equipo/solicitud.php">
      DIAG<span>NÓSTICOS</span>
    </a>
    <div class="navbar-right">
      <div class="user-badge">
        <span>👤 <?= htmlspecialchars($user['nombre'] . ' ' . $user['apellido']) ?></span>
        <span class="region-tag"><?= htmlspecialchars($user['region_label']) ?></span>
      </div>
      <a href="/diagnosticos-de-equipo/logout.php" class="btn btn-red btn-sm">⏻ Salir</a>
    </div>
  </nav>

  <!-- Page Content -->
  <main class="page-content relative">
    <div class="container">

      <!-- Page Header -->
      <div style="margin-bottom:32px">
        <h1 class="neon-title">Nueva Solicitud</h1>
        <p class="neon-subtitle" style="margin-top:4px">Diagnóstico de equipo tecnológico</p>
      </div>

      <form id="form-solicitud" novalidate>

        <!-- Section 1: Datos del solicitante -->
        <div class="glass-card form-card" style="margin-bottom:24px">
          <div class="section-header">
            <div class="section-icon">👤</div>
            <div>
              <h2 style="font-size:1rem;color:var(--neon-cyan);letter-spacing:1px">Datos del Solicitante</h2>
              <p style="font-size:0.8rem;color:var(--text-dim)">Información del responsable de la solicitud</p>
            </div>
          </div>

          <div class="form-row cols-2" style="margin-bottom:16px">
            <div class="form-group">
              <label class="form-label" for="nombre_solicitante">Nombre del Solicitante *</label>
              <input class="form-control" type="text" name="nombre_solicitante" id="nombre_solicitante"
                     value="<?= htmlspecialchars($user['nombre'] . ' ' . $user['apellido']) ?>"
                     placeholder="Nombre completo" required>
            </div>
            <div class="form-group">
              <label class="form-label" for="centro_trabajo">Centro de Trabajo *</label>
              <input class="form-control" type="text" name="centro_trabajo" id="centro_trabajo"
                     placeholder="Ej. Ayuntamiento de Colima" required>
            </div>
          </div>

          <div class="form-row cols-2">
            <div class="form-group">
              <label class="form-label" for="region_label">Región / Municipio</label>
              <input class="form-control" type="text" id="region_label"
                     value="<?= htmlspecialchars($user['region_label']) ?>" readonly disabled>
            </div>
            <div class="form-group">
              <label class="form-label" for="lugar">Lugar</label>
              <input class="form-control" type="text" name="lugar" id="lugar"
                     placeholder="Ciudad o municipio">
            </div>
          </div>
        </div>

        <!-- Section 2: Datos del equipo -->
        <div class="glass-card form-card" style="margin-bottom:24px">
          <div class="section-header">
            <div class="section-icon">🖥</div>
            <div>
              <h2 style="font-size:1rem;color:var(--neon-cyan);letter-spacing:1px">Datos del Equipo</h2>
              <p style="font-size:0.8rem;color:var(--text-dim)">Especificaciones del equipo a diagnosticar</p>
            </div>
          </div>

          <div class="form-row cols-2" style="margin-bottom:16px">
            <div class="form-group">
              <label class="form-label" for="tipo_equipo">Tipo de Equipo *</label>
              <select class="form-control" name="tipo_equipo" id="tipo_equipo" required>
                <option value="">— Selecciona tipo —</option>
                <option>Computadora de escritorio</option>
                <option>Laptop / Notebook</option>
                <option>Impresora</option>
                <option>Escáner</option>
                <option>Monitor</option>
                <option>Proyector</option>
                <option>Servidor</option>
                <option>Switch / Router</option>
                <option>Tablet</option>
                <option>Otro</option>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label" for="marca">Marca *</label>
              <input class="form-control" type="text" name="marca" id="marca"
                     placeholder="Ej. HP, Dell, Lenovo" required>
            </div>
          </div>

          <div class="form-row cols-3" style="margin-bottom:16px">
            <div class="form-group">
              <label class="form-label" for="modelo">Modelo *</label>
              <input class="form-control" type="text" name="modelo" id="modelo"
                     placeholder="Ej. ProBook 450" required>
            </div>
            <div class="form-group">
              <label class="form-label" for="numero_serie">Número de Serie</label>
              <input class="form-control" type="text" name="numero_serie" id="numero_serie"
                     placeholder="SN12345678">
            </div>
            <div class="form-group">
              <label class="form-label" for="numero_inventario">Número de Inventario</label>
              <input class="form-control" type="text" name="numero_inventario" id="numero_inventario"
                     placeholder="INV-00001">
            </div>
          </div>

          <div class="form-row cols-2">
            <div class="form-group">
              <label class="form-label" for="fecha">Fecha *</label>
              <input class="form-control" type="date" name="fecha" id="fecha"
                     value="<?= date('Y-m-d') ?>" required>
            </div>
          </div>
        </div>

        <!-- Section: Fotografía del equipo (obligatoria) -->
        <div class="glass-card form-card" style="margin-bottom:24px">
          <div class="section-header">
            <div class="section-icon">📷</div>
            <div>
              <h2 style="font-size:1rem;color:var(--neon-cyan);letter-spacing:1px">Fotografía del Equipo</h2>
              <p style="font-size:0.8rem;color:var(--text-dim)">Sube una foto clara del aparato que se va a diagnosticar. Es obligatoria.</p>
            </div>
          </div>

          <div class="form-group" style="margin-bottom:0">
            <label class="form-label" for="imagen">Imagen del equipo *</label>
            <input class="form-control file-input" type="file" name="imagen" id="imagen"
                   accept="image/jpeg,image/png,image/webp" required>
            <div class="img-hint">JPG, PNG o WebP. La imagen se reduce automáticamente antes de enviarse.</div>
            <div class="img-preview" id="img-preview" hidden>
              <img id="img-preview-img" alt="Vista previa de la fotografía del equipo">
              <span id="img-preview-info"></span>
            </div>
          </div>
        </div>

        <!-- Section 3: Diagnóstico -->
        <div class="glass-card form-card" style="margin-bottom:32px">
          <div class="section-header">
            <div class="section-icon">🔍</div>
            <div>
              <h2 style="font-size:1rem;color:var(--neon-cyan);letter-spacing:1px">Descripción del Problema</h2>
              <p style="font-size:0.8rem;color:var(--text-dim)">Describe la falla o situación del equipo. Solo el administrador podrá ver esta descripción.</p>
            </div>
          </div>

          <div class="form-group" style="margin-bottom:0">
            <label class="form-label" for="diagnostico">Descripción del problema</label>
            <textarea class="form-control" name="diagnostico" id="diagnostico"
                      rows="6"
                      placeholder="Describe detalladamente las fallas observadas, desde cuándo ocurren, pruebas realizadas..."></textarea>
          </div>
        </div>

        <!-- Submit -->
        <div style="display:flex;justify-content:flex-end;gap:16px;padding-bottom:40px">
          <button type="reset" class="btn btn-cyan">🔄 Limpiar</button>
          <button type="submit" class="btn btn-cyan-solid" id="btn-submit">
            📤 Enviar Solicitud
          </button>
        </div>

      </form>

      <!-- Mis solicitudes -->
      <div class="glass-card" style="padding:28px;margin-bottom:40px">
        <div class="section-header">
          <div class="section-icon">📋</div>
          <div>
            <h2 style="font-size:1rem;color:var(--neon-cyan);letter-spacing:1px">Mis Solicitudes</h2>
            <p style="font-size:0.8rem;color:var(--text-dim)">Seguimiento del estado de tus diagnósticos</p>
          </div>
        </div>
        <div id="mis-solicitudes"><div class="spinner"></div></div>
      </div>
    </div>
  </main>

  <script src="/diagnosticos-de-equipo/js/main.js?v=<?= (int)@filemtime(__DIR__ . '/js/main.js') ?>"></script>
</body>
</html>
