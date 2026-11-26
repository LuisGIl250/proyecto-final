/* ═══════════════════════════════════════════
   DIAGNÓSTICOS DE EQUIPO — MAIN JS
   ═══════════════════════════════════════════ */

// ── Particle Canvas ───────────────────────
(function initParticles() {
  const canvas = document.getElementById('particles-canvas');
  if (!canvas) return;

  const ctx = canvas.getContext('2d');
  let W = window.innerWidth, H = window.innerHeight;
  canvas.width = W; canvas.height = H;

  const NUM = 55;
  const particles = Array.from({ length: NUM }, () => ({
    x: Math.random() * W,
    y: Math.random() * H,
    r: Math.random() * 1.5 + 0.3,
    vx: (Math.random() - 0.5) * 0.4,
    vy: (Math.random() - 0.5) * 0.4,
    alpha: Math.random() * 0.5 + 0.1,
    color: Math.random() > 0.5 ? '#00e5ff' : '#d500f9',
  }));

  function draw() {
    ctx.clearRect(0, 0, W, H);

    // Draw connection lines
    for (let i = 0; i < particles.length; i++) {
      for (let j = i + 1; j < particles.length; j++) {
        const dx = particles[i].x - particles[j].x;
        const dy = particles[i].y - particles[j].y;
        const dist = Math.sqrt(dx * dx + dy * dy);
        if (dist < 140) {
          ctx.beginPath();
          ctx.moveTo(particles[i].x, particles[i].y);
          ctx.lineTo(particles[j].x, particles[j].y);
          ctx.strokeStyle = `rgba(0,229,255,${0.07 * (1 - dist / 140)})`;
          ctx.lineWidth = 0.5;
          ctx.stroke();
        }
      }
    }

    // Draw particles
    particles.forEach(p => {
      ctx.beginPath();
      ctx.arc(p.x, p.y, p.r, 0, Math.PI * 2);
      ctx.globalAlpha = p.alpha;
      ctx.fillStyle = p.color;
      ctx.shadowColor = p.color;
      ctx.shadowBlur = 6;
      ctx.fill();
      ctx.globalAlpha = 1;
      ctx.shadowBlur = 0;

      p.x += p.vx; p.y += p.vy;
      if (p.x < 0 || p.x > W) p.vx *= -1;
      if (p.y < 0 || p.y > H) p.vy *= -1;
    });

    requestAnimationFrame(draw);
  }

  draw();

  window.addEventListener('resize', () => {
    W = window.innerWidth; H = window.innerHeight;
    canvas.width = W; canvas.height = H;
  });
})();

// ── Toast Notifications ───────────────────
const ToastManager = (() => {
  let container = null;

  function getContainer() {
    if (!container) {
      container = document.createElement('div');
      container.className = 'toast-container';
      document.body.appendChild(container);
    }
    return container;
  }

  function show(message, type = 'info', duration = 3000) {
    const c = getContainer();
    const icons = { success: '✓', error: '✗', info: 'ℹ' };
    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    const icon = document.createElement('span');
    icon.textContent = icons[type] || 'ℹ';
    const text = document.createElement('span');
    text.textContent = message;
    toast.append(icon, text);
    c.appendChild(toast);
    setTimeout(() => toast.remove(), duration + 300);
  }

  return { show, success: m => show(m, 'success'), error: m => show(m, 'error'), info: m => show(m, 'info') };
})();

// ── Tab System ────────────────────────────
function initTabs(containerId) {
  const container = document.getElementById(containerId);
  if (!container) return;

  const buttons = container.querySelectorAll('.tab-btn');
  const panels  = container.querySelectorAll('.tab-panel');

  buttons.forEach(btn => {
    btn.addEventListener('click', () => {
      const target = btn.dataset.tab;
      buttons.forEach(b => b.classList.remove('active'));
      panels.forEach(p => p.classList.remove('active'));
      btn.classList.add('active');
      const panel = container.querySelector(`[data-panel="${target}"]`);
      if (panel) panel.classList.add('active');
    });
  });
}

// ── Form Helpers ──────────────────────────
function serializeForm(form) {
  const data = {};
  new FormData(form).forEach((val, key) => { data[key] = val; });
  return data;
}

function csrfToken() {
  const m = document.querySelector('meta[name="csrf-token"]');
  return m ? m.content : '';
}

async function apiPost(url, data) {
  const res  = await fetch(url, {
    method: 'POST',
    credentials: 'same-origin',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': csrfToken(),
    },
    body: JSON.stringify(data),
  });
  return res.json();
}

// Envío multipart (formularios con archivo): el navegador pone el Content-Type con su boundary
async function apiPostForm(url, formData) {
  const res = await fetch(url, {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'X-CSRF-Token': csrfToken() },
    body: formData,
  });
  return res.json();
}

// ── Fotografía del equipo ─────────────────
const IMG_MAX_SIDE = 1600;     // lado mayor tras reducir (px)
const IMG_QUALITY  = 0.85;     // calidad JPEG
const IMG_TARGET_BYTES = 1.5 * 1024 * 1024;   // peso objetivo de la imagen que se envía
const IMG_MAX_FILE = 15 * 1024 * 1024;   // límite razonable del archivo original antes de reducirlo

// 48 KB → "48 KB", 2 621 440 → "2.5 MB" (antes un archivo de 30 KB se mostraba como "0.0 MB")
function formatBytes(n) {
  return n < 1048576 ? `${Math.max(1, Math.round(n / 1024))} KB` : `${(n / 1048576).toFixed(1)} MB`;
}

function equipoImageUrl(id, region) {
  return `/diagnosticos-de-equipo/api/equipo_imagen.php?id=${Number(id)}` +
         (region ? `&region=${encodeURIComponent(region)}` : '');
}

