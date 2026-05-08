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
    darkMode: store.get('darkMode', false),
    mobileMenu: false,
    pageTitle: 'QuizB — Platform Kuis Modern',
    toast: { show: false, message: '', type: 'success', icon: '✅' },
    _toastTimer: null,

    // Nav items — dinamis di getter, tapi definisikan base dulu
    get navItems() {
      const base = [
        { href: '/',            label: '🏠 Beranda'     },
        { href: '/categories',  label: '📂 Kategori'    },
        { href: '/quizzes',     label: '📝 Semua Kuis'  },
        { href: '/leaderboard', label: '🏆 Leaderboard' },
      ];
      if (this.user) {
        base.push({ href: '/classroom', label: '🏫 Kelas' });
        const badge = this.challenge.pendingCount > 0 ? ' (' + this.challenge.pendingCount + ')' : '';
        base.push({ href: '/challenges', label: '⚔️ Tantangan' + badge });
      }
      return base;
    },

    // ---- Page Data ----
    home:        { featured: [], categories: [], groups: [], loading: true, stats: { total_questions: 0, total_quizzes: 0, total_categories: 0, total_users: 0 } },
    categories:  { list: [], loading: true },
    quizzes:     { list: [], loading: true, total: 0, page: 1, categoryId: 0, search: '', difficulty: '' },
    quizDetail:  { quiz: null, loading: true },
    leaderboard: { list: [], loading: true },
    dashboard:   { stats: null, userInfo: null, recent: [], loading: true },
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
      // Detail page state
      members:     [],
      assignments: [],
      isTeacher:   false,
      // Create assignment modal
      assignModal: { show: false, editId: null },
      assignForm:  { title: '', quiz_id: '', mode: 'bebas', deadline: '', max_questions: '', shuffle_questions: null, shuffle_options: null, timer_per_question: '', duration_minutes: '' },
      assignError: '',
      assignLoading: false,
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
      tab: 'stats',
      stats: null,
      quizzes: [], quizzesTotal: 0, quizzesPage: 1, quizzesSearch: '', quizzesView: 'list',
      users:   [],   usersTotal: 0,   usersPage: 1,   usersSearch: '',
      categories: [],
      questions: [], questionsQuizId: null, questionsQuizTitle: '',
      questionsAll: [], questionsTotal: 0, questionsPage: 1, questionsSearch: '', questionsQuizFilter: 0,
      // Daftar quiz khusus untuk dropdown di tab Soal — agar tidak menimpa
      // pagination admin.quizzes pada tab Quiz.
      quizPicker: [],
      groups: [],
      allCategories: [],
      groupAssign: { show: false, group: null, selected: [] },
      // Review Soal
      review: { data: [], expandedId: null, attempts: {}, search: '', page: 1, perPage: 15 },
      // User history modal
      userHistory: { show: false, user: null, attempts: [], total: 0, page: 1, loading: false },
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

    // Settings
    settings: { limit: 10, shuffleQuestions: true, shuffleOptions: true, loading: false, saving: false, error: '', success: '' },

    // Challenge
    challenge: {
      incoming: [], received: [], outgoing: [], loading: false, pendingCount: 0,
      pollInterval: null,
    },

    // ---- Lifecycle ----
    async init() {
      // Dark mode
      this.applyDark();

      // Save the original intended hash BEFORE any redirect may change it.
      const initialHash = window.location.hash || '#/';

      // Hash routing
      this.handleRoute(initialHash);
      window.addEventListener('hashchange', () => this.handleRoute(window.location.hash));

      // Search events
      window.addEventListener('search', (e) => this.onSearch(e.detail.q));

      // Load current user
      await this.loadUser();
      // Sync settings state dengan data user
      if (this.user) {
        this.settings.limit            = this.user.quiz_questions_limit || 10;
        this.settings.shuffleQuestions = this.user.shuffle_questions    ?? true;
        this.settings.shuffleOptions   = this.user.shuffle_options      ?? true;
      }

      // Start challenge notification polling for logged-in users
      if (this.user) {
        this.loadChallenges();
        this.challenge.pollInterval = setInterval(() => this.loadChallenges(), 20000);
      }

      // After loadUser: if the user IS authenticated and the original route was
      // a protected page (but we were bounced to /login because user hadn't loaded
      // yet), navigate back to the intended destination now.
      const protected_routes = ['/dashboard', '/history', '/classroom', '/profile', '/settings', '/challenges'];
      const intendedPath = (initialHash.replace(/^#/, '').split('?')[0]) || '/';
      if (this.user && protected_routes.some(r => intendedPath.startsWith(r))) {
        return this.handleRoute(initialHash);
      }

      // Re-check protected route guards after user is loaded.
      // We only re-run guard logic — NOT the full data loaders — so that
      // params (e.g. /quiz/5) loaded above are not overwritten with undefined.
      this._guardRoute(this.currentRoute);
    },

    // Lightweight guard re-check (used after async loadUser).
    _guardRoute(route) {
      const protected_routes = ['/dashboard', '/history', '/classroom', '/profile', '/settings', '/challenges'];
      const admin_routes     = ['/admin'];
      if (route === '/onboarding' && !this.user) return this.navigate('/login');
      if (protected_routes.some(r => route.startsWith(r)) && !this.user) {
        this.showToast('Silakan login untuk mengakses halaman ini', 'warning', '⚠️');
        return this.navigate('/login');
      }
      if (admin_routes.some(r => route.startsWith(r)) && this.user?.role !== 'admin') {
        this.showToast('Akses ditolak', 'error', '🚫');
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
        'admin':       '/admin',
        'classroom':   rest[0] ? '/classroom/' + rest[0] : '/classroom',
        'challenges':  '/challenges',
        'assignment':  rest[0] ? '/assignment/' + rest.join('/') : '/assignment',
        'onboarding':  '/onboarding',
      };

      const route = routeMap[base] || (path === '/' ? '/' : '/404');
      this.currentRoute = route;
      this.routeParams  = rest;
      window.scrollTo({ top: 0, behavior: 'smooth' });
      this.onRouteChange(route, rest);
    },

    navigate(path) {
      window.location.hash = '#' + path;
    },

    onRouteChange(route, params) {
      // Guard protected routes
      const protected_routes = ['/dashboard', '/history', '/classroom', '/profile', '/settings', '/challenges'];
      const admin_routes     = ['/admin'];

      if (route === '/onboarding' && !this.user) return this.navigate('/login');
      if (protected_routes.some(r => route.startsWith(r)) && !this.user) {
        this.showToast('Silakan login untuk mengakses halaman ini', 'warning', '⚠️');
        return this.navigate('/login');
      }
      if (admin_routes.some(r => route.startsWith(r)) && this.user?.role !== 'admin') {
        this.showToast('Akses ditolak', 'error', '🚫');
        return this.navigate('/');
      }

      // Load data per route
      if (route === '/')                   this.loadHome();
      if (route === '/categories')         this.loadCategories();
      if (route === '/quizzes')            this.loadQuizzes();
      if (route.startsWith('/quiz/'))      this.loadQuizDetail(params[0]);
      if (route.startsWith('/play/'))      return; // Quiz engine handles its own load via x-init
      if (route === '/leaderboard')        this.loadLeaderboard();
      if (route === '/dashboard')          this.loadDashboard();
      if (route === '/history')            this.loadHistory();
      if (route === '/profile')            this.loadDashboard(); // reuse dashboard stats
      if (route === '/settings')           this.loadSettings();
      if (route.startsWith('/result/'))    this.loadResult(params[0]);
      if (route.startsWith('/admin'))      this.loadAdminTab(this.admin.tab);
      if (route === '/classroom')          this.loadClassroom();
      if (route.startsWith('/classroom/') && params[0]) this.loadClassroomDetail(params[0]);
      if (route === '/challenges')         this.loadChallenges();
      if (!this.currentRoute.includes('/monitor') && this.assignmentView && this.assignmentView.monitorInterval) {
        clearInterval(this.assignmentView.monitorInterval);
        this.assignmentView.monitorInterval = null;
      }
      if (/^\/assignment\/\d+\/results$/.test(route)) this.loadAssignmentResults(params[0]);
      if (/^\/assignment\/\d+\/monitor$/.test(route)) this.loadAssignmentMonitor(params[0]);
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
        // API auth.register returns flat: { id, name, email, role, csrf_token }
        const data = await api.post('auth.register', { name: f.name, email: f.email, password: f.password });
        this.user = { id: data.id, name: data.name, email: data.email, role: data.role };
        api._csrfToken = data.csrf_token || null;
        this.showToast('Registrasi berhasil! Selamat datang 🎉', 'success', '🎉');
        // User baru → arahkan ke pilihan role dulu
        this.navigate(data.is_new_user ? '/onboarding' : '/dashboard');
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
        this.showToast('Tugas berhasil dikumpulkan! 🎉', 'success', '✅');
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
        this.showToast('Berhasil bergabung ke kelas!', 'success', '🎉');
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
        this.showToast('Kelas berhasil dibuat!', 'success', '🎉');
        await this.loadClassroom();
      } catch (e) {
        this.classroom.createError = e.message;
      } finally {
        this.classroom.createLoading = false;
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

    openEditAssignModal(assign) {
      this.classroom.assignModal.editId = assign.id;
      this.classroom.assignForm = {
        title:             assign.title,
        quiz_id:           assign.quiz_id,
        mode:              assign.mode,
        deadline:          assign.deadline ? assign.deadline.replace(' ', 'T').substring(0, 16) : '',
        max_questions:     assign.max_questions != null ? assign.max_questions : '',
        shuffle_questions:   assign.shuffle_questions, // null | 0 | 1
        shuffle_options:     assign.shuffle_options,
        timer_per_question:  assign.timer_per_question != null ? assign.timer_per_question : '',
        duration_minutes:    assign.duration_minutes   != null ? assign.duration_minutes   : '',
      };
      this.classroom.assignError = '';
      this.classroom.assignModal.show = true;
    },

    async updateAssignment(classId) {
      const f  = this.classroom.assignForm;
      const id = this.classroom.assignModal.editId;
      if (!f.title || f.title.length < 3) { this.classroom.assignError = 'Judul tugas minimal 3 karakter'; return; }
      this.classroom.assignLoading = true;
      this.classroom.assignError   = '';
      try {
        const maxQ      = f.max_questions !== '' && f.max_questions != null ? parseInt(f.max_questions) : null;
        const shuffleQ   = f.shuffle_questions; // null | 0 | 1
        const shuffleO   = f.shuffle_options;
        const timerPerQ  = f.timer_per_question !== '' && f.timer_per_question != null ? parseInt(f.timer_per_question) : null;
        const durMins    = f.duration_minutes   !== '' && f.duration_minutes   != null ? parseInt(f.duration_minutes)   : null;
        await api.put('assignment.update', id, {
          title:              f.title,
          mode:               f.mode,
          deadline:           f.deadline || null,
          max_questions:      maxQ,
          shuffle_questions:  shuffleQ,
          shuffle_options:    shuffleO,
          timer_per_question: timerPerQ,
          duration_minutes:   durMins,
        });
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
      this.classroom.assignForm  = { title: '', quiz_id: '', mode: 'bebas', deadline: '', max_questions: '', shuffle_questions: null, shuffle_options: null, timer_per_question: '', duration_minutes: '' };
      this.classroom.assignError = '';
      this.classroom.assignModal.show = true;
      // Load quiz list for dropdown if not already loaded
      if (!this.classroom.quizListForAssign.length) {
        this.classroom.quizListLoading = true;
        try {
          const resp = await api.getFull('quiz.list', { limit: 50 });
          this.classroom.quizListForAssign = Array.isArray(resp.data) ? resp.data : [];
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
      if (!f.quiz_id) { this.classroom.assignError = 'Pilih paket soal'; return; }
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
          quiz_id:            parseInt(f.quiz_id),
          title:              f.title,
          mode:               f.mode,
          deadline:           f.deadline || null,
          max_questions:      maxQ,
          ...(shuffleQ  !== null ? { shuffle_questions:  shuffleQ  } : {}),
          ...(shuffleO  !== null ? { shuffle_options:    shuffleO  } : {}),
          ...(timerPerQ !== null ? { timer_per_question: timerPerQ } : {}),
          ...(durMins   !== null ? { duration_minutes:   durMins   } : {}),
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
          this.admin.questionsAll   = qData.questions || [];
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
        }
      } catch (e) {
        this.showToast(e.message, 'error', '❌');
      } finally {
        this.admin.loading = false;
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
        } else if (type === 'quiz_create') {
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
        await this.loadAdminTab('groups');
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
        this.admin.importFile.questions = data.questions.map(q => ({ ...q, _sel: true }));
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

    // ---- Import soal dari QuizB ----

    async openImportQuizbModal() {
      if (!this.admin.questionsQuizFilter) {
        this.showToast('Pilih filter quiz terlebih dahulu sebagai tujuan import', 'error', '❌');
        return;
      }
      this.admin.importQuizb = {
        show: true, loading: true,
        themes: [], selectedThemeId: null,
        subthemes: [], selectedSubthemeId: null,
        titles: [], selectedTitleId: null, selectedTitleName: '',
        questions: [], selectedIds: [],
        quizId: this.admin.questionsQuizFilter,
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
        this.admin.questionsAll   = qData.questions || [];
        this.admin.questionsTotal = qData.total     || 0;
      } catch (e) {
        this.showToast(e.message, 'error', '❌');
      } finally {
        this.admin.loading = false;
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
    async loadUserHistory(u, page = 1) {
      this.admin.userHistory.user     = u;
      this.admin.userHistory.page     = page;
      this.admin.userHistory.show     = true;
      this.admin.userHistory.loading  = true;
      this.admin.userHistory.attempts = [];
      this.admin.userHistory.total    = 0;
      try {
        const resp = await api.getFull('admin.user_history', { user_id: u.id, page, limit: 15 });
        this.admin.userHistory.attempts = resp.data  || [];
        this.admin.userHistory.total    = resp.meta?.total || 0;
      } catch (e) {
        this.showToast(e.message, 'error', '❌');
      } finally {
        this.admin.userHistory.loading = false;
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
        this.showToast('Tantangan diterima! Mulai bermain.', 'success', '⚔️');
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

    async forceStopStudent(assignmentId, studentId, studentName) {
      if (!confirm('Hentikan paksa pekerjaan ' + studentName + '?')) return;
      try {
        await api.post('assignment.force_stop', {
          assignment_id: parseInt(assignmentId),
          student_id:    parseInt(studentId),
        });
        this.showToast(studentName + ' berhasil dihentikan', 'success', '⛔');
      } catch (e) {
        this.showToast(e.message, 'error', '❌');
      }
    },

    async declineChallenge(challengeId) {
      try {
        await api.post('challenge.decline', { challenge_id: parseInt(challengeId) });
        this.showToast('Tantangan ditolak.', 'info', '🚫');
        await this.loadChallenges();
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

