// ============================================
// assets/js/app.js — Main Alpine.js App
// ============================================

function QuizBApp() {
  return {
    // ---- State ----
    currentRoute: '/',
    routeParams: {},
    user: null,
    darkMode: store.get('darkMode', false),
    mobileMenu: false,
    pageTitle: 'QuizB — Platform Kuis Modern',
    toast: { show: false, message: '', type: 'success', icon: '✅' },
    _toastTimer: null,

    navItems: [
      { href: '/',            label: '🏠 Beranda'     },
      { href: '/categories',  label: '📂 Kategori'    },
      { href: '/quizzes',     label: '📝 Semua Kuis'  },
      { href: '/leaderboard', label: '🏆 Leaderboard' },
    ],

    // ---- Page Data ----
    home:        { featured: [], categories: [], loading: true, stats: { total_questions: 0, total_quizzes: 0, total_categories: 0, total_users: 0 } },
    categories:  { list: [], loading: true },
    quizzes:     { list: [], loading: true, total: 0, page: 1, categoryId: 0, search: '' },
    quizDetail:  { quiz: null, loading: true },
    leaderboard: { list: [], loading: true },
    dashboard:   { stats: null, recent: [], loading: true },
    history:     { list: [], loading: true, total: 0, page: 1 },
    result:      { data: null, loading: true },

    // Admin state
    admin: {
      tab: 'stats',
      stats: null,
      quizzes: [], quizzesTotal: 0, quizzesPage: 1,
      users: [],   usersTotal: 0,   usersPage: 1,
      categories: [],
      loading: false,
      modal: { show: false, type: '', data: {} },
      form: {},
      formError: '',
    },

    // Auth forms
    loginForm:    { email: '', password: '', loading: false, error: '' },
    registerForm: { name: '', email: '', password: '', password_confirm: '', loading: false, error: '' },

    // ---- Lifecycle ----
    async init() {
      // Dark mode
      this.applyDark();

      // Hash routing
      this.handleRoute(window.location.hash || '#/');
      window.addEventListener('hashchange', () => this.handleRoute(window.location.hash));

      // Search events
      window.addEventListener('search', (e) => this.onSearch(e.detail.q));

      // Load current user
      await this.loadUser();
    },

    // ---- Router ----
    handleRoute(hash) {
      const path = hash.replace(/^#/, '') || '/';
      const [base, ...rest] = path.split('/').filter(Boolean);
      const routeMap = {
        '':            '/',
        'categories':  '/categories',
        'quizzes':     '/quizzes',
        'quiz':        '/quiz/' + (rest[0] || ''),
        'play':        '/play/' + (rest[0] || ''),
        'result':      '/result/' + (rest[0] || ''),
        'leaderboard': '/leaderboard',
        'dashboard':   '/dashboard',
        'history':     '/history',
        'login':       '/login',
        'register':    '/register',
        'admin':       '/admin',
      };

      const route = routeMap[base] || (path === '/' ? '/' : '/404');
      this.currentRoute = route;
      window.scrollTo({ top: 0, behavior: 'smooth' });
      this.onRouteChange(route, rest);
    },

    navigate(path) {
      window.location.hash = '#' + path;
    },

    onRouteChange(route, params) {
      // Guard protected routes (dashboard & history tetap perlu login)
      const protected_routes = ['/dashboard', '/history'];
      const admin_routes     = ['/admin'];

      if (protected_routes.some(r => route.startsWith(r)) && !this.user) {
        this.showToast('Silakan login untuk melihat dashboard', 'warning', '⚠️');
        return this.navigate('/login');
      }
      if (admin_routes.some(r => route.startsWith(r)) && this.user?.role !== 'admin') {
        this.showToast('Akses ditolak', 'error', '🚫');
        return this.navigate('/');
      }

      // Load data per route
      if (route === '/')            this.loadHome();
      if (route === '/categories')  this.loadCategories();
      if (route === '/quizzes')     this.loadQuizzes();
      if (route.startsWith('/quiz/')) this.loadQuizDetail(params[0]);
      if (route === '/leaderboard') this.loadLeaderboard();
      if (route === '/dashboard')   this.loadDashboard();
      if (route === '/history')     this.loadHistory();
      if (route.startsWith('/result/')) this.loadResult(params[0]);
      if (route.startsWith('/admin'))   this.loadAdminTab(this.admin.tab);
    },

    // ---- Auth ----
    async loadUser() {
      try {
        this.user = await api.get('auth.me');
      } catch {
        this.user = null;
      }
    },

    async login() {
      const f = this.loginForm;
      if (!f.email || !f.password) { f.error = 'Email dan password wajib diisi'; return; }
      f.loading = true; f.error = '';
      try {
        const data = await api.post('auth.login', { email: f.email, password: f.password });
        this.user = data.user;
        api._csrfToken = data.csrf_token || null;
        this.showToast(`Selamat datang, ${this.user.name}!`, 'success', '👋');
        this.navigate('/dashboard');
      } catch (e) {
        f.error = e.message;
      } finally {
        f.loading = false;
      }
    },

    async register() {
      const f = this.registerForm;
      if (!f.name || !f.email || !f.password) { f.error = 'Semua field wajib diisi'; return; }
      if (f.password !== f.password_confirm)   { f.error = 'Password tidak cocok'; return; }
      if (f.password.length < 6)               { f.error = 'Password minimal 6 karakter'; return; }
      f.loading = true; f.error = '';
      try {
        const data = await api.post('auth.register', { name: f.name, email: f.email, password: f.password });
        this.user = data.user;
        this.showToast('Registrasi berhasil!', 'success', '🎉');
        this.navigate('/dashboard');
      } catch (e) {
        f.error = e.message;
      } finally {
        f.loading = false;
      }
    },

    async logout() {
      try { await api.post('auth.logout'); } catch {}
      this.user = null;
      api._csrfToken = null;
      this.showToast('Sampai jumpa!', 'info', '👋');
      this.navigate('/');
    },

    // ---- Page Loaders ----
    async loadHome() {
      this.home.loading = true;
      try {
        // category.list → jsonSuccess → data.data = array langsung
        // quiz.list     → jsonPaginated → data.data = array quiz, data.meta = pagination
        // quiz.stats    → jsonSuccess   → data.data = { total_questions, total_quizzes, ... }
        const [cats, quizData, stats] = await Promise.all([
          api.get('category.list'),
          api.getFull('quiz.list', { limit: 6 }),
          api.get('quiz.stats'),
        ]);
        this.home.categories = Array.isArray(cats) ? cats : [];
        this.home.featured   = Array.isArray(quizData.data) ? quizData.data : [];
        this.home.stats      = stats || {};
      } catch (e) {
        this.showToast(e.message, 'error', '❌');
      } finally {
        this.home.loading = false;
      }
    },

    async loadCategories() {
      this.categories.loading = true;
      try {
        this.categories.list = await api.get('category.list');
      } catch (e) {
        this.showToast(e.message, 'error', '❌');
      } finally {
        this.categories.loading = false;
      }
    },

    async loadQuizzes(reset = false) {
      if (reset) { this.quizzes.page = 1; this.quizzes.list = []; }
      this.quizzes.loading = true;
      try {
        const params = {
          page: this.quizzes.page,
          limit: 12,
          ...(this.quizzes.categoryId ? { category: this.quizzes.categoryId } : {}),
          ...(this.quizzes.search     ? { search: this.quizzes.search }       : {}),
        };
        // jsonPaginated → { success, data: [...], meta: { total, page, ... } }
        // api.getFull mengembalikan seluruh response JSON, bukan hanya .data
        const resp = await api.getFull('quiz.list', params);
        this.quizzes.list  = Array.isArray(resp.data) ? resp.data : [];
        this.quizzes.total = resp.meta?.total || 0;
      } catch (e) {
        this.showToast(e.message, 'error', '❌');
      } finally {
        this.quizzes.loading = false;
      }
    },

    async loadQuizDetail(id) {
      this.quizDetail.loading = true; this.quizDetail.quiz = null;
      try {
        // quiz.get → jsonSuccess(quiz_object) → data.data = quiz object langsung
        const quiz = await api.get('quiz.get', { id });
        this.quizDetail.quiz = quiz;
        this.pageTitle = (quiz?.title || 'Quiz') + ' — QuizB';
      } catch (e) {
        this.showToast(e.message, 'error', '❌');
      } finally {
        this.quizDetail.loading = false;
      }
    },

    async loadLeaderboard() {
      this.leaderboard.loading = true;
      try {
        this.leaderboard.list = await api.get('leaderboard.global');
      } catch (e) {
        this.showToast(e.message, 'error', '❌');
      } finally {
        this.leaderboard.loading = false;
      }
    },

    async loadDashboard() {
      this.dashboard.loading = true;
      try {
        const data = await api.get('attempt.dashboard');
        this.dashboard.stats  = data.stats;
        this.dashboard.recent = data.recent || [];
      } catch (e) {
        this.showToast(e.message, 'error', '❌');
      } finally {
        this.dashboard.loading = false;
      }
    },

    async loadHistory(reset = false) {
      if (reset) { this.history.page = 1; this.history.list = []; }
      this.history.loading = true;
      try {
        const data = await api.get('attempt.history', { page: this.history.page, limit: 10 });
        this.history.list  = data.attempts || [];
        this.history.total = data.total    || 0;
      } catch (e) {
        this.showToast(e.message, 'error', '❌');
      } finally {
        this.history.loading = false;
      }
    },

    async loadResult(attemptId) {
      this.result.loading = true; this.result.data = null;
      try {
        this.result.data = await api.get('attempt.result', { id: attemptId });
      } catch (e) {
        this.showToast(e.message, 'error', '❌');
      } finally {
        this.result.loading = false;
      }
    },

    // ---- Admin ----
    async loadAdminTab(tab) {
      this.admin.tab = tab;
      this.admin.loading = true;
      try {
        if (tab === 'stats') {
          this.admin.stats = await api.get('admin.stats');
        } else if (tab === 'quizzes') {
          const data = await api.get('admin.quiz_list', { page: this.admin.quizzesPage, limit: 15 });
          this.admin.quizzes      = data.quizzes || [];
          this.admin.quizzesTotal = data.total   || 0;
        } else if (tab === 'users') {
          const data = await api.get('admin.user_list', { page: this.admin.usersPage, limit: 15 });
          this.admin.users      = data.users  || [];
          this.admin.usersTotal = data.total  || 0;
        } else if (tab === 'categories') {
          this.admin.categories = await api.get('admin.category_list');
        }
      } catch (e) {
        this.showToast(e.message, 'error', '❌');
      } finally {
        this.admin.loading = false;
      }
    },

    openAdminModal(type, data = {}) {
      this.admin.modal = { show: true, type };
      this.admin.form  = { ...data };
      this.admin.formError = '';
    },

    closeAdminModal() {
      this.admin.modal.show = false;
      this.admin.form = {};
      this.admin.formError = '';
    },

    async saveAdminForm() {
      const { type, data } = this.admin.modal;
      const f = this.admin.form;
      this.admin.loading = true;
      try {
        if (type === 'quiz_create') {
          await api.post('admin.quiz_create', f);
          this.showToast('Quiz berhasil dibuat', 'success', '✅');
        } else if (type === 'quiz_edit') {
          await api.put('admin.quiz_update', f.id, f);
          this.showToast('Quiz berhasil diperbarui', 'success', '✅');
        } else if (type === 'category_create') {
          await api.post('admin.category_create', f);
          this.showToast('Kategori berhasil dibuat', 'success', '✅');
        } else if (type === 'category_edit') {
          await api.put('admin.category_update', f.id, f);
          this.showToast('Kategori berhasil diperbarui', 'success', '✅');
        } else if (type === 'user_edit') {
          await api.put('admin.user_update', f.id, f);
          this.showToast('User berhasil diperbarui', 'success', '✅');
        }
        this.closeAdminModal();
        await this.loadAdminTab(this.admin.tab);
      } catch (e) {
        this.admin.formError = e.message;
      } finally {
        this.admin.loading = false;
      }
    },

    async deleteAdminItem(type, id) {
      if (!confirm('Yakin ingin menghapus item ini?')) return;
      try {
        if (type === 'quiz')     await api.delete('admin.quiz_delete', id);
        if (type === 'category') await api.delete('admin.category_delete', id);
        if (type === 'user')     await api.delete('admin.user_delete', id);
        this.showToast('Berhasil dihapus', 'success', '🗑️');
        await this.loadAdminTab(this.admin.tab);
      } catch (e) {
        this.showToast(e.message, 'error', '❌');
      }
    },

    // ---- Search ----
    onSearch: debounce(async function(q) {
      if (!q || q.length < 2) return;
      this.quizzes.search = q;
      this.quizzes.page = 1;
      this.navigate('/quizzes');
      await this.loadQuizzes(true);
    }, 300),

    // ---- Dark Mode ----
    toggleDark() {
      this.darkMode = !this.darkMode;
      store.set('darkMode', this.darkMode);
      this.applyDark();
    },
    applyDark() {
      document.documentElement.classList.toggle('dark', this.darkMode);
    },

    // ---- Toast ----
    showToast(message, type = 'success', icon = '✅') {
      clearTimeout(this._toastTimer);
      this.toast = { show: true, message, type, icon };
      this._toastTimer = setTimeout(() => { this.toast.show = false; }, 3500);
    },
  };
}