function loadImageElement(file) {
  return new Promise((resolve, reject) => {
    const url = URL.createObjectURL(file);
    const img = new Image();
    img.onload  = () => { URL.revokeObjectURL(url); resolve(img); };
    img.onerror = () => { URL.revokeObjectURL(url); reject(new Error('No se pudo leer la imagen')); };
    img.src = url;
  });
}

// Dibuja la imagen en un canvas de lado mayor $side y la exporta como JPEG
function drawToBlob(img, side, quality) {
  const scale = Math.min(1, side / Math.max(img.naturalWidth, img.naturalHeight));
  const w = Math.max(1, Math.round(img.naturalWidth  * scale));
  const h = Math.max(1, Math.round(img.naturalHeight * scale));
  const canvas = document.createElement('canvas');
  canvas.width = w;
  canvas.height = h;
  const ctx = canvas.getContext('2d');
  ctx.fillStyle = '#fff';            // fondo blanco para PNG/WebP con transparencia
  ctx.fillRect(0, 0, w, h);
  ctx.drawImage(img, 0, 0, w, h);
  return new Promise(resolve => canvas.toBlob(resolve, 'image/jpeg', quality));
}

// Reduce la foto y la convierte a JPEG. Si aun así pesa más de IMG_TARGET_BYTES (por debajo del
// upload_max_filesize de 2 MB que trae PHP por defecto), la vuelve a comprimir con menos calidad y tamaño.
async function compressImage(file) {
  const img = await loadImageElement(file);
  let side = IMG_MAX_SIDE, quality = IMG_QUALITY, blob = null;
  for (let attempt = 0; attempt < 5; attempt++) {
    blob = await drawToBlob(img, side, quality);
    if (!blob || blob.size === 0) throw new Error('No se pudo procesar la imagen');
    if (blob.size <= IMG_TARGET_BYTES) break;
    side    = Math.max(800, Math.round(side * 0.85));
    quality = Math.max(0.6, quality - 0.08);
  }
  return blob;
}

// ── Button loading state ──────────────────
function setLoading(btn, loading) {
  if (loading) {
    btn.dataset.originalText = btn.innerHTML;
    btn.innerHTML = '<span class="spinner" style="width:18px;height:18px;border-width:2px;margin:0"></span>';
    btn.disabled = true;
  } else {
    btn.innerHTML = btn.dataset.originalText || btn.innerHTML;
    btn.disabled = false;
  }
}

// ── Modales genéricos ─────────────────────
// Un modal por id; se cierra con Escape, con clic fuera de la caja o con closeModal(id).
function openModal(id, html) {
  closeModal(id);
  const overlay = document.createElement('div');
  overlay.className = 'modal-overlay';
  overlay.id = id;
  overlay.innerHTML = `<div class="glass-card modal-box" role="dialog" aria-modal="true">${html}</div>`;
  document.body.appendChild(overlay);
  overlay._esc = e => { if (e.key === 'Escape') closeModal(id); };
  document.addEventListener('keydown', overlay._esc);
  overlay.addEventListener('mousedown', e => { if (e.target === overlay) closeModal(id); });
  return overlay;
}

function closeModal(id) {
  const m = document.getElementById(id);
  if (!m) return;
  document.removeEventListener('keydown', m._esc);
  m.remove();
}

// ── Login Page ────────────────────────────
function initLoginPage() {
  initTabs('auth-tabs');

  if (new URLSearchParams(location.search).get('error') === 'session') {
    ToastManager.info('Tu sesión expiró o no está activa. Inicia sesión de nuevo.');
  }

  // Open specific tab from URL hash
  const hash = window.location.hash.replace('#', '');
  if (hash) {
    const btn = document.querySelector(`[data-tab="${hash}"]`);
    if (btn) btn.click();
  }

  // User login
  const loginForm = document.getElementById('form-login');
  if (loginForm) {
    loginForm.addEventListener('submit', async e => {
      e.preventDefault();
      const btn = loginForm.querySelector('button[type=submit]');
      setLoading(btn, true);
      try {
        const data = serializeForm(loginForm);
        const res  = await apiPost('/diagnosticos-de-equipo/api/login.php', data);
        if (res.success) {
          ToastManager.success('¡Bienvenido! Redirigiendo...');
          setTimeout(() => location.href = res.redirect, 800);
        } else {
          ToastManager.error(res.error || 'Credenciales incorrectas');
        }
      } catch { ToastManager.error('Error de conexión'); }
      finally { setLoading(btn, false); }
    });
  }

  // User register
  const regForm = document.getElementById('form-register');
  if (regForm) {
    regForm.addEventListener('submit', async e => {
      e.preventDefault();
      const btn = regForm.querySelector('button[type=submit]');

      const pass    = regForm.querySelector('[name=contrasena]').value;
      const confirm = regForm.querySelector('[name=confirmar]').value;
      if (pass !== confirm) { ToastManager.error('Las contraseñas no coinciden'); return; }
      if (pass.length < 6)  { ToastManager.error('La contraseña debe tener al menos 6 caracteres'); return; }

      setLoading(btn, true);
      try {
        const data = serializeForm(regForm);
        const res  = await apiPost('/diagnosticos-de-equipo/api/register.php', data);
        if (res.success) {
          ToastManager.success('¡Cuenta creada! Iniciando sesión...');
          setTimeout(() => location.href = res.redirect, 800);
        } else {
          ToastManager.error(res.error || 'Error al registrar');
        }
      } catch { ToastManager.error('Error de conexión'); }
      finally { setLoading(btn, false); }
    });
  }

  // Admin login
  const adminForm = document.getElementById('form-admin');
  if (adminForm) {
    adminForm.addEventListener('submit', async e => {
      e.preventDefault();
      const btn = adminForm.querySelector('button[type=submit]');
      setLoading(btn, true);
      try {
        const data = serializeForm(adminForm);
        const res  = await apiPost('/diagnosticos-de-equipo/api/admin_login.php', data);
        if (res.success) {
          ToastManager.success('Acceso de administrador concedido...');
          setTimeout(() => location.href = res.redirect, 800);
        } else {
          ToastManager.error(res.error || 'Credenciales de admin incorrectas');
        }
      } catch { ToastManager.error('Error de conexión'); }
      finally { setLoading(btn, false); }
    });
  }
}

