// ============================================
// assets/js/app.js — Main Alpine.js App
// v2.1: Fix auth mapping, classroom state, quiz search
// ============================================

function QuizBApp() {
  return {
    // ---- State ----
    currentRoute: '/',
    routeParams: [],
    user: null,
    _userLoaded: false, // Flag: true setelah loadUser() selesai — guard route tidak boleh aktif sebelum ini
    darkMode: store.get('darkMode', false),
    mobileMenu: false,
    mobileSearch: false,
    search: { q: '', results: [], loading: false, total: 0 },
    pageTransition: { show: false }, // legacy — progress bar used instead
    pageTitle: 'QuizB — Platform Kuis Modern',
    toast: { show: false, message: '', type: 'success', icon: '✅' },
    _toastTimer: null,

    // Nav items — dinamis di getter, tapi definisikan base dulu
    get navItems() {
      const base = [
        { href: this.user ? '/dashboard' : '/', label: '🏠  Beranda' },
        { href: '/activity',                    label: '📈  Aktivitas' },
      ];
      if (this.user) {
        base.push({ href: '/classroom', label: '🎓  Kelas' });
        const badge = this.challenge.pendingCount > 0 ? ' (' + this.challenge.pendingCount + ')' : '';
        base.push({ href: '/challenges', label: '⚔️  Tantangan' + badge });
      }
      return base;
    },

    // ---- Page Data ----
    home:        { featured: [], categories: [], groups: [], loading: true, stats: { total_questions: 0, total_quizzes: 0, total_categories: 0, total_users: 0 } },
    categories:  { list: [], loading: true },
    quizzes:     { list: [], loading: true, total: 0, page: 1, categoryId: 0, search: '', difficulty: '' },
    quizDetail:  { quiz: null, loading: true },
    leaderboard: { list: [], loading: true },
    activity:       { list: [], loading: true, total: 0, page: 1, loadingMore: false },
    publicHistory:  { list: [], loading: true, loadingMore: false, total: 0, page: 1, limit: 50,
                      filterType: '', filterId: null, filterLabel: '' },
    dashboard:   { stats: null, userInfo: null, recent: [], loading: true, assignments: [], assignmentsRole: '', assignmentsLoading: false },
    // Modal: attempts for an assignment (student)
    dashboardAttemptModal: { show: false, loading: false, attempts: [], assignment: null, error: '' },
    history:     { list: [], loading: true, total: 0, page: 1 },
    result:      { data: null, loading: true, assignId: null, assignSubmitted: false, assignSubmitting: false, assignError: '', challengeId: null, challengeData: null, mode: null },

    // ---- Classroom State ----
    classroom: {
      list:         [],
      loading:      true,
      detail:       null,
      detailLoading: true,
      joinModal:    { show: false },
      createModal:  { show: false },
      joinCode:     '',
      joinError:    '',
      joinLoading:  false,
      createForm:   { name: '', description: '' },
      createError:  '',
      createLoading: false,
      // Edit class modal
      editModal:    { show: false },
      editForm:     { id: null, name: '', description: '', is_active: 1 },
      editError:    '',
      editLoading:  false,
      // Delete class modal
      deleteModal:  { show: false, cls: null, loading: false, error: '' },
      // Leave class modal (pelajar)
      leaveModal:   { show: false, loading: false, error: '' },
      // Detail page state
      members:     [],
      assignments: [],
      isTeacher:   false,
      // Create assignment modal
      assignModal: { show: false, editId: null },
      assignForm:  { title: '', quiz_ids: [], mode: 'bebas', deadline: '', max_questions: '', shuffle_questions: null, shuffle_options: null, timer_per_question: '', duration_minutes: '', require_full_score: false },
      assignError: '',
      assignLoading: false,
      assignQuizDropdownOpen: false,
      // Quiz list for assignment dropdown
      quizListForAssign: [],
      quizListLoading: false,
    },

    // Assignment monitor state
    assignmentView: {
      loading: false,
      assignment: null,
      results:  null,
      monitor:  null,
      monitorInterval: null,
    },

    // Admin state
    admin: {
      tab: 'content',
      stats: null,
      quizzes: [], quizzesTotal: 0, quizzesPage: 1, quizzesSearch: '', quizzesView: 'list',
      users:   [],   usersTotal: 0,   usersPage: 1,   usersSearch: '',
      categories: [],
      questions: [], questionsQuizId: null, questionsQuizTitle: '',
      questionsAll: [], questionsTotal: 0, questionsPage: 1, questionsSearch: '', questionsQuizFilter: 0,
      // Daftar quiz khusus untuk dropdown di tab Soal — agar tidak menimpa
      // pagination admin.quizzes pada tab Quiz.
      quizPicker: [],
      contentQuizzes: [], contentQuizCount: 0, contentSearch: '', contentCategoryFilter: null, contentOpenGroups: [], contentOpenCategories: [],
      contentSelectedQuiz: null,
      groups: [],
      allCategories: [],
      questionsSourceTab: 'quizzes',
      groupAssign: { show: false, group: null, selected: [] },
      // Review Soal
      review: { data: [], expandedId: null, attempts: {}, search: '', page: 1, perPage: 15 },
      // Analisis Soal
      analysis: [],
      // User history modal
      userHistory: { show: false, user: null, allAttempts: [], page: 1, perPage: 15, loading: false, sort: { key: '', dir: 'asc' }, exporting: false },
      // Sort state per tab (client-side sort current page)
      sort: {
        quizzes:    { key: '', dir: 'asc' },
        users:      { key: '', dir: 'asc' },
        categories: { key: '', dir: 'asc' },
        review:     { key: 'total_plays', dir: 'desc' },
        questions:  { key: '', dir: 'asc' },
      },
      loading: false,
      modal: { show: false, type: '', data: {} },
      form: {},
      formError: '',
      importFile: {
        show: false, loading: false, step: 1,
        questions: [], quizId: null,
      },
      importQuizb: {
        show: false, loading: false,
        themes: [], selectedThemeId: null,
        subthemes: [], selectedSubthemeId: null,
        titles: [], selectedTitleId: null, selectedTitleName: '',
        questions: [], selectedIds: [],
        quizId: null,
      },
    },

    // Auth forms
    loginForm:    { email: '', password: '', loading: false, error: '' },
    registerForm: { name: '', email: '', password: '', password_confirm: '', loading: false, error: '' },
    googleSetupForm: { googleName: '', customName: '', loading: false, error: '' },

    // Settings
    settings: { limit: 10, shuffleQuestions: true, shuffleOptions: true, loading: false, saving: false, error: '', success: '' },

    // Challenge
    challenge: {
      incoming: [], received: [], outgoing: [], loading: false, pendingCount: 0,
      pollInterval: null,
    },

    // Notifications
    notif: {
      unreadCount: 0,
      list: [],
      total: 0,
      page: 1,
      show: false,
      loading: false,
    },

    // Messages
    msgs: {
      unreadCount: 0,
      threads: [],
      activeThread: null,
      chat: [],
      chatTotal: 0,
      chatPage: 1,
      input: '',
      sending: false,
      loading: false,
      chatLoading: false,
      pollInterval: null,
      newChat: { show: false, q: '', results: [], loading: false },
    },

    // ---- Lifecycle ----
    async init() {
      try {
        // Dark mode
        this.applyDark();

        // Save the original intended hash BEFORE any redirect may change it.
        const initialHash = window.location.hash || '#/';

        // Daftarkan hashchange listener SEBELUM handle route pertama kali
        window.addEventListener('hashchange', () => this.handleRoute(window.location.hash));

        // Search events
        window.addEventListener('search', (e) => this.onSearch(e.detail.q));

        // Handle route awal — _userLoaded masih false, jadi guard TIDAK aktif.
        // Hanya load data halaman yang dijalankan, tanpa redirect paksa ke /login.
        this.handleRoute(initialHash);

        // Load current user
        await this.loadUser();

        // Tandai bahwa user sudah selesai di-load — guard boleh aktif mulai sekarang
        this._userLoaded = true;

        // Sync settings state dengan data user
        if (this.user) {
          this.settings.limit            = this.user.quiz_questions_limit || 10;
          this.settings.shuffleQuestions = this.user.shuffle_questions    ?? true;
          this.settings.shuffleOptions   = this.user.shuffle_options      ?? true;
        }

        // Start challenge + notification polling for logged-in users
        if (this.user) {
          this.loadChallenges();
          this.challenge.pollInterval = setInterval(() => this.loadChallenges(), 20000);
          this.pollCounts();
        }

        // —— Flash message dari PHP session (verify-email.php redirect) ——
        if (window._phpFlash?.msg) {
          const iconMap = { success: '✅', error: '❌', warning: '⚠️', info: 'ℹ️' };
          this.showToast(
            window._phpFlash.msg,
            window._phpFlash.type || 'success',
            iconMap[window._phpFlash.type] || '✅'
          );
          window._phpFlash = null;
        }

        // Re-check semua guard setelah user selesai di-load.
        // _guardRoute akan menangani:
        //   - user BELUM login → akses protected route → redirect /login
        //   - user SUDAH login → akses /login atau /register → redirect /dashboard
        this._guardRoute(this.currentRoute);
      } catch (e) {
        console.error('App init failed', e);
        this.showToast('Terjadi kesalahan saat memuat aplikasi', 'error', '❌');
      } finally {
        this.hidePageLoader();
      }
    },

    hidePageLoader() {
      const loader = document.getElementById('page-loader');
      if (!loader) return;
      loader.classList.add('hidden');
      setTimeout(() => {
        if (loader.parentNode) loader.parentNode.removeChild(loader);
      }, 300);
    },

    // Lightweight guard re-check (used after async loadUser).
    _guardRoute(route) {
      const protected_routes = ['/dashboard', '/history', '/classroom', '/profile', '/settings', '/challenges', '/messages'];
      const admin_routes     = ['/admin'];
      const auth_only_routes = ['/login', '/register']; // halaman yang tidak boleh diakses user yg sudah login

      // User sudah login → jangan ke halaman login / register
      if (this.user && auth_only_routes.some(r => route.startsWith(r))) {
        return this.navigate('/dashboard');
      }
      if (route === '/onboarding' && !this.user) return this.navigate('/login');
      if (protected_routes.some(r => route.startsWith(r)) && !this.user) {
        this.showToast('Silakan login untuk mengakses halaman ini', 'warning', '⚠️');
        return this.navigate('/login');
      }
      if (admin_routes.some(r => route.startsWith(r)) && this.user?.role !== 'admin') {
        this.showToast('Akses ditolak', 'error', '⛔');
        return this.navigate('/');
      }
    },

    // ---- Router ----
    handleRoute(hash) {
      // Strip query string from hash for routing (e.g. #/play/5?assign=3 → /play/5)
      const hashClean = hash.replace(/^#/, '').split('?')[0] || '/';
      const path = hashClean;
      const segments = path.split('/').filter(Boolean);
      const base = segments[0] || '';
      const rest = segments.slice(1);

      const routeMap = {
        '':            '/',
        'categories':  '/categories',
        'quizzes':     '/quizzes',
        'quiz':        '/quiz/'        + (rest[0] || ''),
        'play':        '/play/'        + (rest[0] || ''),
        'result':      '/result/'      + (rest[0] || ''),
        'leaderboard': '/leaderboard',
        'dashboard':   '/dashboard',
        'history':     '/history',
        'profile':     '/profile',
        'settings':    '/settings',
        'login':       '/login',
        'register':    '/register',
        'google-setup': '/google-setup',
        'admin':       '/admin',
        'classroom':   rest[0] ? '/classroom/' + rest[0] : '/classroom',
        'challenges':      '/challenges',
        'activity':        '/activity',
        'public-history':  '/public-history',
        'assignment':  rest[0] ? '/assignment/' + rest.join('/') : '/assignment',
        'onboarding':  '/onboarding',
        'email-sent':  '/email-sent',
        'verify-error':'/verify-error',
        'messages':    '/messages',
        'search':      '/search',
        'about':       '/about',
        'privacy':     '/privacy',
      };

      const route = routeMap[base] || (path === '/' ? '/' : '/404');
      this.currentRoute = route;
      this.routeParams  = rest;
      window.scrollTo({ top: 0, behavior: 'smooth' });
      this.onRouteChange(route, rest);
    },

    navigate(path) {
      // Thin progress bar — no backdrop-blur, no GPU lag
      const bar = document.getElementById('nav-progress');
      if (bar) {
        bar.style.transition = 'none';
        bar.style.width = '0%';
        bar.style.opacity = '1';
        requestAnimationFrame(() => {
          bar.style.transition = 'width 0.25s cubic-bezier(0.4,0,0.2,1)';
          bar.style.width = '75%';
        });
      }
      window.location.hash = '#' + path;
    },

    _finishProgress() {
      const bar = document.getElementById('nav-progress');
      if (!bar) return;
      bar.style.transition = 'width 0.1s ease-out';
      bar.style.width = '100%';
      setTimeout(() => {
        bar.style.transition = 'opacity 0.15s ease-in';
        bar.style.opacity = '0';
        setTimeout(() => { bar.style.width = '0%'; }, 160);
      }, 110);
    },

    onRouteChange(route, params) {
      // Guard protected routes — hanya aktif setelah _userLoaded = true
      // Sebelum user selesai di-load, skip semua guard agar tidak ada redirect paksa
      if (this._userLoaded) {
        const protected_routes = ['/dashboard', '/history', '/classroom', '/profile', '/settings', '/challenges', '/messages'];
        const admin_routes     = ['/admin'];
        const auth_only_routes = ['/login', '/register'];

        // User sudah login → jangan ke halaman login / register
        if (this.user && auth_only_routes.some(r => route.startsWith(r))) {
          return this.navigate('/dashboard');
        }
        if (route === '/onboarding' && !this.user) return this.navigate('/login');
        if (protected_routes.some(r => route.startsWith(r)) && !this.user) {
          this.showToast('Silakan login untuk mengakses halaman ini', 'warning', '⚠️');
          return this.navigate('/login');
        }
        if (admin_routes.some(r => route.startsWith(r)) && this.user?.role !== 'admin') {
          this.showToast('Akses ditolak', 'error', '⛔');
          return this.navigate('/');
        }
      }
      // Load data per route
      if (route === '/')                   this.loadHome();
      if (route === '/categories')         this.loadCategories();
      if (route === '/quizzes')            this.loadQuizzes();
      if (route.startsWith('/quiz/'))      this.loadQuizDetail(params[0]);
      if (route.startsWith('/play/'))      { this._finishProgress(); return; } // Quiz engine handles its own load via x-init
      if (route === '/leaderboard')        this.loadLeaderboard();
      if (route === '/dashboard')          this.loadDashboard();
      if (route === '/history')            this.loadHistory();
      if (route === '/profile')            this.loadDashboard(); // reuse dashboard stats
      if (route === '/settings')           this.loadSettings();
      if (route === '/google-setup')       this.loadGoogleSetup();
      if (route.startsWith('/result/'))    this.loadResult(params[0]);
      if (route.startsWith('/admin'))      this.loadAdminTab(this.admin.tab);
      if (route === '/classroom')          this.loadClassroom();
      if (route.startsWith('/classroom/') && params[0]) this.loadClassroomDetail(params[0]);
      if (route === '/challenges')         this.loadChallenges();
      if (route === '/activity')            this.loadActivity();
      if (route === '/public-history')     this.loadPublicHistory();
      if (!this.currentRoute.includes('/monitor') && this.assignmentView && this.assignmentView.monitorInterval) {
        clearInterval(this.assignmentView.monitorInterval);
        this.assignmentView.monitorInterval = null;
      }
      if (/^\/assignment\/\d+\/results$/.test(route)) this.loadAssignmentResults(params[0]);
      if (/^\/assignment\/\d+\/monitor$/.test(route)) this.loadAssignmentMonitor(params[0]);
      if (route === '/messages') {
        // Refresh thread list
        this.loadMsgThreads();
        // Jika ada thread aktif, reload chat-nya dan scroll ke bawah
        if (this.msgs.activeThread) {
          this.loadChat(this.msgs.activeThread.id, 1).then(() => this._scrollChatBottom());
          if (!this.msgs.pollInterval) this._startMsgPoll(this.msgs.activeThread.id);
        }
      } else {
        // Saat meninggalkan halaman pesan, hentikan polling
        if (this.msgs.pollInterval) this.clearMsgPoll();
      }
      if (route === '/search') {
        if (this.search.q.trim().length >= 2) this.loadSearch(this.search.q);
        else this.search.results = [];
      }

      // Complete progress bar
      this._finishProgress();
    },

    // ---- Auth ----
    async loadUser() {
      try {
        const data = await api.get('auth.me');
        // API me returns flat user object with csrf field
        this.user = data ? {
          id:                   data.id,
          name:                 data.name,
          email:                data.email,
          role:                 data.role,
          quiz_questions_limit: data.quiz_questions_limit || 10,
          shuffle_questions:    data.shuffle_questions    ?? true,
          shuffle_options:      data.shuffle_options      ?? true,
        } : null;
        // Sync settings state
        if (this.user) {
          this.settings.limit            = this.user.quiz_questions_limit;
          this.settings.shuffleQuestions = this.user.shuffle_questions;
          this.settings.shuffleOptions   = this.user.shuffle_options;
        }
        // Simpan CSRF token dari respons auth.me agar POST langsung valid
        if (data?.csrf) api.setToken(data.csrf);
      } catch {
        this.user = null;
      }
    },

    async login() {
      const f = this.loginForm;
      if (!f.email || !f.password) { f.error = 'Email dan password wajib diisi'; return; }
      f.loading = true; f.error = '';
      try {
        // API auth.login returns flat: { id, name, email, role, csrf_token }
        const data = await api.post('auth.login', { email: f.email, password: f.password });
        this.user = { id: data.id, name: data.name, email: data.email, role: data.role };
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
        // Tidak ada auto-login — user harus verifikasi email dulu
        if (data.email_sent) {
          window.emailSentPage = { email: data.email || f.email };
          this.showToast('Cek email kamu untuk link konfirmasi 📧', 'success', '📧');
          this.navigate('/email-sent');
          // Reset form
          f.name = ''; f.email = ''; f.password = ''; f.password_confirm = '';
        }
      } catch (e) {
        f.error = e.message;
      } finally {
        f.loading = false;
      }
    },

    loginWithGoogle() {
      window.location.href = '/api/auth/google?mode=login';
    },

    registerWithGoogle() {
      window.location.href = '/api/auth/google?mode=register';
    },

    async setGoogleName() {
      const f = this.googleSetupForm;
      const name = f.customName.trim();
      if (!name) { f.error = 'Nama wajib diisi'; return; }
      f.loading = true; f.error = '';
      try {
        await api.post('auth.update_profile', { name });
        this.user.name = name;
        this.showToast('Nama berhasil disimpan!', 'success', '✅');
        this.navigate('/onboarding');
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
        const [cats, quizData, stats, groups] = await Promise.all([
          api.get('category.list'),
          api.getFull('quiz.list', { limit: 6 }),
          api.get('quiz.stats'),
          api.get('category_group.list'),
        ]);
        this.home.categories = Array.isArray(cats)    ? cats    : [];
        this.home.featured   = Array.isArray(quizData.data) ? quizData.data : [];
        this.home.stats      = stats  || {};
        this.home.groups     = Array.isArray(groups)  ? groups  : [];
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

      // Pastikan dropdown kategori terisi meskipun user langsung membuka /quizzes
      // (tanpa lewat homepage). Kalau home.categories belum dimuat, fetch sekali.
      if (!this.home.categories || this.home.categories.length === 0) {
        try {
          const cats = await api.get('category.list');
          this.home.categories = Array.isArray(cats) ? cats : [];
        } catch (_) { /* non-fatal */ }
      }

      try {
        const params = {
          page: this.quizzes.page,
          limit: 12,
          ...(this.quizzes.categoryId                   ? { category:   this.quizzes.categoryId }   : {}),
          ...(this.quizzes.search                       ? { search:     this.quizzes.search }         : {}),
          ...(this.quizzes.difficulty                   ? { difficulty: this.quizzes.difficulty }     : {}),
        };
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
        const resp = await api.getFull('leaderboard.global', { limit: 50 });
        this.leaderboard.list = Array.isArray(resp.data) ? resp.data : [];
      } catch (e) {
        this.showToast(e.message, 'error', '❌');
      } finally {
        this.leaderboard.loading = false;
      }
    },

    async loadActivity(reset = false) {
      if (reset) { this.activity.page = 1; this.activity.list = []; }
      this.activity.loading = true;
      try {
        const resp = await api.getFull('activity.feed', { page: 1, limit: 20 });
        this.activity.list  = Array.isArray(resp.data) ? resp.data : [];
        this.activity.total = resp.meta?.total || 0;
        this.activity.page  = 1;
      } catch (e) {
        this.showToast(e.message, 'error', '❌');
      } finally {
        this.activity.loading = false;
      }
    },

    async loadMoreActivity() {
      if (this.activity.loadingMore) return;
      this.activity.loadingMore = true;
      try {
        const nextPage = this.activity.page + 1;
        const resp = await api.getFull('activity.feed', { page: nextPage, limit: 20 });
        const more = Array.isArray(resp.data) ? resp.data : [];
        this.activity.list  = [...this.activity.list, ...more];
        this.activity.total = resp.meta?.total || this.activity.total;
        this.activity.page  = nextPage;
      } catch (e) {
        this.showToast(e.message, 'error', '❌');
      } finally {
        this.activity.loadingMore = false;
      }
    },

    // Shortcut: set filter lalu navigate ke /public-history
    openPublicHistory(type, id, label) {
      this.publicHistory.filterType  = type;
      this.publicHistory.filterId    = id;
      this.publicHistory.filterLabel = label;
      this.publicHistory.page        = 1;
      this.publicHistory.list        = [];
      this.publicHistory.total       = 0;
      this.navigate('/public-history');
    },

    // Helper: bangun params & action sesuai filterType
    _publicHistoryParams() {
      const params = { page: this.publicHistory.page, limit: this.publicHistory.limit };
      let action;
      if (this.publicHistory.filterType === 'user') {
        action = 'activity.user_history';
        params.user_id = this.publicHistory.filterId;
      } else if (this.publicHistory.filterType === 'quiz') {
        action = 'activity.quiz_history';
        params.quiz_id = this.publicHistory.filterId;
      } else {
        action = 'activity.mode_history';
        params.mode = this.publicHistory.filterId;
      }
      return { action, params };
    },

    async loadPublicHistory() {
      if (!this.publicHistory.filterType) return;
      this.publicHistory.loading = true;
      try {
        const { action, params } = this._publicHistoryParams();
        const resp = await api.get(action, params);
        this.publicHistory.list        = Array.isArray(resp.data) ? resp.data : [];
        this.publicHistory.total       = resp.meta?.total || 0;
        this.publicHistory.filterLabel = resp.filter?.label || this.publicHistory.filterLabel;
      } catch (e) {
        this.showToast(e.message, 'error', '❌');
      } finally {
        this.publicHistory.loading = false;
      }
    },

    async loadMorePublicHistory() {
      if (this.publicHistory.loadingMore) return;
      this.publicHistory.loadingMore = true;
      try {
        this.publicHistory.page++;
        const { action, params } = this._publicHistoryParams();
        const resp = await api.get(action, params);
        const more = Array.isArray(resp.data) ? resp.data : [];
        this.publicHistory.list  = [...this.publicHistory.list, ...more];
        this.publicHistory.total = resp.meta?.total || this.publicHistory.total;
      } catch (e) {
        this.publicHistory.page--;          // rollback page jika gagal
        this.showToast(e.message, 'error', '❌');
      } finally {
        this.publicHistory.loadingMore = false;
      }
    },

    async loadDashboard() {
      this.dashboard.loading = true;
      try {
        // API attempt.dashboard returns { user, stats, recent }
        const data = await api.get('attempt.dashboard');
        this.dashboard.userInfo = data.user   || null;
        this.dashboard.stats    = data.stats  || null;
        this.dashboard.recent   = data.recent || [];
      } catch (e) {
        this.showToast(e.message, 'error', '❌');
      } finally {
        this.dashboard.loading = false;
      }
      // Muat tugas untuk pelajar & pengajar (parallel, tidak block stats)
      if (this.user && ['pelajar', 'pengajar', 'admin'].includes(this.user.role)) {
        this.dashboard.assignmentsLoading = true;
        try {
          const aData = await api.get('assignment.my_dashboard');
          this.dashboard.assignments     = aData.assignments || [];
          this.dashboard.assignmentsRole = aData.role        || '';
        } catch (e) {
          this.dashboard.assignments = [];
        } finally {
          this.dashboard.assignmentsLoading = false;
        }
      }
    },

    async openAssignmentAttempts(assignId) {
      this.dashboardAttemptModal.show = true;
      this.dashboardAttemptModal.loading = true;
      this.dashboardAttemptModal.attempts = [];
      this.dashboardAttemptModal.assignment = null;
      this.dashboardAttemptModal.error = '';
      try {
        const data = await api.get('assignment.attempts', { id: parseInt(assignId) });
        this.dashboardAttemptModal.assignment = data.assignment || null;
        this.dashboardAttemptModal.attempts = Array.isArray(data.attempts) ? data.attempts : [];
      } catch (e) {
        this.dashboardAttemptModal.error = e.message;
      } finally {
        this.dashboardAttemptModal.loading = false;
      }
    },

    closeAssignmentAttempts() {
      this.dashboardAttemptModal.show = false;
      this.dashboardAttemptModal.attempts = [];
      this.dashboardAttemptModal.assignment = null;
      this.dashboardAttemptModal.error = '';
    },

    async loadHistory(reset = false) {
      if (reset) { this.history.page = 1; this.history.list = []; }
      this.history.loading = true;
      try {
        const resp = await api.getFull('attempt.history', { page: this.history.page, limit: 10 });
        this.history.list  = Array.isArray(resp.data) ? resp.data : [];
        this.history.total = resp.meta?.total || 0;
      } catch (e) {
        this.showToast(e.message, 'error', '❌');
      } finally {
        this.history.loading = false;
      }
    },

    async loadResult(attemptId) {
      this.result.loading = true;
      this.result.data = null;
      this.result.assignSubmitted = false;
      this.result.assignError = '';
      // Baca assignId dan challengeId dari URL hash jika ada
      const hashMatch = window.location.hash.match(/[?&]assign=(\d+)/);
      this.result.assignId = hashMatch ? hashMatch[1] : null;
      const cidMatch = window.location.hash.match(/[?&]cid=(\d+)/);
      this.result.challengeId = cidMatch ? cidMatch[1] : null;
      this.result.challengeData = null;
      const modeMatch = window.location.hash.match(/[?&]mode=([a-z]+)/);
      this.result.mode = modeMatch ? modeMatch[1] : null;
      try {
        const resp = await api.get('attempt.result', { id: attemptId });
        // API returns { attempt: {...}, answers: [...] }
        this.result.data = {
          ...resp.attempt,
          answers: resp.answers || [],
          quiz: {
            id:            resp.attempt.quiz_id,
            title:         resp.attempt.quiz_title,
            passing_score: resp.attempt.passing_score,
            time_limit:    resp.attempt.time_limit,
            category_name: resp.attempt.category_name,
          },
        };
        // Load challenge status jika ada challengeId
        if (this.result.challengeId) {
          try {
            this.result.challengeData = await api.get('challenge.status', { id: this.result.challengeId });
          } catch (_) {}
        }
      } catch (e) {
        this.showToast(e.message, 'error', '❌');
      } finally {
        this.result.loading = false;
      }
    },

    async submitToAssignment(assignmentId, attemptId) {
      this.result.assignSubmitting = true;
      this.result.assignError = '';
      try {
        await api.post('assignment.submit', {
          assignment_id: parseInt(assignmentId),
          attempt_id:    parseInt(attemptId),
        });
        this.result.assignSubmitted = true;
        this.showToast('Tugas berhasil dikumpulkan! ✅', 'success', '✅');
      } catch (e) {
        this.result.assignError = e.message;
        this.showToast(e.message, 'error', '❌');
      } finally {
        this.result.assignSubmitting = false;
      }
    },

    // ---- Classroom ----
    async loadClassroom() {
      this.classroom.loading = true;
      this.classroom.list = [];
      try {
        const data = await api.get('class.list');
        this.classroom.list = Array.isArray(data) ? data : [];
      } catch (e) {
        this.showToast(e.message, 'error', '❌');
      } finally {
        this.classroom.loading = false;
      }
    },

    async loadClassroomDetail(classId) {
      this.classroom.detailLoading = true;
      this.classroom.detail = null;
      this.classroom.members = [];
      this.classroom.assignments = [];
      try {
        const data = await api.get('class.get', { id: classId });
        this.classroom.detail      = data.class;
        this.classroom.members     = data.members || [];
        this.classroom.assignments = data.assignments || [];
        this.classroom.isTeacher   = data.is_teacher || false;
        this.pageTitle = (data.class?.name || 'Kelas') + ' — QuizB';
      } catch (e) {
        this.showToast(e.message, 'error', '❌');
      } finally {
        this.classroom.detailLoading = false;
      }
    },

    // ---- Onboarding: pilih role untuk user baru ----
    async setOnboardingRole(role) {
      try {
        await api.post('auth.set_role', { role });
        if (this.user) this.user.role = role;
        if (role === 'pelajar') {
          this.navigate('/classroom');
          await this.$nextTick?.();
          // Buka modal join kelas setelah halaman classroom dimuat
          setTimeout(() => this.openJoinClassModal(), 600);
        } else if (role === 'pengajar') {
          this.navigate('/classroom');
          setTimeout(() => this.openCreateClassModal(), 600);
        } else {
          // role = 'user' → ke beranda
          this.navigate('/');
        }
      } catch (e) {
        this.showToast(e.message, 'error', '❌');
      }
    },

    openJoinClassModal() {
      this.classroom.joinCode  = '';
      this.classroom.joinError = '';
      this.classroom.joinModal.show = true;
    },

    openCreateClassModal() {
      this.classroom.createForm  = { name: '', description: '' };
      this.classroom.createError = '';
      this.classroom.createModal.show = true;
    },

    async joinClass() {
      const code = this.classroom.joinCode.trim().toUpperCase();
      if (!code) { this.classroom.joinError = 'Masukkan kode kelas'; return; }
      this.classroom.joinLoading = true;
      this.classroom.joinError   = '';
      try {
        const data = await api.post('class.join', { join_code: code });
        this.classroom.joinModal.show = false;
        this.showToast('Berhasil bergabung ke kelas!', 'success', '✅');
        await this.loadClassroom();
      } catch (e) {
        this.classroom.joinError = e.message;
      } finally {
        this.classroom.joinLoading = false;
      }
    },

    async createClass() {
      const f = this.classroom.createForm;
      if (!f.name || f.name.length < 3) { this.classroom.createError = 'Nama kelas minimal 3 karakter'; return; }
      this.classroom.createLoading = true;
      this.classroom.createError   = '';
      try {
        await api.post('class.create', { name: f.name, description: f.description });
        this.classroom.createModal.show = false;
        this.showToast('Kelas berhasil dibuat!', 'success', '✅');
        await this.loadClassroom();
      } catch (e) {
        this.classroom.createError = e.message;
      } finally {
        this.classroom.createLoading = false;
      }
    },

    openEditClassModal(cls) {
      this.classroom.editForm  = { id: cls.id, name: cls.name, description: cls.description || '', is_active: cls.is_active };
      this.classroom.editError = '';
      this.classroom.editModal.show = true;
    },

    async updateClass() {
      const f = this.classroom.editForm;
      if (!f.name || f.name.length < 3) { this.classroom.editError = 'Nama kelas minimal 3 karakter'; return; }
      this.classroom.editLoading = true;
      this.classroom.editError   = '';
      try {
        await api.put('class.update', f.id, { name: f.name, description: f.description, is_active: f.is_active });
        this.classroom.editModal.show = false;
        this.showToast('Kelas berhasil diperbarui!', 'success', '✅');
        await this.loadClassroom();
      } catch (e) {
        this.classroom.editError = e.message;
      } finally {
        this.classroom.editLoading = false;
      }
    },

    openDeleteClassModal(cls) {
      this.classroom.deleteModal = { show: true, cls, loading: false, error: '' };
    },

    async confirmDeleteClass() {
      const dm = this.classroom.deleteModal;
      if (!dm.cls) return;
      dm.loading = true;
      dm.error   = '';
      try {
        await api.delete('class.delete', dm.cls.id);
        dm.show = false;
        this.showToast('Kelas "' + dm.cls.name + '" berhasil dihapus', 'success', '🗑️');
        await this.loadClassroom();
      } catch (e) {
        dm.error = e.message;
      } finally {
        dm.loading = false;
      }
    },

    async leaveClass(classId) {
      const lm = this.classroom.leaveModal;
      lm.loading = true;
      lm.error   = '';
      try {
        await api.delete('class.leave', classId);
        lm.show = false;
        this.showToast('Berhasil keluar dari kelas', 'success', '👋');
        this.navigate('/classroom');
      } catch (e) {
        lm.error = e.message;
      } finally {
        lm.loading = false;
      }
    },

    async kickMember(classId, userId) {
      if (!confirm('Keluarkan anggota ini dari kelas?')) return;
      try {
        await api.delete('class.kick', classId, { user_id: userId });
        this.showToast('Anggota dikeluarkan', 'success', '✅');
        await this.loadClassroomDetail(classId);
      } catch (e) {
        this.showToast(e.message, 'error', '❌');
      }
    },

    async openEditAssignModal(assign) {
      this.classroom.assignModal.editId = assign.id;
      // Ekstrak quiz_ids dari quiz_packages jika tersedia, atau fallback ke quiz_id
      let quizIds = [];
      if (assign.quiz_packages && Array.isArray(assign.quiz_packages)) {
        quizIds = assign.quiz_packages.map(p => parseInt(p.quiz_id));
      } else if (assign.quiz_id) {
        quizIds = [parseInt(assign.quiz_id)];
      }
      
      this.classroom.assignForm = {
        title:             assign.title,
        quiz_ids:          quizIds,
        mode:              assign.mode,
        deadline:          assign.deadline ? assign.deadline.replace(' ', 'T').substring(0, 16) : '',
        max_questions:     assign.max_questions != null ? assign.max_questions : '',
        shuffle_questions: assign.shuffle_questions != null ? (assign.shuffle_questions == 1 ? true : (assign.shuffle_questions == 0 ? false : null)) : null,
        shuffle_options:   assign.shuffle_options   != null ? (assign.shuffle_options   == 1 ? true : (assign.shuffle_options   == 0 ? false : null)) : null,
        timer_per_question:  assign.timer_per_question != null ? assign.timer_per_question : '',
        duration_minutes:    assign.duration_minutes   != null ? assign.duration_minutes   : '',
        require_full_score:  assign.require_full_score ? true : false,
      };
      this.classroom.assignQuizDropdownOpen = false;
      this.classroom.assignError = '';
      this.classroom.assignModal.show = true;

      // Muat daftar quiz untuk dropdown jika belum ada
      if (!this.classroom.quizListForAssign.length) {
        this.classroom.quizListLoading = true;
        try {
          let total = 0;
          try {
            const stats = await api.get('quiz.stats');
            total = parseInt(stats.total_quizzes || 0, 10) || 0;
          } catch (_) { total = 0; }

          const perPage = 50;
          let all = [];
          if (total > 0) {
            const pages = Math.max(1, Math.ceil(total / perPage));
            const promises = [];
            for (let p = 1; p <= pages; p++) {
              promises.push(api.getFull('quiz.list', { limit: perPage, page: p }));
            }
            const results = await Promise.all(promises);
            for (const r of results) {
              if (Array.isArray(r.data)) all = all.concat(r.data);
            }
          } else {
            let page = 1;
            while (true) {
              const r = await api.getFull('quiz.list', { limit: perPage, page });
              const items = Array.isArray(r.data) ? r.data : [];
              all = all.concat(items);
              if (items.length < perPage) break;
              page++;
              if (page > 200) break;
            }
          }
          try {
            all.sort((a, b) => (a.title || '').toString().localeCompare((b.title || '').toString(), undefined, { sensitivity: 'base' }));
          } catch (_) {
            all.sort((a, b) => (a.title || '').toString().toLowerCase().localeCompare((b.title || '').toString().toLowerCase()));
          }
          this.classroom.quizListForAssign = all;
        } catch (e) {
          this.classroom.quizListForAssign = [];
        } finally {
          this.classroom.quizListLoading = false;
        }
      }
    },

    async updateAssignment(classId) {
      const f  = this.classroom.assignForm;
      const id = this.classroom.assignModal.editId;
      if (!f.title || f.title.length < 3) { this.classroom.assignError = 'Judul tugas minimal 3 karakter'; return; }
      if (!f.quiz_ids || f.quiz_ids.length === 0) { this.classroom.assignError = 'Pilih minimal satu paket soal'; return; }
      this.classroom.assignLoading = true;
      this.classroom.assignError   = '';
      try {
        const maxQ     = f.max_questions !== '' && f.max_questions != null ? parseInt(f.max_questions) : null;
        // Konversi boolean JS ke integer untuk PHP: true→1, false→0, null→null
        const shuffleQ = f.shuffle_questions === true ? 1 : (f.shuffle_questions === false ? 0 : null);
        const shuffleO = f.shuffle_options   === true ? 1 : (f.shuffle_options   === false ? 0 : null);
        const timerPerQ = f.timer_per_question !== '' && f.timer_per_question != null ? parseInt(f.timer_per_question) : null;
        const durMins   = f.duration_minutes   !== '' && f.duration_minutes   != null ? parseInt(f.duration_minutes)   : null;
        
        const payload = {
          title:              f.title,
          mode:               f.mode,
          deadline:           f.deadline || null,
          max_questions:      maxQ,
          shuffle_questions:  shuffleQ,
          shuffle_options:    shuffleO,
          timer_per_question: timerPerQ,
          duration_minutes:   durMins,
          require_full_score: f.require_full_score ? 1 : 0,
          quiz_ids:           f.quiz_ids,   // selalu kirim agar packages bisa diperbarui
        };
        
        await api.put('assignment.update', id, payload);
        this.classroom.assignModal.show = false;
        this.showToast('Tugas berhasil diperbarui!', 'success', '✅');
        await this.loadClassroomDetail(classId);
      } catch (e) {
        this.classroom.assignError = e.message;
      } finally {
        this.classroom.assignLoading = false;
      }
    },

    async openAssignModal(existingAssign = null) {
      this.classroom.assignModal.editId = null;
      this.classroom.assignForm  = { 
        title: '', 
        quiz_ids: [], 
        mode: 'bebas', 
        deadline: '', 
        max_questions: '', 
        shuffle_questions: null, 
        shuffle_options: null, 
        timer_per_question: '', 
        duration_minutes: '', 
        require_full_score: false 
      };
      this.classroom.assignQuizDropdownOpen = false;
      this.classroom.assignError = '';
      this.classroom.assignModal.show = true;
      // Load quiz list for dropdown if not already loaded
      if (!this.classroom.quizListForAssign.length) {
        this.classroom.quizListLoading = true;
        try {
          // Try to get total quizzes first
          let total = 0;
          try {
            const stats = await api.get('quiz.stats');
            total = parseInt(stats.total_quizzes || 0, 10) || 0;
          } catch (_) {
            total = 0;
          }

          const perPage = 50; // server caps page size to 50
          let all = [];

          if (total > 0) {
            // Compute number of pages and fetch them (in parallel)
            const pages = Math.max(1, Math.ceil(total / perPage));
            const promises = [];
            for (let p = 1; p <= pages; p++) {
              promises.push(api.getFull('quiz.list', { limit: perPage, page: p }));
            }
            const results = await Promise.all(promises);
            for (const r of results) {
              if (Array.isArray(r.data)) all = all.concat(r.data);
            }
          } else {
            // Fallback: sequential fetch until a page returns less than perPage items
            let page = 1;
            while (true) {
              const r = await api.getFull('quiz.list', { limit: perPage, page });
              const items = Array.isArray(r.data) ? r.data : [];
              all = all.concat(items);
              if (items.length < perPage) break;
              page++;
              // safety: avoid infinite loop
              if (page > 200) break;
            }
          }

          // Sort quizzes alphabetically by title (A → Z), case-insensitive
          try {
            all.sort((a, b) => (a.title || '').toString().localeCompare((b.title || '').toString(), undefined, { sensitivity: 'base' }));
          } catch (_) {
            // Fallback: basic lower-case compare if localeCompare with options fails
            all.sort((a, b) => (a.title || '').toString().toLowerCase().localeCompare((b.title || '').toString().toLowerCase()));
          }
          this.classroom.quizListForAssign = all;
        } catch (e) {
          this.classroom.quizListForAssign = [];
        } finally {
          this.classroom.quizListLoading = false;
        }
      }
    },

    async createAssignment(classId) {
      const f = this.classroom.assignForm;
      if (!f.title || f.title.length < 3) { this.classroom.assignError = 'Judul tugas minimal 3 karakter'; return; }
      if (!f.quiz_ids || f.quiz_ids.length === 0) { this.classroom.assignError = 'Pilih minimal satu paket soal'; return; }
      this.classroom.assignLoading = true;
      this.classroom.assignError   = '';
      try {
        const maxQ     = f.max_questions !== '' && f.max_questions != null ? parseInt(f.max_questions) : null;
        const shuffleQ  = f.shuffle_questions; // null = ikuti user setting
        const shuffleO  = f.shuffle_options;
        const timerPerQ = f.timer_per_question !== '' && f.timer_per_question != null ? parseInt(f.timer_per_question) : null;
        const durMins   = f.duration_minutes   !== '' && f.duration_minutes   != null ? parseInt(f.duration_minutes)   : null;
        await api.post('assignment.create', {
          class_id:           parseInt(classId),
          quiz_ids:           f.quiz_ids,
          title:              f.title,
          mode:               f.mode,
          deadline:           f.deadline || null,
          max_questions:      maxQ,
          ...(shuffleQ  !== null ? { shuffle_questions:  shuffleQ  } : {}),
          ...(shuffleO  !== null ? { shuffle_options:    shuffleO  } : {}),
          ...(timerPerQ !== null ? { timer_per_question: timerPerQ } : {}),
          ...(durMins   !== null ? { duration_minutes:   durMins   } : {}),
          require_full_score: f.require_full_score ? 1 : 0,
        });
        this.classroom.assignModal.show = false;
        this.showToast('Tugas berhasil dibuat!', 'success', '✅');
        await this.loadClassroomDetail(classId);
      } catch (e) {
        this.classroom.assignError = e.message;
      } finally {
        this.classroom.assignLoading = false;
      }
    },

    // ---- Settings ----
    async loadSettings() {
      if (!this.user) return;
      this.settings.limit   = this.user.quiz_questions_limit || 10;
      this.settings.error   = '';
      this.settings.success = '';
    },

    async loadGoogleSetup() {
      if (!this.user) return this.navigate('/login');
      this.googleSetupForm.googleName = this.user.name || '';
      this.googleSetupForm.customName = '';
      this.googleSetupForm.error = '';
      this.googleSetupForm.loading = false;
      this._finishProgress();
    },

    async saveSettings(limit) {
      const val = parseInt(limit);
      if (!val || val < 1 || val > 100) {
        this.settings.error = 'Jumlah soal harus antara 1 dan 100';
        return;
      }
      this.settings.saving  = true;
      this.settings.error   = '';
      this.settings.success = '';
      try {
        await api.post('auth.update_settings', { quiz_questions_limit: val });
        this.settings.limit = val;
        if (this.user) this.user.quiz_questions_limit = val;
        this.settings.success = 'Pengaturan berhasil disimpan!';
        this.showToast('Pengaturan disimpan', 'success', '✅');
      } catch (e) {
        this.settings.error = e.message;
      } finally {
        this.settings.saving = false;
      }
    },

    async deleteAssignment(assignId, classId) {
      if (!confirm('Hapus tugas ini?')) return;
      try {
        await api.delete('assignment.delete', assignId);
        this.showToast('Tugas dihapus', 'success', '🗑️');
        await this.loadClassroomDetail(classId);
      } catch (e) {
        this.showToast(e.message, 'error', '❌');
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
          this.admin.quizzesView = 'list';
          const data = await api.get('admin.quiz_list', { page: this.admin.quizzesPage, limit: 15, search: this.admin.quizzesSearch });
          this.admin.quizzes      = data.quizzes || [];
          this.admin.quizzesTotal = data.total   || 0;
          // Also load categories for quiz form dropdown
          if (!this.admin.categories.length) {
            this.admin.categories = await api.get('admin.category_list');
          }
        } else if (tab === 'content') {
          this.admin.contentSearch          = '';
          this.admin.contentOpenGroups      = [];
          this.admin.contentOpenCategories  = [];
          this.admin.contentCategoryFilter  = null;
          this.admin.contentSelectedQuiz = null;
          this.admin.questionsAll        = [];
          this.admin.questionsTotal      = 0;
          const [cats, groupsData, quizData] = await Promise.all([
            api.get('admin.category_list'),
            api.get('admin.group_list'),
            api.get('admin.quiz_list', { limit: 500, search: '' }),
          ]);
          this.admin.categories        = cats;
          this.admin.allCategories     = groupsData.all_categories || cats;  // untuk modal assign
          this.admin.groups            = groupsData.groups || [];
          this.admin.contentQuizzes    = quizData.quizzes || [];
          this.admin.contentQuizCount  = quizData.total   || 0;
          this.admin.contentOpenGroups = [];
        } else if (tab === 'users') {
          const data = await api.get('admin.user_list', { page: this.admin.usersPage, limit: 15, search: this.admin.usersSearch });
          this.admin.users      = data.users  || [];
          this.admin.usersTotal = data.total  || 0;
        } else if (tab === 'categories') {
          this.admin.categories = await api.get('admin.category_list');
        } else if (tab === 'questions') {
          // Load full quiz list for filter dropdown
          if (!this.admin.quizPicker.length) {
            const d = await api.get('admin.quiz_list', { limit: 500 });
            this.admin.quizPicker = d.quizzes || [];
          }
          // Load all questions (paginated + searchable + filterable)
          const qData = await api.get('question.list_all', {
            page:    this.admin.questionsPage,
            search:  this.admin.questionsSearch,
            quiz_id: this.admin.questionsQuizFilter || '',
          });
          this.admin.questionsAll   = (qData.questions || []).map(q => ({ ...q, _sel: false }));
          this.admin.questionsTotal = qData.total     || 0;
        } else if (tab === 'groups') {
          const data = await api.get('admin.group_list');
          this.admin.groups        = data.groups         || [];
          this.admin.allCategories = data.all_categories || [];
        } else if (tab === 'review') {
          const data = await api.get('admin.review_soal');
          this.admin.review.data       = Array.isArray(data) ? data : [];
          this.admin.review.expandedId = null;
          this.admin.review.attempts   = {};
        } else if (tab === 'analysis') {
          const data = await api.get('admin.question_stats');
          this.admin.analysis = Array.isArray(data.data) ? data.data : [];
        }
      } catch (e) {
        this.showToast(e.message, 'error', '❌');
      } finally {
        this.admin.loading = false;
      }
    },

    async onAdminContentSearch(value) {
      this.admin.contentSearch = value.trim();
      await this.loadAdminTab('content');
    },

    getVisibleContentGroups() {
      const search = this.admin.contentSearch.trim().toLowerCase();
      if (!search) return this.admin.groups;
      return this.admin.groups.filter(group => {
        if (group.name?.toLowerCase().includes(search) || (group.description || '').toLowerCase().includes(search)) {
          return true;
        }
        return this.getContentGroupCategories(group).length > 0;
      });
    },

    getContentGroupCategories(group) {
      if (!group || !group.categories) return [];
      const categories = group.categories.map(cat => {
        const full = this.admin.categories.find(c => c.id === cat.id) || cat;
        return { ...full, ...cat, quiz_count: full.quiz_count ?? 0, group_id: group.id };
      });
      const search = this.admin.contentSearch.trim().toLowerCase();
      if (!search) return categories;
      if (group.name?.toLowerCase().includes(search) || (group.description || '').toLowerCase().includes(search)) {
        return categories;
      }
      return categories.filter(cat => {
        const catMatch = cat.name?.toLowerCase().includes(search) || cat.slug?.toLowerCase().includes(search);
        if (catMatch) return true;
        return this.getQuizzesByCategory(cat.id).some(quiz => {
          return quiz.title?.toLowerCase().includes(search)
            || (quiz.category_name || '').toLowerCase().includes(search)
            || (quiz.group_name || '').toLowerCase().includes(search);
        });
      });
    },

    filteredUnassignedCategories() {
      const cats = this.admin.categories.filter(cat => !cat.group_id || cat.group_id === 0);
      const search = this.admin.contentSearch.trim().toLowerCase();
      if (!search) return cats;
      return cats.filter(cat => {
        const catMatch = cat.name?.toLowerCase().includes(search) || cat.slug?.toLowerCase().includes(search);
        if (catMatch) return true;
        return this.getQuizzesByCategory(cat.id).some(quiz => {
          return quiz.title?.toLowerCase().includes(search)
            || (quiz.category_name || '').toLowerCase().includes(search)
            || (quiz.group_name || '').toLowerCase().includes(search);
        });
      });
    },

    getFilteredQuizzes() {
      let quizzes = Array.isArray(this.admin.contentQuizzes) ? [...this.admin.contentQuizzes] : [];
      if (this.admin.contentCategoryFilter) {
        quizzes = quizzes.filter(q => Number(q.category_id) === Number(this.admin.contentCategoryFilter));
      }
      const search = this.admin.contentSearch.trim().toLowerCase();
      if (!search) return quizzes;
      return quizzes.filter(quiz => {
        return quiz.title?.toLowerCase().includes(search)
          || (quiz.category_name || '').toLowerCase().includes(search)
          || (quiz.group_name || '').toLowerCase().includes(search);
      });
    },

    setContentCategoryFilter(catId) {
      this.admin.contentCategoryFilter = Number(this.admin.contentCategoryFilter) === Number(catId) ? null : catId;
    },

    getCategoryQuizCount(catId) {
      return this.admin.contentQuizzes.filter(q => Number(q.category_id) === Number(catId)).length;
    },

    getQuizzesByCategory(catId) {
      return this.admin.contentQuizzes.filter(q => Number(q.category_id) === Number(catId));
    },

    // Pilih quiz di panel kiri → muat soal ke panel kanan (full CRUD, pakai state existing)
    async selectContentQuiz(quiz) {
      if (this.admin.contentSelectedQuiz?.id === quiz.id) return;
      this.admin.contentSelectedQuiz   = quiz;
      this.admin.questionsQuizId       = quiz.id;
      this.admin.questionsQuizTitle    = quiz.title;
      this.admin.questionsQuizFilter   = quiz.id;
      this.admin.questionsPage         = 1;
      this.admin.questionsSearch       = '';
      await this.reloadQuizQuestions();
    },

    // Kembali dari soal ke daftar quiz (panel kanan)
    backToContentQuizList() {
      this.admin.contentSelectedQuiz = null;
      this.admin.questionsAll        = [];
      this.admin.questionsTotal      = 0;
      this.admin.questionsPage       = 1;
      this.admin.questionsSearch     = '';
    },

    toggleContentGroup(groupId) {
      const idx = this.admin.contentOpenGroups.indexOf(groupId);
      if (idx === -1) {
        this.admin.contentOpenGroups.push(groupId);
      } else {
        this.admin.contentOpenGroups.splice(idx, 1);
      }
    },

    toggleContentCategory(catId) {
      const idx = this.admin.contentOpenCategories.indexOf(catId);
      if (idx === -1) {
        this.admin.contentOpenCategories.push(catId);
        // Sinkronkan panel kanan agar langsung menampilkan quiz kategori ini
        this.admin.contentCategoryFilter = catId;
        this.admin.contentSelectedQuiz   = null;
        this.admin.questionsAll          = [];
        this.admin.questionsTotal        = 0;
      } else {
        this.admin.contentOpenCategories.splice(idx, 1);
        if (Number(this.admin.contentCategoryFilter) === Number(catId)) {
          this.admin.contentCategoryFilter = null;
          this.admin.contentSelectedQuiz   = null;
          this.admin.questionsAll          = [];
          this.admin.questionsTotal        = 0;
        }
      }
    },

    // ---- Sort helpers (client-side, per page) ----
    sortAdmin(tab, key) {
      const s = this.admin.sort[tab];
      if (!s) return;
      if (s.key === key) {
        s.dir = s.dir === 'asc' ? 'desc' : 'asc';
      } else {
        s.key = key;
        s.dir = 'asc';
      }
    },

    sortedAdmin(tab, arr) {
      const s = this.admin.sort?.[tab];
      if (!s || !s.key) return arr;
      return [...arr].sort((a, b) => {
        let av = a[s.key] ?? '';
        let bv = b[s.key] ?? '';
        if (typeof av === 'string') { av = av.toLowerCase(); bv = (bv + '').toLowerCase(); }
        if (av < bv) return s.dir === 'asc' ? -1 : 1;
        if (av > bv) return s.dir === 'asc' ? 1 : -1;
        return 0;
      });
    },

    sortIcon(tab, key) {
      const s = this.admin.sort?.[tab];
      if (!s || s.key !== key) return '↕';
      return s.dir === 'asc' ? '↑' : '↓';
    },

    get selectedAdminQuestionsCount() {
      return this.admin.questionsAll?.filter(q => q._sel).length || 0;
    },

    toggleSelectAllQuestions(checked) {
      this.admin.questionsAll = this.admin.questionsAll.map(q => ({ ...q, _sel: checked }));
    },

    async deleteSelectedQuestions() {
      const ids = this.admin.questionsAll.filter(q => q._sel).map(q => q.id);
      if (ids.length === 0) return;
      if (!confirm(`Yakin ingin menghapus ${ids.length} soal terpilih?`)) return;
      try {
        for (const id of ids) {
          await api.post('question.delete', { id });
        }
        this.showToast(`${ids.length} soal berhasil dihapus`, 'success', '🗑️');
        await this.reloadQuizQuestions();
      } catch (e) {
        this.showToast(e.message, 'error', '❌');
      }
    },

    // ---- Export Quiz Questions (PDF/Excel/Word) ----
    async exportQuizQuestions(format = 'pdf') {
      if (!this.admin.contentSelectedQuiz?.id) {
        this.showToast('Pilih quiz terlebih dahulu', 'warning', '⚠️');
        return;
      }

      const quizId = this.admin.contentSelectedQuiz.id;
      const quizTitle = this.admin.contentSelectedQuiz.title;

      try {
        this.admin.loading = true;
        const response = await api.get('admin.quiz_export', { id: quizId });
        const exportData = response;

        if (format === 'pdf') {
          await this.exportQuizAsPDF(exportData, quizTitle);
        } else if (format === 'excel') {
          await this.exportQuizAsExcel(exportData, quizTitle);
        } else if (format === 'word') {
          await this.exportQuizAsWord(exportData, quizTitle);
        }
      } catch (e) {
        console.error('Export error:', e);
        this.showToast(e.message || `Gagal export soal sebagai ${format.toUpperCase()}`, 'error', '❌');
      } finally {
        this.admin.loading = false;
      }
    },

    // ---- Export as PDF ----
    async exportQuizAsPDF(exportData, quizTitle) {
      // Generate PDF using html2pdf library
      if (typeof html2pdf === 'undefined') {
        // Fallback: download as JSON if html2pdf not available
        this.exportAsJSON(exportData, quizTitle);
        this.showToast('Export JSON berhasil (library PDF tidak tersedia)', 'success', '📥');
        return;
      }

      // Create HTML content for PDF
      const htmlContent = this.generatePDFContent(exportData);

      // Configure html2pdf options
      const opt = {
        margin: 10,
        filename: `${quizTitle.replace(/[^a-z0-9]/gi, '_')}_soal.pdf`,
        image: { type: 'jpeg', quality: 0.98 },
        html2canvas: { scale: 2 },
        jsPDF: { orientation: 'portrait', unit: 'mm', format: 'a4' },
      };

      // Generate and download PDF
      html2pdf().set(opt).from(htmlContent).save();
      this.showToast('Export PDF berhasil', 'success', '📄');
    },

    // ---- Export as Excel ----
    async exportQuizAsExcel(exportData, quizTitle) {
      if (typeof XLSX === 'undefined') {
        this.showToast('Library Excel tidak tersedia', 'error', '❌');
        return;
      }

      const { quiz, questions } = exportData;
      const filename = `${quizTitle.replace(/[^a-z0-9]/gi, '_')}_soal`;

      // Prepare sheets
      const sheets = {};

      // Sheet 1: Quiz Info
      const infoSheet = [
        ['INFORMASI QUIZ'],
        [],
        ['Judul', quiz.title],
        ['Kategori', quiz.category_name || '-'],
        ['Total Soal', quiz.total_questions],
        ['Tingkat Kesulitan', quiz.difficulty === 'easy' ? 'Mudah' : quiz.difficulty === 'medium' ? 'Sedang' : 'Sulit'],
        ['Waktu Limit (menit)', Math.ceil(quiz.time_limit / 60)],
        ['Nilai Kelulusan (%)', quiz.passing_score],
        ['Deskripsi', quiz.description || '-'],
        ['Diekspor pada', new Date().toLocaleString('id-ID')],
      ];
      sheets['Info'] = infoSheet;

      // Sheet 2: Soal & Jawaban
      const questionsSheet = [];
      questionsSheet.push([
        'No. Soal', 'Soal', 'Poin', 'Opsi A', 'Opsi B', 'Opsi C', 'Opsi D', 'Opsi E', 
        'Jawaban Benar', 'Penjelasan'
      ]);

      questions.forEach((q, idx) => {
        const options = ['', '', '', '', ''];
        const labels = ['A', 'B', 'C', 'D', 'E'];
        let correctLabel = '';

        q.options.forEach((opt, oIdx) => {
          if (oIdx < 5) {
            options[oIdx] = opt.option_text;
            if (opt.is_correct) {
              correctLabel = opt.label;
            }
          }
        });

        questionsSheet.push([
          idx + 1,
          q.question_text,
          q.points || '-',
          options[0] || '',
          options[1] || '',
          options[2] || '',
          options[3] || '',
          options[4] || '',
          correctLabel,
          q.explanation || '-'
        ]);
      });
      sheets['Soal'] = questionsSheet;

      // Create workbook
      const wb = XLSX.utils.book_new();

      // Add sheets
      Object.keys(sheets).forEach(sheetName => {
        const ws = XLSX.utils.aoa_to_sheet(sheets[sheetName]);
        
        // Set column widths
        const maxWidth = 50;
        const colWidths = [];
        sheets[sheetName].forEach(row => {
          row.forEach((cell, idx) => {
            const cellLength = String(cell || '').length;
            colWidths[idx] = Math.min(Math.max(colWidths[idx] || 0, cellLength + 2), maxWidth);
          });
        });
        ws['!cols'] = colWidths.map(w => ({ wch: w }));

        XLSX.utils.book_append_sheet(wb, ws, sheetName);
      });

      // Save file
      XLSX.writeFile(wb, `${filename}.xlsx`);
      this.showToast('Export Excel berhasil', 'success', '📊');
    },

    // ---- Export as Word ----
    async exportQuizAsWord(exportData, quizTitle) {
      if (typeof JSZip === 'undefined') {
        this.showToast('Library Word tidak tersedia', 'error', '❌');
        return;
      }

      const { quiz, questions } = exportData;
      const filename = `${quizTitle.replace(/[^a-z0-9]/gi, '_')}_soal`;

      try {
        // Generate HTML content untuk Word
        const htmlContent = this.generateWordContent(exportData);

        // Create DOCX structure using JSZip
        const zip = new JSZip();

        // Add document.xml
        zip.folder('word').file('document.xml', htmlContent);

        // Add _rels/.rels
        const relsContent = `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>
</Relationships>`;
        zip.folder('_rels').file('.rels', relsContent);

        // Add [Content_Types].xml
        const contentTypesContent = `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>
</Types>`;
        zip.file('[Content_Types].xml', contentTypesContent);

        // Add word/_rels/document.xml.rels
        const wordRelsContent = `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
</Relationships>`;
        zip.folder('word/_rels').file('document.xml.rels', wordRelsContent);

        // Generate BLOB and download
        const blob = await zip.generateAsync({ type: 'blob' });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = `${filename}.docx`;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(url);

        this.showToast('Export Word berhasil', 'success', '📝');
      } catch (e) {
        console.error('Word export error:', e);
        this.showToast('Gagal membuat file Word: ' + e.message, 'error', '❌');
      }
    },

    // Generate Word XML content
    generateWordContent(exportData) {
      const { quiz, questions } = exportData;
      
      // Escape XML special characters
      const escapeXml = (str) => {
        if (!str) return '';
        return String(str)
          .replace(/&/g, '&amp;')
          .replace(/</g, '&lt;')
          .replace(/>/g, '&gt;')
          .replace(/"/g, '&quot;')
          .replace(/'/g, '&apos;');
      };

      let body = '';

      // Title
      body += `<w:p>
        <w:pPr>
          <w:jc w:val="center"/>
          <w:spacing w:before="0" w:after="200"/>
          <w:pStyle w:val="Heading1"/>
        </w:pPr>
        <w:r>
          <w:rPr>
            <w:b/>
            <w:sz w:val="32"/>
          </w:rPr>
          <w:t>${escapeXml(quiz.title)}</w:t>
        </w:r>
      </w:p>`;

      // Category
      body += `<w:p>
        <w:pPr>
          <w:jc w:val="center"/>
          <w:spacing w:before="0" w:after="400"/>
        </w:pPr>
        <w:r>
          <w:rPr>
            <w:color w:val="666666"/>
          </w:rPr>
          <w:t>Kategori: ${escapeXml(quiz.category_name || '-')}</w:t>
        </w:r>
      </w:p>`;

      // Quiz info table
      body += `<w:tbl>
        <w:tblPr>
          <w:tblW w:w="9000" w:type="auto"/>
          <w:tblBorders>
            <w:top w:val="single" w:sz="12" w:space="0" w:color="000000"/>
            <w:left w:val="single" w:sz="12" w:space="0" w:color="000000"/>
            <w:bottom w:val="single" w:sz="12" w:space="0" w:color="000000"/>
            <w:right w:val="single" w:sz="12" w:space="0" w:color="000000"/>
            <w:insideH w:val="single" w:sz="12" w:space="0" w:color="000000"/>
            <w:insideV w:val="single" w:sz="12" w:space="0" w:color="000000"/>
          </w:tblBorders>
        </w:tblPr>
        <w:tr>
          <w:tc>
            <w:tcPr><w:tcW w:w="2250" w:type="dxa"/></w:tcPr>
            <w:p><w:r><w:rPr><w:b/></w:rPr><w:t>Total Soal:</w:t></w:r></w:p>
          </w:tc>
          <w:tc>
            <w:tcPr><w:tcW w:w="2250" w:type="dxa"/></w:tcPr>
            <w:p><w:r><w:t>${quiz.total_questions}</w:t></w:r></w:p>
          </w:tc>
          <w:tc>
            <w:tcPr><w:tcW w:w="2250" w:type="dxa"/></w:tcPr>
            <w:p><w:r><w:rPr><w:b/></w:rPr><w:t>Tingkat Kesulitan:</w:t></w:r></w:p>
          </w:tc>
          <w:tc>
            <w:tcPr><w:tcW w:w="2250" w:type="dxa"/></w:tcPr>
            <w:p><w:r><w:t>${quiz.difficulty === 'easy' ? 'Mudah' : quiz.difficulty === 'medium' ? 'Sedang' : 'Sulit'}</w:t></w:r></w:p>
          </w:tc>
        </w:tr>
        <w:tr>
          <w:tc>
            <w:tcPr><w:tcW w:w="2250" w:type="dxa"/></w:tcPr>
            <w:p><w:r><w:rPr><w:b/></w:rPr><w:t>Waktu Limit:</w:t></w:r></w:p>
          </w:tc>
          <w:tc>
            <w:tcPr><w:tcW w:w="2250" w:type="dxa"/></w:tcPr>
            <w:p><w:r><w:t>${Math.ceil(quiz.time_limit / 60)} menit</w:t></w:r></w:p>
          </w:tc>
          <w:tc>
            <w:tcPr><w:tcW w:w="2250" w:type="dxa"/></w:tcPr>
            <w:p><w:r><w:rPr><w:b/></w:rPr><w:t>Nilai Kelulusan:</w:t></w:r></w:p>
          </w:tc>
          <w:tc>
            <w:tcPr><w:tcW w:w="2250" w:type="dxa"/></w:tcPr>
            <w:p><w:r><w:t>${quiz.passing_score}%</w:t></w:r></w:p>
          </w:tc>
        </w:tr>
      </w:tbl>`;

      // Description
      if (quiz.description) {
        body += `<w:p>
          <w:pPr>
            <w:spacing w:before="400" w:after="400"/>
            <w:pStyle w:val="Normal"/>
          </w:pPr>
          <w:r>
            <w:rPr>
              <w:i/>
              <w:color w:val="666666"/>
            </w:rPr>
            <w:t>Deskripsi: ${escapeXml(quiz.description)}</w:t>
          </w:r>
        </w:p>`;
      }

      // Questions
      questions.forEach((q, idx) => {
        // Question heading
        body += `<w:p>
          <w:pPr>
            <w:spacing w:before="400" w:after="200"/>
            <w:pStyle w:val="Heading2"/>
          </w:pPr>
          <w:r>
            <w:rPr>
              <w:b/>
              <w:sz w:val="24"/>
            </w:rPr>
            <w:t>Soal ${idx + 1}${q.points ? ` (${q.points} poin)` : ''}</w:t>
          </w:r>
        </w:p>`;

        // Question text
        body += `<w:p>
          <w:pPr>
            <w:spacing w:before="0" w:after="200"/>
            <w:ind w:left="720"/>
          </w:pPr>
          <w:r>
            <w:t>${escapeXml(q.question_text)}</w:t>
          </w:r>
        </w:p>`;

        // Options
        q.options.forEach((opt, oIdx) => {
          const isCorrect = opt.is_correct;
          body += `<w:p>
            <w:pPr>
              <w:spacing w:before="0" w:after="100"/>
              <w:ind w:left="1440" w:hanging="360"/>
              <w:numPr>
                <w:ilvl w:val="0"/>
                <w:numId w:val="1"/>
              </w:numPr>
            </w:pPr>
            <w:r>
              <w:rPr>
                ${isCorrect ? '<w:b/>' : ''}
                ${isCorrect ? '<w:color w:val="10B981"/>' : ''}
              </w:rPr>
              <w:t>${opt.label}. ${escapeXml(opt.option_text)}${isCorrect ? ' ✓ (Jawaban Benar)' : ''}</w:t>
            </w:r>
          </w:p>`;
        });

        // Explanation
        if (q.explanation) {
          body += `<w:p>
            <w:pPr>
              <w:spacing w:before="200" w:after="200"/>
              <w:ind w:left="720"/>
              <w:pStyle w:val="Normal"/>
            </w:pPr>
            <w:r>
              <w:rPr>
                <w:i/>
                <w:color w:val="F59E0B"/>
              </w:rPr>
              <w:t>Penjelasan: ${escapeXml(q.explanation)}</w:t>
            </w:r>
          </w:p>`;
        }

        body += '<w:p><w:pPr><w:spacing w:before="0" w:after="0"/></w:pPr></w:p>';
      });

      // Footer
      body += `<w:p>
        <w:pPr>
          <w:jc w:val="center"/>
          <w:spacing w:before="400" w:after="0"/>
          <w:pStyle w:val="Normal"/>
        </w:pPr>
        <w:r>
          <w:rPr>
            <w:sz w:val="18"/>
            <w:color w:val="999999"/>
          </w:rPr>
          <w:t>Diekspor pada: ${new Date().toLocaleString('id-ID')}</w:t>
        </w:r>
      </w:p>`;

      // Complete XML document
      const xml = `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"
            xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"
            xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing"
            xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">
  <w:body>
    ${body}
  </w:body>
</w:document>`;

      return xml;
    },

    // Generate HTML content for PDF
    generatePDFContent(exportData) {
      const { quiz, questions } = exportData;

      let html = `
        <div style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">
          <!-- Header -->
          <div style="text-align: center; margin-bottom: 30px; border-bottom: 2px solid #333; padding-bottom: 15px;">
            <h1 style="margin: 0; color: #1f2937;">${quiz.title}</h1>
            <p style="margin: 5px 0; color: #6b7280; font-size: 14px;">${quiz.category_name || 'Kategori tidak ditentukan'}</p>
          </div>

          <!-- Quiz Info -->
          <div style="margin-bottom: 20px; padding: 10px; background-color: #f3f4f6; border-radius: 5px;">
            <table style="width: 100%; font-size: 12px;">
              <tr>
                <td style="width: 50%;"><strong>Total Soal:</strong> ${quiz.total_questions}</td>
                <td style="width: 50%;"><strong>Tingkat Kesulitan:</strong> ${quiz.difficulty === 'easy' ? 'Mudah' : quiz.difficulty === 'medium' ? 'Sedang' : 'Sulit'}</td>
              </tr>
              <tr>
                <td><strong>Waktu Limit:</strong> ${Math.ceil(quiz.time_limit / 60)} menit</td>
                <td><strong>Nilai Kelulusan:</strong> ${quiz.passing_score}%</td>
              </tr>
            </table>
          </div>

          ${quiz.description ? `<div style="margin-bottom: 20px; padding: 10px; background-color: #f0f9ff; border-left: 4px solid #3b82f6;"><strong>Deskripsi:</strong><br/>${quiz.description}</div>` : ''}

          <!-- Questions -->
          <div style="page-break-after: always;"></div>
      `;

      questions.forEach((q, idx) => {
        html += `
          <div style="margin-bottom: 25px; page-break-inside: avoid;">
            <div style="margin-bottom: 8px;">
              <h3 style="margin: 0; color: #1f2937; font-size: 14px;">
                <strong>Soal ${idx + 1}${q.points ? ` (${q.points} poin)` : ''}</strong>
              </h3>
              <div style="margin-top: 8px; padding: 8px; background-color: #f9fafb; border-left: 3px solid #3b82f6;">
                ${q.question_text}
              </div>
            </div>

            <!-- Options -->
            <div style="margin-left: 15px;">
              ${q.options.map((opt, oIdx) => `
                <div style="margin-bottom: 5px; font-size: 12px;">
                  <strong>${opt.label}.</strong> ${opt.option_text}
                  ${opt.is_correct ? '<span style="color: #10b981; margin-left: 10px;"><strong>✓ Jawaban Benar</strong></span>' : ''}
                </div>
              `).join('')}
            </div>

            <!-- Explanation -->
            ${q.explanation ? `
              <div style="margin-top: 10px; padding: 8px; background-color: #fef3c7; border-left: 3px solid #f59e0b; font-size: 12px;">
                <strong>Penjelasan:</strong><br/>
                ${q.explanation}
              </div>
            ` : ''}
          </div>
        `;
      });

      html += `
          <!-- Footer -->
          <div style="margin-top: 30px; padding-top: 15px; border-top: 1px solid #d1d5db; text-align: center; font-size: 11px; color: #9ca3af;">
            <p>Diekspor pada: ${new Date().toLocaleString('id-ID')}</p>
          </div>
        </div>
      `;

      return html;
    },

    // Fallback: Export as JSON
    exportAsJSON(data, filename) {
      const jsonStr = JSON.stringify(data, null, 2);
      const blob = new Blob([jsonStr], { type: 'application/json' });
      const url = URL.createObjectURL(blob);
      const link = document.createElement('a');
      link.href = url;
      link.download = `${filename.replace(/[^a-z0-9]/gi, '_')}_soal.json`;
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);
      URL.revokeObjectURL(url);
    },

    // ---- Review attempts per quiz ----
    async toggleReviewExpand(quizId) {
      if (this.admin.review.expandedId === quizId) {
        this.admin.review.expandedId = null;
        return;
      }
      this.admin.review.expandedId = quizId;
      await this.loadReviewAttempts(quizId, 1);
    },

    async loadReviewAttempts(quizId, page = 1) {
      if (!this.admin.review.attempts[quizId]) {
        this.admin.review.attempts[quizId] = { data: [], total: 0, page: 1, loading: false };
      }
      const state = this.admin.review.attempts[quizId];
      state.loading = true;
      state.page    = page;
      try {
        const resp  = await api.getFull('admin.quiz_attempts', { quiz_id: quizId, page, limit: 10 });
        state.data  = resp.data  || [];
        state.total = resp.meta?.total || 0;
      } catch (e) {
        this.showToast(e.message, 'error', '❌');
      } finally {
        state.loading = false;
      }
    },

    async loadAdminQuestions(quizId, quizTitle) {
      this.admin.questionsQuizId    = quizId;
      this.admin.questionsQuizTitle = quizTitle;
      this.admin.loading = true;
      try {
        this.admin.questions = await api.get('question.list', { quiz_id: quizId });
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
      // Pastikan groups sudah dimuat untuk dropdown di form kategori
      if ((type === 'category_create' || type === 'category_edit') && !this.admin.groups.length) {
        api.get('admin.group_list').then(d => {
          this.admin.groups = d.groups || [];
        }).catch(() => {});
      }
    },

    closeAdminModal() {
      this.admin.modal.show = false;
      this.admin.form = {};
      this.admin.formError = '';
    },

    async saveAdminForm() {
      const { type } = this.admin.modal;
      const f = this.admin.form;
      this.admin.loading = true;
      try {
        if (type === 'group_create') {
          await api.post('admin.group_create', f);
          this.showToast('Rumpun berhasil dibuat', 'success', '✅');
        } else if (type === 'group_edit') {
          await api.put('admin.group_update', f.id, f);
          this.showToast('Rumpun berhasil diperbarui', 'success', '✅');
        } else if (type === 'quiz_create' || type === 'quiz_edit') {
          // Jika kategori baru perlu dibuat terlebih dahulu
          if (f._newCat && f._newCatName && f._newCatName.trim().length >= 2) {
            const newCat = await api.post('admin.category_create', { name: f._newCatName.trim() });
            f.category_id = newCat.id;
            this.admin.categories = await api.get('admin.category_list');
          }
          if (!f.category_id) { this.admin.formError = 'Pilih atau buat kategori terlebih dahulu'; this.admin.loading = false; return; }
          if (type === 'quiz_create') {
            const newQuiz = await api.post('admin.quiz_create', f);
            this.showToast('Quiz berhasil dibuat', 'success', '✅');
            this.closeAdminModal();
            const calledFromContent = this.admin.tab === 'content';
            await this.loadAdminTab('quizzes');
            await this.openQuizQuestions(newQuiz);
            // Jika dibuat dari tab Konten, set sourceTab agar tombol back kembali ke Konten
            if (calledFromContent) this.admin.questionsSourceTab = 'content';
            return;
          } else {
            await api.put('admin.quiz_update', f.id, f);
            this.showToast('Quiz berhasil diperbarui', 'success', '✅');
          }
        } else if (type === 'category_create') {
          await api.post('admin.category_create', f);
          this.showToast('Kategori berhasil dibuat', 'success', '✅');
        } else if (type === 'category_edit') {
          await api.put('admin.category_update', f.id, f);
          this.showToast('Kategori berhasil diperbarui', 'success', '✅');
        } else if (type === 'user_edit') {
          await api.put('admin.user_update', f.id, f);
          this.showToast('User berhasil diperbarui', 'success', '✅');
        } else if (type === 'question_create') {
          await api.post('question.create', f);
          this.showToast('Soal berhasil ditambahkan', 'success', '✅');
          await this.reloadQuizQuestions();
        } else if (type === 'question_edit') {
          await api.post('question.update', f);
          this.showToast('Soal berhasil diperbarui', 'success', '✅');
          await this.reloadQuizQuestions();
        }
        this.closeAdminModal();
        if (type !== 'question_create' && type !== 'question_edit') {
          await this.loadAdminTab(this.admin.tab);
        }
      } catch (e) {
        this.admin.formError = e.message;
      } finally {
        this.admin.loading = false;
      }
    },

    async deleteAdminItem(type, id) {
      if (!confirm('Yakin ingin menghapus item ini?')) return;
      try {
        if (type === 'group')    await api.delete('admin.group_delete', id);
        if (type === 'quiz')     await api.delete('admin.quiz_delete', id);
        if (type === 'category') await api.delete('admin.category_delete', id);
        if (type === 'user')     await api.delete('admin.user_delete', id);
        if (type === 'question') {
          await api.post('question.delete', { id });
          this.showToast('Soal berhasil dihapus', 'success', '🗑️');
          await this.reloadQuizQuestions();
          return;
        }
        this.showToast('Berhasil dihapus', 'success', '🗑️');
        await this.loadAdminTab(this.admin.tab);
      } catch (e) {
        this.showToast(e.message, 'error', '❌');
      }
    },

    // ---- Group (Rumpun) assign modal ----
    openGroupAssignModal(group) {
      const assignedIds = (group.categories || []).map(c => c.id);
      this.admin.groupAssign = {
        show:     true,
        group:    group,
        selected: [...assignedIds],
      };
    },

    toggleGroupCategory(catId) {
      const idx = this.admin.groupAssign.selected.indexOf(catId);
      if (idx === -1) {
        this.admin.groupAssign.selected.push(catId);
      } else {
        this.admin.groupAssign.selected.splice(idx, 1);
      }
    },

    async saveGroupAssign() {
      const { group, selected } = this.admin.groupAssign;
      if (!group) return;
      this.admin.loading = true;
      try {
        await api.post('admin.group_assign', {
          group_id:     group.id,
          category_ids: selected,
        });
        this.admin.groupAssign.show = false;
        this.showToast('Kategori berhasil diperbarui', 'success', '✅');
        // Reload tab yang sedang aktif (content atau tab lain)
        await this.loadAdminTab(this.admin.tab);
      } catch (e) {
        this.showToast(e.message, 'error', '❌');
      } finally {
        this.admin.loading = false;
      }
    },

    // ---- Import soal dari File (Word/Excel) ----

    openImportFileModal() {
      if (!this.admin.questionsQuizFilter) {
        this.showToast('Pilih filter quiz terlebih dahulu sebagai tujuan import', 'error', '❌');
        return;
      }
      this.admin.importFile = { show: true, loading: false, step: 1, questions: [], quizId: this.admin.questionsQuizFilter };
    },

    async parseImportFile() {
      const fileInput = document.getElementById('import-file-input');
      if (!fileInput || !fileInput.files[0]) {
        this.showToast('Pilih file terlebih dahulu', 'error', '❌');
        return;
      }
      this.admin.importFile.loading = true;
      try {
        const fd = new FormData();
        fd.append('file', fileInput.files[0]);
        const data = await api.upload('question.import_file_parse', fd);
        if (!data.questions || data.questions.length === 0) {
          this.showToast('Tidak ada soal yang terbaca. Periksa format file.', 'error', '❌');
          return;
        }
        this.admin.importFile.questions = data.questions.map(q => ({ ...q, _sel: true, has_key: q.has_key || false }));
        this.admin.importFile.step = 2;
      } catch (e) {
        this.showToast(e.message, 'error', '❌');
      } finally {
        this.admin.importFile.loading = false;
      }
    },

    async saveImportFile() {
      const sel = this.admin.importFile.questions.filter(q => q._sel);
      if (!sel.length) { this.showToast('Pilih minimal satu soal', 'error', '❌'); return; }
      if (!this.admin.importFile.quizId) { this.showToast('Pilih quiz tujuan import terlebih dahulu', 'error', '❌'); return; }

      // Cek soal yang belum memiliki kunci jawaban
      const noKey = sel.filter(q => !q.options.some(o => o.is_correct));
      if (noKey.length > 0) {
        const ok = confirm(`⚠️ ${noKey.length} soal belum memiliki kunci jawaban yang dipilih.\n\nSoal tersebut akan diimpor dengan kunci jawaban kosong. Lanjutkan?`);
        if (!ok) return;
      }

      this.admin.importFile.loading = true;
      try {
        const data = await api.post('question.import_save', {
          quiz_id:   this.admin.importFile.quizId,
          questions: sel.map(({ question_text, explanation, options }) => ({ question_text, explanation, options })),
        });
        this.admin.importFile.show = false;
        this.showToast(`${data.imported} soal berhasil diimpor`, 'success', '✅');
        await this.reloadQuizQuestions();
      } catch (e) {
        this.showToast(e.message, 'error', '❌');
      } finally {
        this.admin.importFile.loading = false;
      }
    },

    toggleAllImportFile(checked) {
      this.admin.importFile.questions = this.admin.importFile.questions.map(q => ({ ...q, _sel: checked }));
    },

    // Set kunci jawaban secara manual untuk soal tertentu di halaman analisis
    setImportCorrect(qIdx, optIdx) {
      const questions = this.admin.importFile.questions;
      const q = { ...questions[qIdx] };
      q.options = q.options.map((o, i) => ({ ...o, is_correct: i === optIdx ? 1 : 0 }));
      q.has_key = true;
      questions[qIdx] = q;
      this.admin.importFile.questions = [...questions];
    },

    // ---- Import soal dari QuizB ----

    async openImportQuizbModal(quizId = null) {
      const targetQuizId = quizId || this.admin.questionsQuizFilter || this.admin.contentSelectedQuiz?.id || null;
      if (!targetQuizId && !this.admin.quizPicker.length) {
        try {
          const d = await api.get('admin.quiz_list', { limit: 500 });
          this.admin.quizPicker = d.quizzes || [];
        } catch (e) {
          this.showToast('Gagal memuat daftar quiz: ' + e.message, 'error', '❌');
        }
      }
      this.admin.importQuizb = {
        show: true, loading: true,
        themes: [], selectedThemeId: null,
        subthemes: [], selectedSubthemeId: null,
        titles: [], selectedTitleId: null, selectedTitleName: '',
        questions: [], selectedIds: [],
        quizId: targetQuizId,
      };
      try {
        const data = await api.get('question.browse_quizb');
        this.admin.importQuizb.themes = data.themes || [];
      } catch (e) {
        this.showToast(e.message, 'error', '❌');
        this.admin.importQuizb.show = false;
      } finally {
        this.admin.importQuizb.loading = false;
      }
    },

    onQuizbThemeChange() {
      const th = this.admin.importQuizb.themes.find(t => t.id == this.admin.importQuizb.selectedThemeId);
      this.admin.importQuizb.subthemes         = th ? th.subthemes : [];
      this.admin.importQuizb.selectedSubthemeId = null;
      this.admin.importQuizb.titles            = [];
      this.admin.importQuizb.selectedTitleId   = null;
      this.admin.importQuizb.questions         = [];
      this.admin.importQuizb.selectedIds       = [];
    },

    onQuizbSubthemeChange() {
      const sub = this.admin.importQuizb.subthemes.find(s => s.id == this.admin.importQuizb.selectedSubthemeId);
      this.admin.importQuizb.titles          = sub ? sub.titles : [];
      this.admin.importQuizb.selectedTitleId = null;
      this.admin.importQuizb.questions       = [];
      this.admin.importQuizb.selectedIds     = [];
    },

    async loadQuizbQuestions() {
      const tid = this.admin.importQuizb.selectedTitleId;
      if (!tid) return;
      const title = this.admin.importQuizb.titles.find(t => t.id == tid);
      this.admin.importQuizb.selectedTitleName = title ? title.title : '';
      this.admin.importQuizb.loading   = true;
      this.admin.importQuizb.questions = [];
      this.admin.importQuizb.selectedIds = [];
      try {
        const data = await api.get('question.browse_quizb', { title_id: tid });
        this.admin.importQuizb.questions   = data.questions || [];
        this.admin.importQuizb.selectedIds = (data.questions || []).map(q => q.id);
      } catch (e) {
        this.showToast(e.message, 'error', '❌');
      } finally {
        this.admin.importQuizb.loading = false;
      }
    },

    toggleQuizbQuestion(qId) {
      const idx = this.admin.importQuizb.selectedIds.indexOf(qId);
      if (idx === -1) this.admin.importQuizb.selectedIds.push(qId);
      else            this.admin.importQuizb.selectedIds.splice(idx, 1);
    },

    toggleAllQuizb(checked) {
      this.admin.importQuizb.selectedIds = checked
        ? this.admin.importQuizb.questions.map(q => q.id)
        : [];
    },

    async saveImportQuizb() {
      const ids = this.admin.importQuizb.selectedIds;
      if (!ids.length) { this.showToast('Pilih minimal satu soal', 'error', '❌'); return; }
      if (!this.admin.importQuizb.quizId) { this.showToast('Pilih quiz tujuan import terlebih dahulu', 'error', '❌'); return; }
      this.admin.importQuizb.loading = true;
      try {
        const data = await api.post('question.import_quizb', {
          quiz_id:      this.admin.importQuizb.quizId,
          question_ids: ids,
        });
        this.admin.importQuizb.show = false;
        this.showToast(`${data.imported} soal berhasil diimpor dari QuizB`, 'success', '✅');
        await this.reloadQuizQuestions();
      } catch (e) {
        this.showToast(e.message, 'error', '❌');
      } finally {
        this.admin.importQuizb.loading = false;
      }
    },


    // ---- Quiz questions sub-view ----
    async openQuizQuestions(quiz) {
      this.admin.questionsSourceTab  = 'quizzes';   // default; openContentQuizQuestions override di bawah
      this.admin.questionsQuizId     = quiz.id;
      this.admin.questionsQuizTitle  = quiz.title;
      this.admin.questionsQuizFilter = quiz.id;
      this.admin.questionsPage       = 1;
      this.admin.questionsSearch     = '';
      this.admin.quizzesView         = 'questions';
      await this.reloadQuizQuestions();
    },

    async reloadQuizQuestions() {
      this.admin.loading = true;
      try {
        const qData = await api.get('question.list_all', {
          page:    this.admin.questionsPage,
          search:  this.admin.questionsSearch,
          quiz_id: this.admin.questionsQuizFilter,
        });
        this.admin.questionsAll   = (qData.questions || []).map(q => ({ ...q, _sel: false }));
        this.admin.questionsTotal = qData.total     || 0;
      } catch (e) {
        this.showToast(e.message, 'error', '❌');
      } finally {
        this.admin.loading = false;
      }
    },

    // ---- Buka Kelola Soal dari tab Content ----
    async openContentQuizQuestions(quiz) {
      this.admin.tab = 'quizzes';
      await this.openQuizQuestions(quiz);
      // Override setelah openQuizQuestions (yang default-nya set ke 'quizzes')
      this.admin.questionsSourceTab = 'content';
    },

    // ---- Kembali dari halaman soal ke tab sumber ----
    async backFromQuestions() {
      if (this.admin.questionsSourceTab === 'content') {
        this.admin.quizzesView = 'list';
        this.admin.questionsSearch = '';
        this.admin.questionsPage = 1;
        await this.loadAdminTab('content');
      } else {
        this.admin.quizzesView = 'list';
        this.admin.questionsSearch = '';
        this.admin.questionsPage = 1;
      }
    },

    // ---- Admin live search ----
    onAdminQuizSearch: debounce(async function(q) {
      this.admin.quizzesSearch = q;
      this.admin.quizzesPage   = 1;
      await this.loadAdminTab('quizzes');
    }, 300),

    onAdminUserSearch: debounce(async function(q) {
      this.admin.usersSearch = q;
      this.admin.usersPage   = 1;
      await this.loadAdminTab('users');
    }, 300),

    onAdminQuestionSearch: debounce(async function(q) {
      this.admin.questionsSearch = q;
      this.admin.questionsPage   = 1;
      await this.reloadQuizQuestions();
    }, 300),

    onAdminReviewSearch(q) {
      this.admin.review.search = q;
      this.admin.review.page   = 1;
    },

    // Client-side filter + sort + paginate for Review Soal tab
    filteredReview() {
      const search = (this.admin.review.search || '').toLowerCase();
      let data = this.admin.review.data;
      if (search) {
        data = data.filter(item => (item.title || '').toLowerCase().includes(search));
      }
      const s = this.admin.sort.review;
      if (s && s.key) {
        data = [...data].sort((a, b) => {
          let av = a[s.key] ?? '';
          let bv = b[s.key] ?? '';
          if (typeof av === 'string') { av = av.toLowerCase(); bv = (bv + '').toLowerCase(); }
          if (av < bv) return s.dir === 'asc' ? -1 : 1;
          if (av > bv) return s.dir === 'asc' ? 1 : -1;
          return 0;
        });
      }
      const pp   = this.admin.review.perPage;
      const page = this.admin.review.page;
      return data.slice((page - 1) * pp, page * pp);
    },

    reviewFilteredTotal() {
      const search = (this.admin.review.search || '').toLowerCase();
      if (!search) return this.admin.review.data.length;
      return this.admin.review.data.filter(item =>
        (item.title || '').toLowerCase().includes(search)
      ).length;
    },

    // User history modal
    async loadUserHistory(u) {
      // Reset — jika user berbeda, ambil ulang semua data
      const uh = this.admin.userHistory;
      if (!uh.show || uh.user?.id !== u.id) {
        uh.user        = u;
        uh.page        = 1;
        uh.allAttempts = [];
        uh.sort        = { key: '', dir: 'asc' };
      }
      uh.show    = true;
      uh.loading = true;
      try {
        // Ambil SEMUA data sekaligus — sort & paginate sepenuhnya di client
        const resp     = await api.getFull('admin.user_history', { user_id: u.id, page: 1, limit: 9999 });
        uh.allAttempts = resp.data || [];
      } catch (e) {
        this.showToast(e.message, 'error', '❌');
      } finally {
        uh.loading = false;
      }
    },

    sortUserHistory(key) {
      const s = this.admin.userHistory.sort;
      if (s.key === key) { s.dir = s.dir === 'asc' ? 'desc' : 'asc'; }
      else               { s.key = key; s.dir = 'asc'; }
      this.admin.userHistory.page = 1; // reset ke hal.1 setelah sort
    },

    sortIconUH(key) {
      const s = this.admin.userHistory.sort;
      if (s.key !== key) return '↕';
      return s.dir === 'asc' ? '↑' : '↓';
    },

    sortedUserHistory() {
      const uh = this.admin.userHistory;
      const s  = uh.sort;
      let data = uh.allAttempts;
      if (s.key) {
        data = [...data].sort((a, b) => {
          let av = a[s.key] ?? '';
          let bv = b[s.key] ?? '';
          if (typeof av === 'string') { av = av.toLowerCase(); bv = (bv + '').toLowerCase(); }
          if (av < bv) return s.dir === 'asc' ? -1 : 1;
          if (av > bv) return s.dir === 'asc' ? 1 : -1;
          return 0;
        });
      }
      // Paginate dari data yang sudah disort
      const from = (uh.page - 1) * uh.perPage;
      return data.slice(from, from + uh.perPage);
    },

    async exportUserHistoryExcel(u) {
      this.admin.userHistory.exporting = true;
      try {
        // Gunakan allAttempts yang sudah ada — tidak perlu fetch ulang
        const uh  = this.admin.userHistory;
        const s   = uh.sort;
        let rows  = uh.allAttempts;
        // Terapkan urutan sort yang sedang aktif ke export
        if (s.key) {
          rows = [...rows].sort((a, b) => {
            let av = a[s.key] ?? '';
            let bv = b[s.key] ?? '';
            if (typeof av === 'string') { av = av.toLowerCase(); bv = (bv + '').toLowerCase(); }
            if (av < bv) return s.dir === 'asc' ? -1 : 1;
            if (av > bv) return s.dir === 'asc' ? 1 : -1;
            return 0;
          });
        }
        const modeLabel = { exam: 'Ujian', instant: 'Instan', end: 'Akhir', challenge: 'Tantangan' };
        const headers   = ['Quiz', 'Mode', 'Skor (%)', 'Benar', 'Waktu (dtk)', 'Selesai'];
        const csv = [
          headers.join(','),
          ...rows.map(r => [
            '"' + (r.quiz_title || '').replace(/"/g, '""') + '"',
            modeLabel[r.mode] || r.mode || '-',
            r.score,
            r.correct_count ?? '-',
            r.time_taken || 0,
            '"' + (r.completed_at || '') + '"',
          ].join(','))
        ].join('\n');
        const blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8;' });
        const url  = URL.createObjectURL(blob);
        const a    = document.createElement('a');
        a.href     = url;
        a.download = 'riwayat_' + (u.name || 'user').replace(/\s+/g, '_') + '.csv';
        a.click();
        URL.revokeObjectURL(url);
        this.showToast('File berhasil diunduh (' + rows.length + ' baris)', 'success', '✅');
      } catch (e) {
        this.showToast(e.message, 'error', '❌');
      } finally {
        this.admin.userHistory.exporting = false;
      }
    },

    // Helper to build question form with blank options
    buildQuestionForm(quizId) {
      return {
        quiz_id: quizId || this.admin.questionsQuizFilter || null,
        question_text: '',
        type: 'multiple',
        points: 10,
        explanation: '',
        options: [
          { option_text: '', is_correct: true },
          { option_text: '', is_correct: false },
          { option_text: '', is_correct: false },
          { option_text: '', is_correct: false },
        ],
      };
    },

    addOption() {
      if (this.admin.form.options && this.admin.form.options.length < 5) {
        this.admin.form.options = [...this.admin.form.options, { option_text: '', is_correct: false }];
      }
    },

    removeOption(index) {
      if (this.admin.form.options && this.admin.form.options.length > 2) {
        this.admin.form.options = this.admin.form.options.filter((_, i) => i !== index);
      }
    },

    setCorrectOption(index) {
      if (!this.admin.form.options) return;
      this.admin.form.options = this.admin.form.options.map((o, i) => ({
        ...o,
        is_correct: i === index,
      }));
    },

    // ---- Challenge ----
    async loadChallenges() {
      if (!this.user) return;
      this.challenge.loading = true;
      try {
        const data = await api.get('challenge.list');
        this.challenge.incoming     = data.incoming      || [];
        this.challenge.received     = data.received      || [];
        this.challenge.outgoing     = data.outgoing      || [];
        this.challenge.pendingCount = data.pending_count || 0;
      } catch (_) {
        // silently fail (polling — jangan ganggu UX)
      } finally {
        this.challenge.loading = false;
      }
    },

    async acceptChallenge(challengeId, quizId) {
      try {
        await api.post('challenge.accept', { challenge_id: parseInt(challengeId) });
        this.showToast('Tantangan diterima! Mulai bermain.', 'success', '⚔️ ');
        this.navigate('/play/' + quizId + '?mode=challenge&cid=' + challengeId);
      } catch (e) {
        this.showToast(e.message, 'error', '❌');
      }
    },

    // ---- Assignment Results & Monitor ----
    async loadAssignmentResults(id) {
      this.assignmentView.loading = true;
      this.assignmentView.results = null;
      try {
        const data = await api.get('assignment.results', { id });
        this.assignmentView.results = data;
        this.assignmentView.assignment = data.assignment;
      } catch (e) {
        this.showToast(e.message, 'error', '❌');
      } finally {
        this.assignmentView.loading = false;
      }
    },

    async loadAssignmentMonitor(id) {
      this.assignmentView.loading = true;
      this.assignmentView.monitor = null;
      clearInterval(this.assignmentView.monitorInterval);
      const doFetch = async () => {
        try {
          const data = await api.get('assignment.monitor', { id });
          this.assignmentView.monitor    = data;
          this.assignmentView.assignment = data.assignment;
        } catch (e) {
          this.showToast(e.message, 'error', '❌');
        } finally {
          this.assignmentView.loading = false;
        }
      };
      await doFetch();
      this.assignmentView.monitorInterval = setInterval(doFetch, 5000);
    },

    exportResultsToExcel() {
      const results = this.assignmentView.results;
      const assignment = this.assignmentView.assignment;
      if (!results || !results.submissions) return;

      const title = assignment?.title || 'Hasil Tugas';
      const now = new Date();
      const dateStr = now.toLocaleDateString('id-ID', { day: '2-digit', month: '2-digit', year: 'numeric' }).replace(/\//g, '-');

      // === Sheet 1: Ringkasan ===
      const summaryData = [
        ['Judul Tugas', title],
        ['Tanggal Export', now.toLocaleString('id-ID')],
        [''],
        ['Total Siswa', results.total_members],
        ['Sudah Mengumpulkan', results.submitted],
        ['Belum Mengumpulkan', results.not_submitted],
        ['Rata-rata Skor', results.avg_score || '—'],
      ];

      // === Sheet 2: Detail Siswa ===
      const headers = ['No', 'Nama Siswa', 'Skor', 'Jawaban Benar', 'Waktu (menit)', 'Waktu (detik)', 'Status', 'Waktu Kumpul'];
      const rows = results.submissions.map((s, idx) => {
        const submitted = !!s.submitted_at;
        const mins = s.time_taken ? Math.floor(s.time_taken / 60) : null;
        const secs = s.time_taken ? s.time_taken % 60 : null;
        const waktuKumpul = s.submitted_at
          ? new Date(s.submitted_at).toLocaleString('id-ID')
          : '—';
        return [
          idx + 1,
          s.student_name,
          submitted ? s.score : '—',
          submitted && s.correct_count !== null ? s.correct_count : '—',
          mins !== null ? mins : '—',
          secs !== null ? secs : '—',
          submitted ? 'Sudah Kumpul' : 'Belum Kumpul',
          waktuKumpul,
        ];
      });

      const wb = XLSX.utils.book_new();

      // Sheet Ringkasan
      const wsSummary = XLSX.utils.aoa_to_sheet(summaryData);
      wsSummary['!cols'] = [{ wch: 25 }, { wch: 30 }];
      XLSX.utils.book_append_sheet(wb, wsSummary, 'Ringkasan');

      // Sheet Detail
      const wsDetail = XLSX.utils.aoa_to_sheet([headers, ...rows]);
      wsDetail['!cols'] = [
        { wch: 5 }, { wch: 28 }, { wch: 8 }, { wch: 15 },
        { wch: 14 }, { wch: 14 }, { wch: 18 }, { wch: 22 },
      ];
      XLSX.utils.book_append_sheet(wb, wsDetail, 'Detail Siswa');

      const fileName = `Hasil_Tugas_${title.replace(/[^a-zA-Z0-9\s]/g, '').trim().replace(/\s+/g, '_')}_${dateStr}.xlsx`;
      XLSX.writeFile(wb, fileName);
      this.showToast('File Excel berhasil diunduh!', 'success', '📊');
    },

    async exportClassroomToExcel(classId) {
      if (!classId) return;
      this.showToast('Menyiapkan data...', 'info', '⏳');
      try {
        const data = await api.get('assignment.class_report', { class_id: classId });
        const { class: cls, members, assignments, scores } = data;

        if (!assignments || assignments.length === 0) {
          this.showToast('Belum ada tugas di kelas ini', 'warning', '📋');
          return;
        }

        const now     = new Date();
        const dateStr = now.toLocaleDateString('id-ID', { day: '2-digit', month: '2-digit', year: 'numeric' }).replace(/\//g, '-');
        const safe    = s => (s || '').replace(/[^a-zA-Z0-9\s]/g, '').trim().replace(/\s+/g, '_');
        const fmtTime = s => s ? (Math.floor(s / 60) + 'm ' + (s % 60) + 's') : '—';

        const wb = XLSX.utils.book_new();

        // ── Sheet 1: Rekap Nilai (pivot table siswa × tugas) ──────────────────
        const rekapHeader = [
          'No', 'Nama Siswa', 'Email',
          ...assignments.map(a => a.title),
          'Rata-rata', 'Total Tugas Dikerjakan'
        ];

        const rekapRows = members.map((m, idx) => {
          const memberScores = assignments.map(a => {
            const s = (scores[m.id] || {})[a.id];
            return s && s.submitted_at ? s.score : null;
          });
          const doneScores = memberScores.filter(v => v !== null);
          const avg = doneScores.length > 0
            ? Math.round(doneScores.reduce((a, b) => a + b, 0) / doneScores.length)
            : null;

          return [
            idx + 1,
            m.name,
            m.email,
            ...memberScores.map(v => v !== null ? v : '—'),
            avg !== null ? avg : '—',
            `${doneScores.length} / ${assignments.length}`,
          ];
        });

        const wsRekap = XLSX.utils.aoa_to_sheet([
          [`Rekap Nilai Kelas: ${cls.name}`],
          [`Diekspor: ${now.toLocaleString('id-ID')}`],
          [],
          rekapHeader,
          ...rekapRows,
        ]);

        // Lebar kolom rekap
        wsRekap['!cols'] = [
          { wch: 5 }, { wch: 28 }, { wch: 28 },
          ...assignments.map(() => ({ wch: 18 })),
          { wch: 12 }, { wch: 22 },
        ];
        XLSX.utils.book_append_sheet(wb, wsRekap, 'Rekap Nilai');

        // ── Sheet 2: Detail Lengkap (semua kolom per tugas) ───────────────────
        const detailHeader = [
          'No', 'Nama Siswa', 'Email',
          'Nama Tugas', 'Mode', 'Deadline',
          'Skor', 'Jawaban Benar', 'Waktu Pengerjaan', 'Waktu Kumpul', 'Status'
        ];

        const detailRows = [];
        let no = 1;
        for (const a of assignments) {
          for (const m of members) {
            const s = (scores[m.id] || {})[a.id];
            const submitted = s && s.submitted_at;
            detailRows.push([
              no++,
              m.name,
              m.email,
              a.title,
              { bebas: 'Bebas', instant: 'Instan', end: 'Akhir', exam: 'Ujian' }[a.mode] || a.mode,
              a.deadline ? new Date(a.deadline).toLocaleString('id-ID') : '—',
              submitted ? s.score : '—',
              submitted && s.correct_count !== null ? s.correct_count : '—',
              submitted ? fmtTime(s.time_taken) : '—',
              submitted ? new Date(s.submitted_at).toLocaleString('id-ID') : '—',
              submitted ? 'Sudah Kumpul' : 'Belum Kumpul',
            ]);
          }
        }

        const wsDetail = XLSX.utils.aoa_to_sheet([detailHeader, ...detailRows]);
        wsDetail['!cols'] = [
          { wch: 5 }, { wch: 28 }, { wch: 28 }, { wch: 28 }, { wch: 10 }, { wch: 20 },
          { wch: 8 }, { wch: 15 }, { wch: 18 }, { wch: 22 }, { wch: 15 },
        ];
        XLSX.utils.book_append_sheet(wb, wsDetail, 'Detail Lengkap');

        // ── Sheet 3+: Satu sheet per tugas ───────────────────────────────────
        for (const a of assignments) {
          const sheetName = a.title.replace(/[\\\/\?\*\[\]]/g, '').substring(0, 31);
          const header = ['No', 'Nama Siswa', 'Email', 'Skor', 'Jawaban Benar', 'Waktu', 'Waktu Kumpul', 'Status'];
          const rows = members.map((m, idx) => {
            const s = (scores[m.id] || {})[a.id];
            const submitted = s && s.submitted_at;
            return [
              idx + 1,
              m.name,
              m.email,
              submitted ? s.score : '—',
              submitted && s.correct_count !== null ? s.correct_count : '—',
              submitted ? fmtTime(s.time_taken) : '—',
              submitted ? new Date(s.submitted_at).toLocaleString('id-ID') : '—',
              submitted ? 'Sudah Kumpul' : 'Belum Kumpul',
            ];
          });
          const ws = XLSX.utils.aoa_to_sheet([header, ...rows]);
          ws['!cols'] = [
            { wch: 5 }, { wch: 28 }, { wch: 28 }, { wch: 8 },
            { wch: 15 }, { wch: 16 }, { wch: 22 }, { wch: 15 },
          ];
          XLSX.utils.book_append_sheet(wb, ws, sheetName);
        }

        const fileName = `Nilai_Kelas_${safe(cls.name)}_${dateStr}.xlsx`;
        XLSX.writeFile(wb, fileName);
        this.showToast('File Excel kelas berhasil diunduh!', 'success', '📊');
      } catch (e) {
        this.showToast(e.message || 'Gagal mengekspor data', 'error', '❌');
      }
    },

    async forceStopStudent(assignmentId, studentId, studentName) {
      if (!confirm('Hentikan paksa pekerjaan ' + studentName + '?')) return;
      try {
        await api.post('assignment.force_stop', {
          assignment_id: parseInt(assignmentId),
          student_id:    parseInt(studentId),
        });
        this.showToast(studentName + ' berhasil dihentikan', 'success', '🛑');
      } catch (e) {
        this.showToast(e.message, 'error', '❌');
      }
    },

    async declineChallenge(challengeId) {
      try {
        await api.post('challenge.decline', { challenge_id: parseInt(challengeId) });
        this.showToast('Tantangan ditolak.', 'info', '⛔');
        await this.loadChallenges();
      } catch (e) {
        this.showToast(e.message, 'error', '❌');
      }
    },

    async deleteChallenge(challengeId, section) {
      // section: 'incoming' | 'received' | 'outgoing'
      if (!confirm('Hapus tantangan ini? Data tidak bisa dikembalikan.')) return;
      try {
        await api.delete('challenge.delete', challengeId);
        this.showToast('Tantangan berhasil dihapus.', 'success', '🗑️');
        // Hapus dari array lokal tanpa fetch ulang agar lebih cepat
        if (section === 'incoming')  this.challenge.incoming  = this.challenge.incoming.filter(c => c.id !== challengeId);
        if (section === 'received')  this.challenge.received  = this.challenge.received.filter(c => c.id !== challengeId);
        if (section === 'outgoing')  this.challenge.outgoing  = this.challenge.outgoing.filter(c => c.id !== challengeId);
        this.challenge.pendingCount = this.challenge.incoming.length;
      } catch (e) {
        this.showToast(e.message, 'error', '❌');
      }
    },


    // ============================================================
    // NOTIFICATIONS
    // ============================================================
    async loadNotifications() {
      this.notif.loading = true;
      try {
        const resp = await api.getFull('notification.list', { page: this.notif.page, limit: 30 });
        this.notif.list  = resp.data       || [];
        this.notif.total = resp.meta?.total || 0;
      } catch (e) {
        console.error('loadNotifications:', e);
      } finally {
        this.notif.loading = false;
      }
    },

    async markAllNotifRead() {
      try {
        await api.post('notification.mark_read', { all: true });
        this.notif.list = this.notif.list.map(n => ({ ...n, is_read: 1 }));
        this.notif.unreadCount = 0;
      } catch (e) { /* silent */ }
    },

    async clearReadNotif() {
      try {
        await api.delete('notification.delete', 0); // 0 = clear all read (backend checks)
        this.notif.list = this.notif.list.filter(n => !n.is_read);
        this.notif.total = this.notif.list.length;
      } catch (e) { /* silent */ }
    },

    async clickNotif(n) {
      if (!n.is_read) {
        try {
          await api.post('notification.mark_read', { id: n.id });
          n.is_read = 1;
          this.notif.unreadCount = Math.max(0, this.notif.unreadCount - 1);
        } catch (e) { /* silent */ }
      }
      this.notif.show = false;

      // Notif new_user → buka public-history dengan filter user yang baru daftar
      if (n.type === 'new_user' && n.link) {
        const m = n.link.match(/user_id=(\d+)/);
        if (m) {
          this.openPublicHistory('user', parseInt(m[1]), '');
          return;
        }
      }

      if (n.link) this.navigate(n.link);
    },

    async pollCounts() {
      const fetchCounts = async () => {
        if (!this.user) return;
        try {
          const data = await api.get('notification.counts');
          this.notif.unreadCount = data.notifications || 0;
          this.msgs.unreadCount  = data.messages       || 0;
        } catch (e) { /* silent */ }
      };
      await fetchCounts();
      setInterval(fetchCounts, 20000);
    },

    // ============================================================
    // MESSAGES
    // ============================================================
    async loadMsgThreads() {
      this.msgs.loading = true;
      try {
        const data = await api.get('message.threads');
        this.msgs.threads = Array.isArray(data) ? data : (data?.data || []);
      } catch (e) {
        console.error('loadMsgThreads:', e);
      } finally {
        this.msgs.loading = false;
      }
    },

    async openThread(th) {
      // Kurangi unreadCount segera sebelum server merespons
      const wasUnread = (int => int > 0 ? int : 0)(th.unread_count || 0);
      if (wasUnread > 0) {
        this.msgs.unreadCount = Math.max(0, this.msgs.unreadCount - wasUnread);
      }

      this.msgs.activeThread = th;
      this.msgs.chat = [];
      this.msgs.chatPage = 1;
      this.msgs.chatTotal = 0;
      await this.loadChat(th.id, 1);
      this._startMsgPoll(th.id);

      // Tandai thread lokal sebagai sudah dibaca
      const t = this.msgs.threads.find(t => t.id === th.id);
      if (t) t.unread_count = 0;

      // Refresh count dari server untuk akurasi
      try {
        const counts = await api.get('notification.counts');
        this.msgs.unreadCount = counts.messages || 0;
        this.notif.unreadCount = counts.notifications || 0;
      } catch (_) {}
    },

    async loadChat(threadId, page) {
      this.msgs.chatLoading = true;
      try {
        const resp = await api.getFull('message.thread_messages', { thread_id: threadId, page, limit: 30 });
        const rows = resp.data || [];
        if (page === 1) {
          this.msgs.chat = rows;
        } else {
          this.msgs.chat = [...rows, ...this.msgs.chat]; // prepend older
        }
        this.msgs.chatTotal = resp.meta?.total || resp.total || 0;
        this.msgs.chatPage  = page;
        if (page === 1) {
          this.$nextTick
            ? this.$nextTick(() => this._scrollChatBottom())
            : setTimeout(() => this._scrollChatBottom(), 50);
        }
      } catch (e) {
        console.error('loadChat:', e);
      } finally {
        this.msgs.chatLoading = false;
      }
    },

    async loadMoreChat() {
      if (!this.msgs.activeThread) return;
      await this.loadChat(this.msgs.activeThread.id, this.msgs.chatPage + 1);
    },

    async sendMessage() {
      const body = this.msgs.input.trim();
      if (!body || this.msgs.sending || !this.msgs.activeThread) return;
      this.msgs.sending = true;
      this.msgs.input   = '';

      // Optimistic update — tampilkan pesan langsung tanpa tunggu server
      const tempId = 'temp_' + Date.now();
      const tempMsg = {
        id:          tempId,
        sender_id:   this.user?.id,
        sender_name: this.user?.name,
        body,
        is_mine:     true,
        is_read:     false,
        created_at:  new Date().toISOString(),
      };
      this.msgs.chat.push(tempMsg);
      this.$nextTick(() => this._scrollChatBottom());

      try {
        const res = await api.post('message.send', { thread_id: this.msgs.activeThread.id, body });
        // Ganti pesan temp dengan data asli dari server
        const realMsg = res?.data ?? res;
        if (realMsg?.id) {
          const idx = this.msgs.chat.findIndex(m => m.id === tempId);
          if (idx !== -1) this.msgs.chat.splice(idx, 1, { ...tempMsg, id: realMsg.id, is_read: false });
        }
        // Refresh thread list preview (background)
        this.loadMsgThreads();
      } catch (e) {
        // Hapus pesan temp jika gagal, kembalikan input
        this.msgs.chat = this.msgs.chat.filter(m => m.id !== tempId);
        this.msgs.input = body;
        this.showToast(e.message || 'Gagal mengirim pesan', 'error', '❌');
      } finally {
        this.msgs.sending = false;
      }
    },

    async deleteChatMsg(msgId) {
      if (!confirm('Hapus pesan ini?')) return;
      try {
        await api.delete('message.delete', msgId);
        this.msgs.chat = this.msgs.chat.filter(m => m.id !== msgId);
      } catch (e) {
        this.showToast(e.message, 'error', '❌');
      }
    },

    async searchMsgUsers(q) {
      const query = q ?? this.msgs.newChat.q;
      if (!query || query.length < 2) { this.msgs.newChat.results = []; return; }
      this.msgs.newChat.loading = true;
      try {
        const data = await api.get('message.search_users', { q: query });
        this.msgs.newChat.results = Array.isArray(data) ? data : (data?.data || []);
      } catch (e) {
        this.msgs.newChat.results = [];
      } finally {
        this.msgs.newChat.loading = false;
      }
    },

    async startNewChat(u) {
      this.msgs.newChat.show = false;
      this.msgs.newChat.q = '';
      this.msgs.newChat.results = [];
      try {
        const data = await api.get('message.open_thread', { user_id: u.id });
        const threadId = data.thread_id;
        // find or create synthetic thread obj
        let th = this.msgs.threads.find(t => t.id === threadId);
        if (!th) {
          th = { id: threadId, other_name: u.name, other_id: u.id, last_body: '', unread_count: 0 };
          this.msgs.threads.unshift(th);
        }
        await this.openThread(th);
      } catch (e) {
        this.showToast(e.message, 'error', '❌');
      }
    },

    _startMsgPoll(threadId) {
      this.clearMsgPoll();
      this.msgs.pollInterval = setInterval(async () => {
        if (this.msgs.activeThread?.id === threadId) {
          const prevLen = this.msgs.chat.length;
          await this.loadChat(threadId, 1);
          // Jika ada pesan baru masuk, refresh unread count
          if (this.msgs.chat.length !== prevLen) {
            try {
              const counts = await api.get('notification.counts');
              this.msgs.unreadCount  = counts.messages      || 0;
              this.notif.unreadCount = counts.notifications || 0;
            } catch (_) {}
          }
        } else {
          this.clearMsgPoll();
        }
      }, 5000);
    },

    clearMsgPoll() {
      if (this.msgs.pollInterval) {
        clearInterval(this.msgs.pollInterval);
        this.msgs.pollInterval = null;
      }
    },

    _scrollChatBottom() {
      const el = document.getElementById('chat-messages') || this.$refs?.chatBox;
      if (el) el.scrollTop = el.scrollHeight;
    },

    // ---- Date helpers ----
    sameDay(d1, d2) {
      const a = new Date(d1), b = new Date(d2);
      return a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate();
    },

    formatTime(dt) {
      if (!dt) return '';
      return new Date(dt).toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' });
    },

    formatDayLabel(dt) {
      if (!dt) return '';
      const d = new Date(dt);
      const now = new Date();
      const yesterday = new Date(now); yesterday.setDate(now.getDate() - 1);
      if (this.sameDay(d, now))       return 'Hari ini';
      if (this.sameDay(d, yesterday)) return 'Kemarin';
      return d.toLocaleDateString('id-ID', { day: 'numeric', month: 'long', year: 'numeric' });
    },

    formatRelative(dateStr) {
      if (!dateStr) return '';
      const diff = Date.now() - new Date(dateStr).getTime();
      const m = Math.floor(diff / 60000);
      if (m < 1)  return 'baru saja';
      if (m < 60) return `${m}m lalu`;
      const h = Math.floor(m / 60);
      if (h < 24) return `${h}j lalu`;
      return `${Math.floor(h / 24)}h lalu`;
    },

    formatDateTime(dateStr) {
      if (!dateStr) return '—';
      const d   = new Date(dateStr);
      const now = new Date();
      const yesterday = new Date(now); yesterday.setDate(now.getDate() - 1);
      const time = d.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' });
      if (d.toDateString() === now.toDateString())       return 'Hari ini, ' + time;
      if (d.toDateString() === yesterday.toDateString()) return 'Kemarin, '  + time;
      return d.toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: '2-digit' }) + ', ' + time;
    },

    // SEARCH PAGE
    // ============================================================
    async loadSearch(q) {
      this.search.q = q;
      if (!q || q.trim().length < 2) {
        this.search.results = [];
        this.search.total   = 0;
        return;
      }
      this.search.loading = true;
      try {
        const data = await api.get('quiz.list', { search: q.trim(), limit: 20, page: 1 });
        this.search.results = Array.isArray(data) ? data : (data?.data || []);
        this.search.total   = data?.total || this.search.results.length;
      } catch (e) {
        this.search.results = [];
      } finally {
        this.search.loading = false;
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

    formatModeLabel(mode) {
      return {
        exam: 'Ujian',
        instant: 'Instan',
        end: 'Akhir',
        challenge: 'Tantangan'
      }[mode] || (mode ? mode.charAt(0).toUpperCase() + mode.slice(1) : 'Bebas');
    },
  }
}

function globalTicker() {
  return {
    currentItem: null,
    visible: false,
    _items: [],
    _idx: 0,
    _timer: null,

    async init() {
      try {
        const data = await api.get('attempt.quiz_global_history');
        this._items = Array.isArray(data) ? data : (data?.data || []);
      } catch (_) {}
      if (!this._items.length) return;
      this.currentItem = this._items[0];
      this.visible = true;
      if (this._items.length > 1) this._startTicker();
    },

    _startTicker() {
      this._timer = setInterval(() => {
        this.visible = false;
        setTimeout(() => {
          this._idx = (this._idx + 1) % this._items.length;
          this.currentItem = this._items[this._idx];
          this.visible = true;
        }, 420);
      }, 4500);
    },

    formatModeLabel(mode) {
      return { exam: 'Ujian', instant: 'Instan', end: 'Akhir', challenge: 'Tantangan' }[mode]
        || (mode ? mode.charAt(0).toUpperCase() + mode.slice(1) : 'Bebas');
    },

    formatTimeAgo(dateStr) {
      if (!dateStr) return '';
      const diff = Date.now() - new Date(dateStr).getTime();
      const m = Math.floor(diff / 60000);
      if (m < 1)  return 'baru saja';
      if (m < 60) return `${m}m lalu`;
      const h = Math.floor(m / 60);
      if (h < 24) return `${h}j lalu`;
      return `${Math.floor(h / 24)}h lalu`;
    },
  };
}

function quizHistorySection() {
  return {
    history: [],
    loading: true,
    currentIndex: 0,
    visible: true,
    _ticker: null,

    async init() {
      await this.loadHistory();
      if (this.history.length > 1) this.startTicker();
    },

    async loadHistory() {
      this.loading = true;
      try {
        const data = await api.get('attempt.quiz_global_history');
        this.history = Array.isArray(data) ? data : (data?.data || []);
      } catch (e) {
        console.error('Failed to load history:', e);
        this.history = [];
      } finally {
        this.loading = false;
      }
    },

    startTicker() {
      this._ticker = setInterval(() => {
        // fade out
        this.visible = false;
        setTimeout(() => {
          this.currentIndex = (this.currentIndex + 1) % this.history.length;
          // fade in
          this.visible = true;
        }, 350);
      }, 4000);
    },

    get currentItem() {
      return this.history.length > 0 ? this.history[this.currentIndex] : null;
    },

    formatTimeAgo(dateStr) {
      const now = new Date();
      const date = new Date(dateStr);
      const diffMs = now - date;
      const diffMins = Math.floor(diffMs / 60000);
      const diffHours = Math.floor(diffMins / 60);
      const diffDays = Math.floor(diffHours / 24);

      if (diffMins < 1) return 'Baru saja';
      if (diffMins < 60) return `${diffMins}m lalu`;
            if (diffHours < 24) return `${diffHours}j lalu`;
      return `${diffDays}h lalu`;
    },

    formatModeLabel(mode) {
      return { exam: 'Ujian', instant: 'Instan', end: 'Akhir', challenge: 'Tantangan' }[mode]
        || (mode ? mode.charAt(0).toUpperCase() + mode.slice(1) : 'Bebas');
    },
  };
}
