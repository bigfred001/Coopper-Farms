/**
 * Coopers Farm — Admin JS
 * Works with original sidebar.php (.sidebar, .nav-item)
 * and all rebuilt admin pages.
 */

/* ── Sidebar toggle (mobile) ─────────────────────────────────── */
(function () {
  const btn     = document.querySelector('.topbar-menu-btn');
  const sidebar = document.querySelector('.sidebar');
  if (!btn || !sidebar) return;

  let overlay = document.getElementById('sidebarOverlay');
  if (!overlay) {
    overlay = document.createElement('div');
    overlay.id = 'sidebarOverlay';
    overlay.style.cssText =
      'display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:299;';
    document.body.appendChild(overlay);
  }

  function openSidebar()  { sidebar.classList.add('open');    overlay.style.display = 'block'; }
  function closeSidebar() { sidebar.classList.remove('open'); overlay.style.display = 'none';  }

  btn.addEventListener('click', function () {
    sidebar.classList.contains('open') ? closeSidebar() : openSidebar();
  });
  overlay.addEventListener('click', closeSidebar);
})();

/* ── Active nav link (matches original .nav-item class) ─────── */
document.addEventListener('DOMContentLoaded', function () {
  var path = window.location.pathname.split('/').pop().split('?')[0];
  document.querySelectorAll('.nav-item').forEach(function (a) {
    var href = (a.getAttribute('href') || '').split('?')[0];
    if (href && href === path) a.classList.add('active');
  });
});

/* ── Escape key: close modals and drawers ────────────────────── */
document.addEventListener('keydown', function (e) {
  if (e.key !== 'Escape') return;
  document.querySelectorAll('.modal-overlay.open').forEach(function (m) {
    m.classList.remove('open');
  });
  document.querySelectorAll('.drawer.open').forEach(function (d) {
    d.classList.remove('open');
  });
  document.querySelectorAll('.drawer-overlay.open').forEach(function (d) {
    d.classList.remove('open');
  });
});

/* ── Click outside modal to close ───────────────────────────── */
document.addEventListener('click', function (e) {
  if (e.target.classList.contains('modal-overlay')) {
    e.target.classList.remove('open');
  }
});

/* ── Toast notification ──────────────────────────────────────── */
/**
 * showToast(message, type, duration)
 * type: 'success' | 'error' | 'warning' | 'info' | 'warn'
 * duration: ms (default 2600)
 */
function showToast(msg, type, duration) {
  type     = type     || 'success';
  duration = duration || 2600;

  var icons = {
    success: 'fa-check-circle',
    error:   'fa-times-circle',
    warning: 'fa-exclamation-triangle',
    warn:    'fa-exclamation-triangle',
    info:    'fa-info-circle',
  };

  var t = document.createElement('div');
  t.className = 'toast' + (type !== 'success' ? ' ' + type : '');
  t.innerHTML =
    '<i class="fas ' + (icons[type] || icons.success) + '"></i> ' + msg;
  document.body.appendChild(t);

  setTimeout(function () {
    t.style.transition = 'opacity .3s, transform .3s';
    t.style.opacity    = '0';
    t.style.transform  = 'translateY(8px)';
    setTimeout(function () { if (t.parentNode) t.remove(); }, 320);
  }, duration);
}

/* Alias — some pages use toast() instead of showToast() */
function toast(msg, type, duration) { showToast(msg, type, duration); }

/* ── Image upload preview ────────────────────────────────────── */
/**
 * previewImg(inputEl, previewId)
 * Renders a selected image file into <img id="previewId">
 */
function previewImg(input, previewId) {
  var preview = document.getElementById(previewId);
  if (!preview) return;
  var file = input.files && input.files[0];
  if (!file) { preview.style.display = 'none'; return; }
  if (file.size > 3 * 1024 * 1024) {
    showToast('Image too large — max 3 MB', 'warning');
    input.value = '';
    preview.style.display = 'none';
    return;
  }
  var reader = new FileReader();
  reader.onload = function (e) {
    preview.src = e.target.result;
    preview.style.display = 'block';
  };
  reader.readAsDataURL(file);
}

/* ── Image drop zone (auto-applied to all .img-drop elements) ── */
document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('.img-drop').forEach(function (drop) {
    drop.addEventListener('dragover', function (e) {
      e.preventDefault();
      drop.style.borderColor = 'var(--primary)';
      drop.style.background  = 'var(--primary-light)';
    });
    drop.addEventListener('dragleave', function () {
      drop.style.borderColor = '';
      drop.style.background  = '';
    });
    drop.addEventListener('drop', function (e) {
      e.preventDefault();
      drop.style.borderColor = '';
      drop.style.background  = '';
      var inp = drop.querySelector('input[type=file]');
      if (!inp || !e.dataTransfer.files.length) return;
      try { inp.files = e.dataTransfer.files; } catch (err) {}
      var pid = inp.dataset.preview;
      if (pid) previewImg(inp, pid);
    });
  });
});

/* ── Confirm wrapper ─────────────────────────────────────────── */
function confirmAction(msg, callback) {
  if (window.confirm(msg)) callback();
}

/* ── Currency formatter (GHS) ────────────────────────────────── */
function formatGHS(amount) {
  return '₵' + parseFloat(amount || 0)
    .toFixed(2)
    .replace(/\B(?=(\d{3})+(?!\d))/g, ',');
}