// ── Solicitud Page ────────────────────────
let mySolicitudes = [];      // solicitudes del usuario (para los modales de firma y de cambios)
let myUserName = '';         // nombre de la cuenta: es el que aparece en su firma
const CAMBIO_MSG_MAX = 1000;
const CAMBIO_RESP_MAX = 500;

function renderCambios(cambios, forAdmin, solicitudId) {
  if (!cambios || !cambios.length) return '';
  const items = cambios.map(c => {
    const open = c.estado === 'pendiente';
    const reply = !open
      ? `<div class="cambio-reply">
           <span class="meta-label">Respuesta${c.atendido_por ? ' · ' + escHtml(c.atendido_por) : ''}${c.atendido_at ? ' · ' + formatDate(c.atendido_at) : ''}</span>
           <p>${c.respuesta ? escHtml(c.respuesta) : 'Atendido sin comentarios.'}</p>
         </div>`
      : '';
    const action = (open && forAdmin)
      ? `<button class="btn btn-green btn-sm" style="margin-top:10px" onclick="openResolveChangeModal(${Number(solicitudId)}, ${Number(c.id)})">✓ Atender</button>`
      : '';
    return `
      <div class="cambio-item ${open ? 'cambio-open' : 'cambio-done'}">
        <div class="cambio-head">
          <span class="cambio-state">${open ? 'En revisión' : 'Atendido'}</span>
          <span class="cambio-date">${formatDate(c.created_at)}</span>
        </div>
        <p>${escHtml(c.mensaje)}</p>
        ${reply}
        ${action}
      </div>`;
  }).join('');
  return `<div class="cambios-list"><span class="meta-label">${forAdmin ? 'Cambios solicitados por el usuario' : 'Cambios solicitados'}</span>${items}</div>`;
}

function renderMyCard(s) {
  const cardClass  = s.estado === 'activa' ? 'card-active' : s.estado === 'completada' ? 'card-done' : '';
  const badgeClass = s.estado === 'pendiente' ? 'status-pending' : s.estado === 'activa' ? 'status-active' : 'status-done';
  const badgeLabel = s.estado === 'pendiente' ? 'Pendiente' : s.estado === 'activa' ? 'En proceso' : 'Completada';

  const cambios     = s.cambios || [];
  const hayAbierto  = cambios.some(c => c.estado === 'pendiente');
  const hasDiag     = s.estado === 'completada' && !!(s.diagnostico_admin && s.diagnostico_admin.trim());
  const firmado     = !!s.firma_usuario_code;

  // Diagnóstico del administrador: aparece cuando el especialista completa la solicitud
  const diagBlock = hasDiag
    ? `<div class="diag-block diag-admin">
         <span class="meta-label">Diagnóstico del especialista${s.completada_por ? ' · ' + escHtml(s.completada_por) : ''}</span>
         <p>${escHtml(s.diagnostico_admin)}</p>
       </div>`
    : `<p class="diag-wait">El diagnóstico del especialista aparecerá aquí cuando complete tu solicitud.</p>`;

  // Firmas (del especialista y la tuya)
  const firmasBlock = hasDiag ? `
    <div class="firma-grid">
      <div class="firma-box">
        <span class="meta-label">Firma del especialista</span>
        ${s.firma_admin_code
          ? `<span class="firma-name">${escHtml(s.completada_por || '')}</span>
             <span class="firma-code">${escHtml(s.firma_admin_code)}</span>
             <span class="firma-date">${formatDateTime(s.firma_admin_at)}</span>`
          : `<span class="firma-pending">Sin firma registrada</span>`}
      </div>
      <div class="firma-box ${firmado ? 'firma-signed' : ''}">
        <span class="meta-label">Tu firma</span>
        ${firmado
          ? `<span class="firma-name">${escHtml(s.firma_usuario_nombre || myUserName)}</span>
             <span class="firma-code">${escHtml(s.firma_usuario_code)}</span>
             <span class="firma-date">${formatDateTime(s.firma_usuario_at)}</span>`
          : `<span class="firma-pending">Pendiente: firma de conformidad</span>`}
      </div>
    </div>` : '';

  // Fotografía del equipo (miniatura; al hacer clic se abre completa)
  const imgBlock = s.tiene_imagen
    ? `<a class="equipo-thumb" href="${equipoImageUrl(s.id)}" target="_blank" rel="noopener" title="Ver fotografía completa">
         <img loading="lazy" alt="Fotografía del equipo" src="${equipoImageUrl(s.id)}">
       </a>`
    : '';

  // El PDF existe cuando la solicitud está completada y tiene diagnóstico del especialista
  const pdfBtn = hasDiag
    ? `<a class="btn btn-magenta btn-sm" href="/diagnosticos-de-equipo/export_pdf.php?id=${Number(s.id)}" target="_blank">📄 Descargar PDF</a>`
    : '';

  const signBtn = (hasDiag && !firmado)
    ? `<button class="btn btn-green btn-sm" onclick="openSignModal(${Number(s.id)})">✍ Firmar de conformidad</button>`
    : '';
  const changeBtn = hayAbierto
    ? `<span class="badge status-change">Cambio en revisión</span>`
    : `<button class="btn btn-cyan btn-sm" onclick="openChangeModal(${Number(s.id)})">✎ Solicitar cambios</button>`;

  return `
    <div class="solicitud-card ${cardClass}">
      <div class="card-header">
        <span class="card-title">${escHtml(s.tipo_equipo)} — ${escHtml(s.marca)} ${escHtml(s.modelo)}</span>
        <span class="badge ${badgeClass}">${badgeLabel}</span>
      </div>
      <div class="card-meta">
        <div class="meta-item"><span class="meta-label">Centro de trabajo</span><span class="meta-value">${escHtml(s.centro_trabajo)}</span></div>
        <div class="meta-item"><span class="meta-label">No. Serie</span><span class="meta-value">${escHtml(s.numero_serie || '—')}</span></div>
        <div class="meta-item"><span class="meta-label">No. Inventario</span><span class="meta-value">${escHtml(s.numero_inventario || '—')}</span></div>
        <div class="meta-item"><span class="meta-label">Fecha</span><span class="meta-value">${formatDate(s.fecha || s.created_at)}</span></div>
      </div>
      ${imgBlock}
      ${diagBlock}
      ${firmasBlock}
      ${renderCambios(cambios, false, s.id)}
      <div class="card-actions">
        ${signBtn}
        ${pdfBtn}
        ${changeBtn}
      </div>
    </div>`;
}

