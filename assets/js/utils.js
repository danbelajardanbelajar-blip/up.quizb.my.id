// ============================================
// assets/js/utils.js — Shared Utilities
// ============================================

const API_BASE = 'api.php';

// ---- HTTP Client ----
const api = {
  _csrfToken: null,

  async _getToken() {
    if (!this._csrfToken) {
      const r = await fetch(`${API_BASE}?action=auth.csrf`);
      const h = r.headers.get('X-CSRF-Token');
      if (h) this._csrfToken = h;
    }
    return this._csrfToken;
  },

  async get(action, params = {}) {
    const qs = new URLSearchParams({ action, ...params });
    const r = await fetch(`${API_BASE}?${qs}`);
    const ct = r.headers.get('X-CSRF-Token');
    if (ct) this._csrfToken = ct;
    const data = await r.json();
    if (!r.ok) throw new Error(data.message || data.error || 'Request failed');
    return data.data;
  },

  // Kembalikan seluruh response (untuk endpoint paginated yang butuh .meta)
  async getFull(action, params = {}) {
    const qs = new URLSearchParams({ action, ...params });
    const r = await fetch(`${API_BASE}?${qs}`);
    const ct = r.headers.get('X-CSRF-Token');
    if (ct) this._csrfToken = ct;
    const data = await r.json();
    if (!r.ok) throw new Error(data.message || data.error || 'Request failed');
    return data; // { success, data: [...], meta: {...} }
  },

  async post(action, body = {}) {
    const token = await this._getToken();
    const r = await fetch(`${API_BASE}?action=${action}`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': token || '' },
      body: JSON.stringify(body),
      credentials: 'same-origin',
    });
    const data = await r.json();
    const ct = r.headers.get('X-CSRF-Token');
    if (ct) this._csrfToken = ct;
    if (!r.ok) throw new Error(data.message || 'Request failed');
    return data.data;
  },

  async put(action, id, body = {}) {
    const token = await this._getToken();
    const r = await fetch(`${API_BASE}?action=${action}&id=${id}`, {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': token || '' },
      body: JSON.stringify(body),
      credentials: 'same-origin',
    });
    const data = await r.json();
    if (!r.ok) throw new Error(data.message || 'Request failed');
    return data.data;
  },

  async delete(action, id, body = null) {
    const token = await this._getToken();
    const url = id ? `${API_BASE}?action=${action}&id=${id}` : `${API_BASE}?action=${action}`;
    const opts = {
      method: 'DELETE',
      headers: { 'X-CSRF-Token': token || '' },
      credentials: 'same-origin',
    };
    if (body) {
      opts.headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(body);
    }
    const r = await fetch(url, opts);
    const data = await r.json();
    if (!r.ok) throw new Error(data.message || data.error || 'Request failed');
    return data.data;
  },
};

// ---- Formatters ----
function formatTime(seconds) {
  const m = Math.floor(seconds / 60);
  const s = seconds % 60;
  return `${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')}`;
}

function formatDate(dateStr) {
  if (!dateStr) return '-';
  return new Date(dateStr).toLocaleDateString('id-ID', { day:'numeric', month:'long', year:'numeric' });
}

function formatRelative(dateStr) {
  if (!dateStr) return '-';
  const diff = Date.now() - new Date(dateStr).getTime();
  const m = Math.floor(diff / 60000);
  if (m < 1)  return 'Baru saja';
  if (m < 60) return `${m} menit lalu`;
  const h = Math.floor(m / 60);
  if (h < 24) return `${h} jam lalu`;
  const d = Math.floor(h / 24);
  if (d < 7)  return `${d} hari lalu`;
  return formatDate(dateStr);
}

function difficultyLabel(d) {
  return { easy: 'Mudah', medium: 'Sedang', hard: 'Sulit' }[d] || d;
}

function difficultyClass(d) {
  return { easy: 'diff-easy', medium: 'diff-medium', hard: 'diff-hard' }[d] || '';
}

function scoreGrade(score) {
  if (score >= 90) return { label: 'Sempurna!', emoji: '🏆', color: 'text-yellow-500' };
  if (score >= 75) return { label: 'Bagus!',     emoji: '⭐', color: 'text-green-500'  };
  if (score >= 60) return { label: 'Lulus',       emoji: '✅', color: 'text-blue-500'   };
  return { label: 'Perlu Belajar Lagi', emoji: '📚', color: 'text-red-500' };
}

function slugify(str) {
  return str.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)/g, '');
}

// ---- Local Storage ----
const store = {
  get: (key, def = null) => { try { const v = localStorage.getItem(key); return v ? JSON.parse(v) : def; } catch { return def; } },
  set: (key, val) => { try { localStorage.setItem(key, JSON.stringify(val)); } catch {} },
  remove: (key) => { try { localStorage.removeItem(key); } catch {} },
};

// ---- Debounce ----
function debounce(fn, ms = 300) {
  let t;
  return (...args) => { clearTimeout(t); t = setTimeout(() => fn(...args), ms); };
}
