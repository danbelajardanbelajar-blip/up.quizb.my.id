// ============================================
// assets/js/utils.js — Shared Utilities
// ============================================

const API_BASE = '/api.php';

// ---- HTTP Client ----
const api = {
  _csrfToken: null,

  // Ambil CSRF token: prioritaskan cache, lalu fetch dari server
  async _getToken() {
    if (this._csrfToken) return this._csrfToken;
    try {
      const r = await fetch(`${API_BASE}?action=auth.csrf`, { credentials: 'same-origin' });
      // Baca dari header (butuh Access-Control-Expose-Headers)
      const fromHeader = r.headers.get('X-CSRF-Token');
      if (fromHeader) { this._csrfToken = fromHeader; return this._csrfToken; }
      // Fallback: baca dari JSON body { data: { token: '...' } }
      const json = await r.json();
      const fromBody = json?.data?.token || json?.token || null;
      if (fromBody) this._csrfToken = fromBody;
    } catch (_) {}
    return this._csrfToken;
  },

  // Simpan token dari luar (misal setelah login/me)
  setToken(token) {
    if (token) this._csrfToken = token;
  },

  // Helper: parse JSON dari response, lempar error yang jelas jika body kosong/rusak
  async _parseJson(r, fallbackMsg = 'Server mengembalikan respons tidak valid') {
    const text = await r.text();
    if (!text || !text.trim()) {
      throw new Error(`${fallbackMsg} (HTTP ${r.status} — respons kosong)`);
    }
    try {
      return JSON.parse(text);
    } catch (_) {
      throw new Error(`${fallbackMsg} (HTTP ${r.status} — bukan JSON valid)`);
    }
  },

  async get(action, params = {}) {
    const qs = new URLSearchParams({ action, ...params });
    const r = await fetch(`${API_BASE}?${qs}`, { credentials: 'same-origin' });
    const ct = r.headers.get('X-CSRF-Token');
    if (ct) this._csrfToken = ct;
    const data = await this._parseJson(r);
    if (!r.ok) throw new Error(data.message || data.error || 'Request failed');
    return data.data;
  },

  // Kembalikan seluruh response (untuk endpoint paginated yang butuh .meta)
  async getFull(action, params = {}) {
    const qs = new URLSearchParams({ action, ...params });
    const r = await fetch(`${API_BASE}?${qs}`, { credentials: 'same-origin' });
    const ct = r.headers.get('X-CSRF-Token');
    if (ct) this._csrfToken = ct;
    const data = await this._parseJson(r);
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
    const data = await this._parseJson(r, 'Gagal menghubungi server');
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
    const data = await this._parseJson(r);
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
    const data = await this._parseJson(r);
    if (!r.ok) throw new Error(data.message || data.error || 'Request failed');
    return data.data;
  },

  // Upload multipart file (FormData) — DO NOT set Content-Type, browser sets boundary
  async upload(action, formData) {
    const token = await this._getToken();
    const r = await fetch(`${API_BASE}?action=${action}`, {
      method: 'POST',
      headers: { 'X-CSRF-Token': token || '' },
      body: formData,
      credentials: 'same-origin',
    });
    const data = await this._parseJson(r, 'Upload gagal');
    const ct = r.headers.get('X-CSRF-Token');
    if (ct) this._csrfToken = ct;
    if (!r.ok) throw new Error(data.message || 'Upload gagal');
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

const store = {
  get(key, def = null) {
    try { const v = localStorage.getItem(key); return v !== null ? JSON.parse(v) : def; } catch { return def; }
  },
  set(key, value) {
    try { localStorage.setItem(key, JSON.stringify(value)); } catch {}
  },
};

function debounce(fn, ms) {
  let t;
  return function(...args) {
    clearTimeout(t);
    t = setTimeout(() => fn.apply(this, args), ms);
  };
}