let lastMySignature = '';    // contenido de la última lista dibujada; si no cambia, no se redibuja

async function loadMySolicitudes() {
  const box = document.getElementById('mis-solicitudes');
  if (!box) return;
  try {
    const res  = await fetch('/diagnosticos-de-equipo/api/my_solicitudes.php', { credentials: 'same-origin' });
    const data = await res.json();
    if (!data.success) {
      lastMySignature = '';
      box.innerHTML = `<p style="color:var(--neon-red);text-align:center">${escHtml(data.error || 'Error al cargar')}</p>`;
      return;
    }
    mySolicitudes = data.solicitudes;
    myUserName    = (data.usuario && data.usuario.nombre) || '';
    const signature = JSON.stringify(data);
    if (signature === lastMySignature) return;
    lastMySignature = signature;
    if (!data.solicitudes.length) {
      box.innerHTML = `<div class="empty-state"><span class="empty-icon">📋</span><p>Aún no has enviado solicitudes</p></div>`;
      return;
    }
    box.innerHTML = data.solicitudes.map(renderMyCard).join('');
  } catch {
    lastMySignature = '';
    box.innerHTML = '<p style="color:var(--neon-red);text-align:center">Error al cargar tus solicitudes</p>';
  }
}

// ── Modal: el usuario firma de conformidad ─
function openSignModal(id) {
  const s = mySolicitudes.find(x => Number(x.id) === Number(id));
  if (!s) return;

  const overlay = openModal('sign-modal', `
    <h2 class="modal-title" id="sign-modal-title">✍ Firmar de conformidad</h2>
    <p class="modal-sub">${escHtml(s.tipo_equipo)} — ${escHtml(s.marca)} ${escHtml(s.modelo)}</p>
    <div class="diag-block diag-admin" style="margin-top:0">
      <span class="meta-label">Diagnóstico del especialista</span>
      <p>${escHtml(s.diagnostico_admin)}</p>
    </div>
    <p class="sign-note">
      Al firmar confirmas que recibiste el diagnóstico y el equipo. Tu firma se genera con los datos de tu cuenta
      y aparecerá en el PDF junto a tu nombre: <strong>${escHtml(myUserName)}</strong>.
    </p>
    <div class="modal-actions">
      <button type="button" class="btn btn-red btn-sm" id="sign-cancel">Cancelar</button>
      <button type="button" class="btn btn-green-solid" id="sign-confirm">✍ Firmar</button>
    </div>`);

  overlay.querySelector('#sign-cancel').addEventListener('click', () => closeModal('sign-modal'));
  const btn = overlay.querySelector('#sign-confirm');
  btn.addEventListener('click', async () => {
    setLoading(btn, true);
    try {
      const res = await apiPost('/diagnosticos-de-equipo/api/sign_solicitud.php', { id: s.id });
      if (res.success) {
        closeModal('sign-modal');
        ToastManager.success('¡Solicitud firmada!');
        loadMySolicitudes();
      } else {
        ToastManager.error(res.error || 'No se pudo firmar');
        setLoading(btn, false);
        if (res.error && /ya está firmada/i.test(res.error)) { closeModal('sign-modal'); loadMySolicitudes(); }
      }
    } catch {
      ToastManager.error('Error de conexión');
      setLoading(btn, false);
    }
  });
}

