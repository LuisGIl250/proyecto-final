<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/db.php';
requireAdmin();

$admin   = getCurrentAdmin();
$regions = getRegions();
$firstRegion = array_key_first($regions);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="<?= htmlspecialchars(csrfToken()) ?>">
  <title>Panel de Administrador — Diagnósticos de Equipo</title>
  <meta name="description" content="Panel administrativo del sistema de diagnóstico de equipo tecnológico">
  <link rel="stylesheet" href="/diagnosticos-de-equipo/css/neon.css?v=<?= (int)@filemtime(__DIR__ . '/css/neon.css') ?>">
  <style>
    .admin-badge {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 4px 14px;
      background: rgba(118,255,3,0.1);
      border: 1px solid rgba(118,255,3,0.3);
      border-radius: 50px;
      font-family: var(--font-heading);
      font-size: 0.75rem;
      color: var(--neon-green);
      letter-spacing: 2px;
      text-transform: uppercase;
    }
    .admin-name {
      font-family: var(--font-heading);
      font-size: 0.9rem;
      color: var(--neon-magenta);
      letter-spacing: 1px;
    }
  </style>
</head>
<body data-page="admin">
  <canvas id="particles-canvas"></canvas>

  <!-- Navbar -->
  <nav class="navbar relative">
    <a class="navbar-brand" href="/diagnosticos-de-equipo/admin.php">
      DIAG<span>NÓSTICOS</span>
    </a>
    <div class="navbar-right">
      <span class="admin-badge">🛡 Administrador</span>
      <span class="admin-name"><?= htmlspecialchars($admin['nombre']) ?></span>
      <a href="/diagnosticos-de-equipo/logout.php" class="btn btn-red btn-sm">⏻ Salir</a>
    </div>
  </nav>

  <!-- Admin Control Strip -->
  <div class="admin-top relative">
    <div>
      <p class="admin-label" style="margin-bottom:4px">Panel de Administración</p>
      <h1 class="neon-title" style="font-size:1.2rem">Gestión de Solicitudes</h1>
    </div>

    <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap">
      <label class="admin-label" for="admin-region-select">Región:</label>
      <div class="region-select-wrapper">
        <select class="form-control" id="admin-region-select" name="region"
                style="border-color:rgba(213,0,249,0.4);min-width:220px">
          <?php foreach ($regions as $key => $r): ?>
            <option value="<?= $key ?>"
              <?= $key === $firstRegion ? 'selected' : '' ?>>
              <?= htmlspecialchars($r['label']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
  </div>

  <!-- Page Content -->
  <main class="page-content relative">
    <div class="container">

      <!-- Stats -->
      <div class="stats-row">
        <div class="stat-card stat-pending">
          <div class="stat-num" id="count-pending">—</div>
          <div class="stat-label">🕐 Pendientes</div>
        </div>
        <div class="stat-card stat-active">
          <div class="stat-num" id="count-active">—</div>
          <div class="stat-label">⚡ Activas</div>
        </div>
        <div class="stat-card stat-done">
          <div class="stat-num" id="count-done">—</div>
          <div class="stat-label">✓ Completadas</div>
        </div>
      </div>

      <!-- Solicitudes Container -->
      <div class="glass-card" style="padding:28px">
        <div id="solicitudes-container">
          <div class="spinner"></div>
        </div>
      </div>

    </div>
  </main>

  <script src="/diagnosticos-de-equipo/js/main.js?v=<?= (int)@filemtime(__DIR__ . '/js/main.js') ?>"></script>
</body>
</html>