// ── Modal: el usuario solicita un cambio ───
function openChangeModal(id) {
  const s = mySolicitudes.find(x => Number(x.id) === Number(id));
  if (!s) return;

  const overlay = openModal('change-modal', `
    <h2 class="modal-title" style="color:var(--neon-cyan)">✎ Solicitar cambios</h2>
    <p class="modal-sub">${escHtml(s.tipo_equipo)} — ${escHtml(s.marca)} ${escHtml(s.modelo)}</p>
    <div class="form-group" style="margin-bottom:0">
      <label class="form-label" for="cambio-text">¿Qué necesitas cambiar? *</label>
      <textarea class="form-control" id="cambio-text" rows="6" maxlength="${CAMBIO_MSG_MAX}"
                placeholder="Ej. El número de serie está mal, es SN12345678. / Pido que se revise de nuevo el diagnóstico porque..."></textarea>
      <div class="char-count"><span id="cambio-count">0</span> / ${CAMBIO_MSG_MAX} · el administrador lo verá en su panel</div>
    </div>
    <div class="modal-actions">
      <button type="button" class="btn btn-red btn-sm" id="cambio-cancel">Cancelar</button>
      <button type="button" class="btn btn-cyan-solid" id="cambio-send">📤 Enviar solicitud</button>
    </div>`);

  const ta    = overlay.querySelector('#cambio-text');
  const count = overlay.querySelector('#cambio-count');
  ta.addEventListener('input', () => { count.textContent = ta.value.length; });
  ta.focus();

  overlay.querySelector('#cambio-cancel').addEventListener('click', () => closeModal('change-modal'));
  const btn = overlay.querySelector('#cambio-send');
  btn.addEventListener('click', async () => {
    const mensaje = ta.value.trim();
    if (!mensaje) {
      ToastManager.error('Describe el cambio que necesitas');
      ta.focus();
      return;
    }
    setLoading(btn, true);
    try {
      const res = await apiPost('/diagnosticos-de-equipo/api/request_change.php', { id: s.id, mensaje });
      if (res.success) {
        closeModal('change-modal');
        ToastManager.success('Solicitud de cambio enviada');
        loadMySolicitudes();
      } else {
        ToastManager.error(res.error || 'No se pudo enviar');
        setLoading(btn, false);
      }
    } catch {
      ToastManager.error('Error de conexión');
      setLoading(btn, false);
    }
  });
}

function initSolicitudPage() {
  const form = document.getElementById('form-solicitud');
  if (!form) return;

  loadMySolicitudes();

  // La lista se actualiza sola (cada 30 s y al volver a la pestaña) para que el usuario vea el diagnóstico,
  // las firmas y el botón de PDF en cuanto el administrador complete su solicitud, sin recargar la página.
  const refreshList = () => {
    if (!document.hidden && !document.querySelector('.modal-overlay')) loadMySolicitudes();
  };
  setInterval(refreshList, 30000);
  document.addEventListener('visibilitychange', refreshList);

  // Vista previa de la fotografía elegida
  const fileInput = form.querySelector('#imagen');
  const preview   = document.getElementById('img-preview');
  const previewEl = document.getElementById('img-preview-img');
  const infoEl    = document.getElementById('img-preview-info');
  let previewUrl  = null;

  function clearPreview() {
    if (previewUrl) { URL.revokeObjectURL(previewUrl); previewUrl = null; }
    previewEl.removeAttribute('src');
    infoEl.textContent = '';
    preview.hidden = true;
  }

  fileInput.addEventListener('change', () => {
    clearPreview();
    const file = fileInput.files[0];
    if (!file) return;
    if (!/^image\/(jpeg|png|webp)$/.test(file.type)) {
      ToastManager.error('La fotografía debe ser una imagen JPG, PNG o WebP');
      fileInput.value = '';
      return;
    }
    if (file.size === 0) {
      ToastManager.error('El archivo está vacío (0 bytes). Si está en iCloud, OneDrive o similar, descárgalo primero o elige otra imagen');
      fileInput.value = '';
      return;
    }
    if (file.size > IMG_MAX_FILE) {
      ToastManager.error('La imagen es demasiado pesada (máximo 15 MB)');
      fileInput.value = '';
      return;
    }
    previewUrl = URL.createObjectURL(file);
    previewEl.src = previewUrl;
    infoEl.textContent = `${file.name} · ${formatBytes(file.size)}`;
    preview.hidden = false;
  });
  form.addEventListener('reset', clearPreview);

  form.addEventListener('submit', async e => {
    e.preventDefault();

    const file = fileInput.files[0];
    if (!file) {
      ToastManager.error('Debes subir una fotografía del equipo');
      fileInput.focus();
      return;
    }

    const btn = form.querySelector('button[type=submit]');
    setLoading(btn, true);
    try {
      const fd = new FormData(form);
      fd.set('imagen', await compressImage(file), 'equipo.jpg');   // se envía ya reducida y en JPEG
      const res = await apiPostForm('/diagnosticos-de-equipo/api/save_solicitud.php', fd);
      if (res.success) {
        ToastManager.success('¡Solicitud enviada exitosamente!');
        setTimeout(() => form.reset(), 500);
        loadMySolicitudes();
      } else {
        ToastManager.error(res.error || 'Error al guardar la solicitud');
      }
    } catch (err) {
      ToastManager.error(err && err.message && /imagen/i.test(err.message) ? err.message : 'Error de conexión');
    }
    finally { setLoading(btn, false); }
  });
}

// ── Admin Page ────────────────────────────
let currentAdminRegion = '';
let currentSolicitudes = [];   // solicitudes de la región mostrada (para el modal de diagnóstico)

async function loadSolicitudes(region) {
  currentAdminRegion = region;
  const container = document.getElementById('solicitudes-container');
  if (!container) return;

  container.innerHTML = '<div class="spinner"></div>';

  try {
    const res  = await fetch(`/diagnosticos-de-equipo/api/get_solicitudes.php?region=${encodeURIComponent(region)}`);
    const data = await res.json();

    if (!data.success) {
      container.innerHTML = `<p style="color:var(--neon-red);text-align:center">${escHtml(data.error)}</p>`;
      return;
    }

    currentSolicitudes = data.solicitudes;

    // Update stats
    updateStats(data.stats);

    // Render tabs content
    renderSolicitudes(data.solicitudes);

  } catch (err) {
    container.innerHTML = '<p style="color:var(--neon-red);text-align:center">Error al cargar solicitudes</p>';
  }
}

function updateStats(stats) {
  const el = id => document.getElementById(id);
  if (el('count-pending'))  el('count-pending').textContent  = stats.pendiente  || 0;
  if (el('count-active'))   el('count-active').textContent   = stats.activa     || 0;
  if (el('count-done'))     el('count-done').textContent     = stats.completada || 0;
}

function renderSolicitudes(solicitudes) {
  const container = document.getElementById('solicitudes-container');

  const groups = { pendiente: [], activa: [], completada: [] };
  solicitudes.forEach(s => { if (groups[s.estado]) groups[s.estado].push(s); });

  container.innerHTML = `
    <div id="admin-tabs">
      <div class="tabs">
        <button class="tab-btn active" data-tab="pendientes">
          🕐 Pendientes <span style="color:var(--neon-yellow)">(${groups.pendiente.length})</span>
        </button>
        <button class="tab-btn tab-cyan" data-tab="activas" style="--active-color:var(--neon-cyan)">
          ⚡ Activas <span style="color:var(--neon-cyan)">(${groups.activa.length})</span>
        </button>
        <button class="tab-btn tab-green" data-tab="completadas">
          ✓ Completadas <span style="color:var(--neon-green)">(${groups.completada.length})</span>
        </button>
      </div>

      <div class="tab-panel active" data-panel="pendientes">
        ${renderCards(groups.pendiente, 'pendiente')}
      </div>
      <div class="tab-panel" data-panel="activas">
        ${renderCards(groups.activa, 'activa')}
      </div>
      <div class="tab-panel" data-panel="completadas">
        ${renderCards(groups.completada, 'completada')}
      </div>
    </div>
  `;

  initTabs('admin-tabs');
}

function renderCards(list, estado) {
  if (!list.length) {
    return `<div class="empty-state">
      <span class="empty-icon">📋</span>
      <p>No hay solicitudes ${estado === 'pendiente' ? 'pendientes' : estado === 'activa' ? 'activas' : 'completadas'}</p>
    </div>`;
  }

  return list.map(s => {
    const cardClass = estado === 'activa' ? 'card-active' : estado === 'completada' ? 'card-done' : '';
    const badgeClass = estado === 'pendiente' ? 'status-pending' : estado === 'activa' ? 'status-active' : 'status-done';
    const badgeLabel = estado === 'pendiente' ? 'Pendiente' : estado === 'activa' ? 'Activa' : 'Completada';

    const hasAdminDiag = !!(s.diagnostico_admin && s.diagnostico_admin.trim());

    // Botón principal según el estado
    const nextAction = estado === 'pendiente'
      ? `<button class="btn btn-cyan btn-sm" onclick="updateStatus(${s.id}, 'activa', this)">⚡ Activar</button>`
      : estado === 'activa'
      ? `<button class="btn btn-green btn-sm" onclick="openDiagnosticoModal(${s.id})">✓ Completar</button>`
      : `<button class="btn btn-cyan btn-sm" onclick="openDiagnosticoModal(${s.id})">✏ ${hasAdminDiag ? 'Editar' : 'Agregar'} diagnóstico</button>`;

    // El PDF solo existe cuando la solicitud está completada y tiene diagnóstico del administrador
    const pdfBtn = (estado === 'completada' && hasAdminDiag)
      ? `<a class="btn btn-magenta btn-sm" href="/diagnosticos-de-equipo/export_pdf.php?id=${s.id}&region=${encodeURIComponent(currentAdminRegion)}" target="_blank">📄 PDF</a>`
      : '';

    const userProblem = s.diagnostico
      ? `<div class="diag-block diag-user">
           <span class="meta-label">Problema reportado por el usuario</span>
           <p>${escHtml(s.diagnostico)}</p>
         </div>`
      : '';

    const adminDiag = hasAdminDiag
      ? `<div class="diag-block diag-admin">
           <span class="meta-label">Diagnóstico del administrador${s.completada_por ? ' · ' + escHtml(s.completada_por) : ''}</span>
           <p>${escHtml(s.diagnostico_admin)}</p>
         </div>`
      : (estado === 'completada'
          ? `<div class="diag-block diag-admin diag-missing"><span class="meta-label">Falta el diagnóstico del administrador (necesario para el PDF)</span></div>`
          : '');

    // Firmas de ambas partes (solo cuando la solicitud está completada)
    const firmasInfo = (estado === 'completada' && hasAdminDiag)
      ? `<div class="diag-block diag-firmas">
           <span class="meta-label">Firmas</span>
           <p><b>Especialista:</b> ${escHtml(s.completada_por || '—')}${s.firma_admin_code
              ? ` · <span class="firma-code-inline">${escHtml(s.firma_admin_code)}</span> · ${formatDateTime(s.firma_admin_at)}`
              : ' · <i>sin firma (edita y guarda el diagnóstico para firmar)</i>'}</p>
           <p><b>Usuario:</b> ${s.firma_usuario_code
              ? `${escHtml(s.firma_usuario_nombre || '')} · <span class="firma-code-inline">${escHtml(s.firma_usuario_code)}</span> · ${formatDateTime(s.firma_usuario_at)}`
              : '<i>pendiente de firma — si exportas ahora, el PDF saldrá sin su firma</i>'}</p>
         </div>`
      : '';

    const cambios      = s.cambios || [];
    const cambiosAbier = cambios.filter(c => c.estado === 'pendiente').length;
    const cambioBadge  = cambiosAbier ? `<span class="badge status-change">Cambio solicitado</span>` : '';

    return `
      <div class="solicitud-card ${cardClass}" id="card-${s.id}">
        <div class="card-header">
          <span class="card-title">${escHtml(s.nombre_solicitante)}</span>
          <span class="card-badges">${cambioBadge}<span class="badge ${badgeClass}">${badgeLabel}</span></span>
        </div>
        <div class="card-meta">
          <div class="meta-item">
            <span class="meta-label">Equipo</span>
            <span class="meta-value">${escHtml(s.tipo_equipo)} — ${escHtml(s.marca)} ${escHtml(s.modelo)}</span>
          </div>
          <div class="meta-item">
            <span class="meta-label">Centro de trabajo</span>
            <span class="meta-value">${escHtml(s.centro_trabajo)}</span>
          </div>
          <div class="meta-item">
            <span class="meta-label">No. Serie</span>
            <span class="meta-value">${escHtml(s.numero_serie || '—')}</span>
          </div>
          <div class="meta-item">
            <span class="meta-label">No. Inventario</span>
            <span class="meta-value">${escHtml(s.numero_inventario || '—')}</span>
          </div>
          <div class="meta-item">
            <span class="meta-label">Fecha</span>
            <span class="meta-value">${formatDate(s.fecha || s.created_at)}</span>
          </div>
        </div>
        ${s.tiene_imagen ? `<a class="equipo-thumb" href="${equipoImageUrl(s.id, currentAdminRegion)}" target="_blank" rel="noopener" title="Ver fotografía completa"><img loading="lazy" alt="Fotografía del equipo" src="${equipoImageUrl(s.id, currentAdminRegion)}"></a>` : ''}
        ${userProblem}
        ${adminDiag}
        ${firmasInfo}
        ${renderCambios(cambios, true, s.id)}
        <div class="card-actions">
          ${nextAction}
          ${pdfBtn}
        </div>
      </div>`;
  }).join('');
}

// ── Modal: diagnóstico del administrador ─────────────────
const DIAG_ADMIN_MAX = 800;

function closeDiagnosticoModal() {
  const m = document.getElementById('diag-modal');
  if (m) m.remove();
  document.removeEventListener('keydown', diagEscHandler);
}

function diagEscHandler(e) {
  if (e.key === 'Escape') closeDiagnosticoModal();
}

function openDiagnosticoModal(id) {
  const s = currentSolicitudes.find(x => Number(x.id) === Number(id));
  if (!s) return;
  closeDiagnosticoModal();

  const isEdit = s.estado === 'completada';
  const overlay = document.createElement('div');
  overlay.className = 'modal-overlay';
  overlay.id = 'diag-modal';
  overlay.innerHTML = `
    <div class="glass-card modal-box" role="dialog" aria-modal="true" aria-labelledby="diag-modal-title">
      <h2 class="modal-title" id="diag-modal-title">${isEdit ? '✏ Editar diagnóstico' : '✓ Completar solicitud'}</h2>
      <p class="modal-sub">${escHtml(s.tipo_equipo)} — ${escHtml(s.marca)} ${escHtml(s.modelo)} · ${escHtml(s.nombre_solicitante)}</p>
      ${s.diagnostico ? `
        <div class="diag-block diag-user" style="margin-top:0">
          <span class="meta-label">Problema reportado por el usuario</span>
          <p>${escHtml(s.diagnostico)}</p>
        </div>` : ''}
      ${s.firma_usuario_code ? `
        <div class="diag-block diag-missing" style="margin-top:0;margin-bottom:14px">
          <span class="meta-label">⚠ El usuario ya firmó este diagnóstico</span>
          <p>Si cambias el texto, su firma se borrará y tendrá que firmar de nuevo.</p>
        </div>` : ''}
      <div class="form-group" style="margin-bottom:0">
        <label class="form-label" for="diag-admin-text">Diagnóstico del equipo *</label>
        <textarea class="form-control" id="diag-admin-text" rows="7" maxlength="${DIAG_ADMIN_MAX}"
                  placeholder="Describe el diagnóstico técnico del equipo: causa de la falla, pruebas realizadas, recomendación..."></textarea>
        <div class="char-count"><span id="diag-count">0</span> / ${DIAG_ADMIN_MAX} · este texto es el que aparecerá en el PDF, con tu firma</div>
      </div>
      <div class="modal-actions">
        <button type="button" class="btn btn-red btn-sm" id="diag-cancel">Cancelar</button>
        <button type="button" class="btn btn-green-solid" id="diag-save">${isEdit ? '💾 Guardar diagnóstico' : '✓ Completar solicitud'}</button>
      </div>
    </div>`;
  document.body.appendChild(overlay);

  const ta    = overlay.querySelector('#diag-admin-text');
  const count = overlay.querySelector('#diag-count');
  ta.value = s.diagnostico_admin || '';
  const updateCount = () => { count.textContent = ta.value.length; };
  ta.addEventListener('input', updateCount);
  updateCount();
  ta.focus();

  overlay.addEventListener('mousedown', e => { if (e.target === overlay) closeDiagnosticoModal(); });
  overlay.querySelector('#diag-cancel').addEventListener('click', closeDiagnosticoModal);
  document.addEventListener('keydown', diagEscHandler);

  const saveBtn = overlay.querySelector('#diag-save');
  saveBtn.addEventListener('click', async () => {
    const diag = ta.value.trim();
    if (!diag) {
      ToastManager.error('Escribe el diagnóstico del equipo para continuar');
      ta.focus();
      return;
    }
    setLoading(saveBtn, true);
    try {
      const res = await apiPost('/diagnosticos-de-equipo/api/update_status.php', {
        id: s.id,
        estado: 'completada',
        region: currentAdminRegion,
        diagnostico_admin: diag,
      });
      if (res.success) {
        closeDiagnosticoModal();
        ToastManager.success(isEdit ? 'Diagnóstico guardado y firmado' : 'Solicitud completada y firmada');
        if (res.firma_usuario_borrada) ToastManager.info('La firma del usuario se borró: tendrá que firmar de nuevo');
        loadSolicitudes(currentAdminRegion);
      } else {
        ToastManager.error(res.error || 'Error al guardar el diagnóstico');
        setLoading(saveBtn, false);
      }
    } catch {
      ToastManager.error('Error de conexión');
      setLoading(saveBtn, false);
    }
  });
}

// ── Modal: atender una solicitud de cambio ─
function openResolveChangeModal(solicitudId, cambioId) {
  const s = currentSolicitudes.find(x => Number(x.id) === Number(solicitudId));
  const c = s && (s.cambios || []).find(x => Number(x.id) === Number(cambioId));
  if (!s || !c) return;

  const overlay = openModal('resolve-modal', `
    <h2 class="modal-title">✓ Atender cambio solicitado</h2>
    <p class="modal-sub">${escHtml(s.tipo_equipo)} — ${escHtml(s.marca)} ${escHtml(s.modelo)} · ${escHtml(s.nombre_solicitante)}</p>
    <div class="diag-block diag-user" style="margin-top:0">
      <span class="meta-label">El usuario pide</span>
      <p>${escHtml(c.mensaje)}</p>
    </div>
    <div class="form-group" style="margin-bottom:0">
      <label class="form-label" for="resolve-text">Respuesta para el usuario (opcional)</label>
      <textarea class="form-control" id="resolve-text" rows="4" maxlength="${CAMBIO_RESP_MAX}"
                placeholder="Ej. Ya corregí el diagnóstico. / No procede porque..."></textarea>
      <div class="char-count"><span id="resolve-count">0</span> / ${CAMBIO_RESP_MAX} · el usuario verá esta respuesta</div>
    </div>
    <div class="modal-actions">
      <button type="button" class="btn btn-red btn-sm" id="resolve-cancel">Cancelar</button>
      <button type="button" class="btn btn-green-solid" id="resolve-save">✓ Marcar como atendido</button>
    </div>`);

  const ta    = overlay.querySelector('#resolve-text');
  const count = overlay.querySelector('#resolve-count');
  ta.addEventListener('input', () => { count.textContent = ta.value.length; });
  ta.focus();

  overlay.querySelector('#resolve-cancel').addEventListener('click', () => closeModal('resolve-modal'));
  const btn = overlay.querySelector('#resolve-save');
  btn.addEventListener('click', async () => {
    setLoading(btn, true);
    try {
      const res = await apiPost('/diagnosticos-de-equipo/api/resolve_change.php', {
        id: c.id,
        region: currentAdminRegion,
        respuesta: ta.value.trim(),
      });
      if (res.success) {
        closeModal('resolve-modal');
        ToastManager.success('Cambio marcado como atendido');
        loadSolicitudes(currentAdminRegion);
      } else {
        ToastManager.error(res.error || 'No se pudo atender el cambio');
        setLoading(btn, false);
      }
    } catch {
      ToastManager.error('Error de conexión');
      setLoading(btn, false);
    }
  });
}

async function updateStatus(id, newEstado, btn) {
  if (btn) setLoading(btn, true);
  try {
    const res = await apiPost('/diagnosticos-de-equipo/api/update_status.php', {
      id, estado: newEstado, region: currentAdminRegion
    });
    if (res.success) {
      ToastManager.success(`Solicitud marcada como ${newEstado}`);
      setTimeout(() => loadSolicitudes(currentAdminRegion), 600);
    } else {
      ToastManager.error(res.error || 'Error al actualizar');
      if (btn) setLoading(btn, false);
    }
  } catch {
    ToastManager.error('Error de conexión');
    if (btn) setLoading(btn, false);
  }
}

function initAdminPage() {
  const regionSelect = document.getElementById('admin-region-select');
  if (!regionSelect) return;

  regionSelect.addEventListener('change', () => {
    const r = regionSelect.value;
    if (r) loadSolicitudes(r);
  });

  // Auto-load first region
  if (regionSelect.value) loadSolicitudes(regionSelect.value);
}

// ── Utilities ─────────────────────────────
function escHtml(str) {
  if (!str) return '';
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

function formatDate(str) {
  if (!str) return '—';
  // 'YYYY-MM-DD' (columna DATE) o 'YYYY-MM-DD HH:MM:SS' (TIMESTAMP): se interpretan como
  // fecha local para que no se recorran por la zona horaria del navegador.
  const m = String(str).match(/^(\d{4})-(\d{2})-(\d{2})/);
  const d = m ? new Date(+m[1], +m[2] - 1, +m[3]) : new Date(str);
  if (isNaN(d)) return '—';
  return d.toLocaleDateString('es-MX', { year: 'numeric', month: 'short', day: 'numeric' });
}

// 'YYYY-MM-DD HH:MM:SS' → "9 nov 2026 · 18:30" (sin convertir zonas horarias: es la hora tal cual se guardó)
function formatDateTime(str) {
  if (!str) return '—';
  const m = String(str).match(/^\d{4}-\d{2}-\d{2}[ T](\d{2}):(\d{2})/);
  return m ? `${formatDate(str)} · ${m[1]}:${m[2]}` : formatDate(str);
}

// ── Auto-init on DOM ready ────────────────
document.addEventListener('DOMContentLoaded', () => {
  const page = document.body.dataset.page;
  if (page === 'login')    initLoginPage();
  if (page === 'solicitud') initSolicitudPage();
  if (page === 'admin')    initAdminPage();
});
