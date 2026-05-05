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
      }
      return base;
    },

    // ---- Page Data ----
    home:        { featured: [], categories: [], loading: true, stats: { total_questions: 0, total_quizzes: 0, total_categories: 0, total_users: 0 } },
    categories:  { list: [], loading: true },
    quizzes:     { list: [], loading: true, total: 0, page: 1, categoryId: 0, search: '', difficulty: '' },
    quizDetail:  { quiz: null, loading: true },
    leaderboard: { list: [], loading: true },
    dashboard:   { stats: null, userInfo: null, recent: [], loading: true },
    history:     { list: [], loading: true, total: 0, page: 1 },
    result:      { data: null, loading: true, assignId: null, assignSubmitted: false, assignSubmitting: false, assignError: '' },

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
      assignForm:  { title: '', quiz_id: '', mode: 'bebas', deadline: '', max_questions: '' },
      assignError: '',
      assignLoading: false,
      // Quiz list for assignment dropdown
      quizListForAssign: [],
      quizListLoading: false,
    },

    // Admin state
    admin: {
      tab: 'stats',
      stats: null,
      quizzes: [], quizzesTotal: 0, quizzesPage: 1,
      users:   [],   usersTotal: 0,   usersPage: 1,
      categories: [],
      questions: [], questionsQuizId: null, questionsQuizTitle: '',
      // Daftar quiz khusus untuk dropdown di tab Soal — agar tidak menimpa
      // pagination admin.quizzes pada tab Quiz.
      quizPicker: [],
      loading: false,
      modal: { show: false, type: '', data: {} },
      form: {},
      formError: '',
    },

    // Auth forms
    loginForm:    { email: '', password: '', loading: false, error: '' },
    registerForm: { name: '', email: '', password: '', password_confirm: '', loading: false, error: '' },

    // Settings
    settings: { limit: 10, loading: false, saving: false, error: '', success: '' },

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
      // Sync settings state dengan data user
      if (this.user) {
        this.settings.limit = this.user.quiz_questions_limit || 10;
      }

      // Re-check protected route guards after user is loaded.
      // We only re-run guard logic — NOT the full data loaders — so that
      // params (e.g. /quiz/5) loaded above are not overwritten with undefined.
      this._guardRoute(this.currentRoute);
    },

    // Lightweight guard re-check (used after async loadUser).
    _guardRoute(route) {
      const protected_routes = ['/dashboard', '/history', '/classroom', '/profile', '/settings'];
      const admin_routes     = ['/admin'];
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
      const protected_routes = ['/dashboard', '/history', '/classroom', '/profile', '/settings'];
      const admin_routes     = ['/admin'];

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
        } : null;
        // Sync settings state
        if (this.user) this.settings.limit = this.user.quiz_questions_limit;
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
      // Baca assignId dari URL hash jika ada
      const hashMatch = window.location.hash.match(/[?&]assign=(\d+)/);
      this.result.assignId = hashMatch ? hashMatch[1] : null;
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
        title:         assign.title,
        quiz_id:       assign.quiz_id,
        mode:          assign.mode,
        deadline:      assign.deadline ? assign.deadline.replace(' ', 'T').substring(0, 16) : '',
        max_questions: assign.max_questions || '',
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
        const maxQ = f.max_questions !== '' && f.max_questions !== null
          ? parseInt(f.max_questions) : null;
        await api.put('assignment.update', id, {
          title:         f.title,
          mode:          f.mode,
          deadline:      f.deadline || null,
          max_questions: maxQ,
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
      this.classroom.assignForm  = { title: '', quiz_id: '', mode: 'bebas', deadline: '', max_questions: '' };
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
        const maxQ = f.max_questions !== '' && f.max_questions !== null
          ? parseInt(f.max_questions) : null;
        await api.post('assignment.create', {
          class_id:      parseInt(classId),
          quiz_id:       parseInt(f.quiz_id),
          title:         f.title,
          mode:          f.mode,
          deadline:      f.deadline || null,
          max_questions: maxQ,
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
          const data = await api.get('admin.quiz_list', { page: this.admin.quizzesPage, limit: 15 });
          this.admin.quizzes      = data.quizzes || [];
          this.admin.quizzesTotal = data.total   || 0;
          // Also load categories for quiz form dropdown
          if (!this.admin.categories.length) {
            this.admin.categories = await api.get('admin.category_list');
          }
        } else if (tab === 'users') {
          const data = await api.get('admin.user_list', { page: this.admin.usersPage, limit: 15 });
          this.admin.users      = data.users  || [];
          this.admin.usersTotal = data.total  || 0;
        } else if (tab === 'categories') {
          this.admin.categories = await api.get('admin.category_list');
        } else if (tab === 'questions') {
          // questions tab: load full quiz list ke variabel terpisah supaya
          // pagination tab Quiz tidak ikut tertimpa.
          if (!this.admin.quizPicker.length) {
            const data = await api.get('admin.quiz_list', { limit: 50 });
            this.admin.quizPicker = data.quizzes || [];
          }
          if (this.admin.questionsQuizId) {
            this.admin.questions = await api.get('question.list', { quiz_id: this.admin.questionsQuizId });
          }
        }
      } catch (e) {
        this.showToast(e.message, 'error', '❌');
      } finally {
        this.admin.loading = false;
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
        } else if (type === 'question_create') {
          await api.post('question.create', f);
          this.showToast('Soal berhasil ditambahkan', 'success', '✅');
          await this.loadAdminQuestions(this.admin.questionsQuizId, this.admin.questionsQuizTitle);
        } else if (type === 'question_edit') {
          await api.post('question.update', f);
          this.showToast('Soal berhasil diperbarui', 'success', '✅');
          await this.loadAdminQuestions(this.admin.questionsQuizId, this.admin.questionsQuizTitle);
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
        if (type === 'quiz')     await api.delete('admin.quiz_delete', id);
        if (type === 'category') await api.delete('admin.category_delete', id);
        if (type === 'user')     await api.delete('admin.user_delete', id);
        if (type === 'question') {
          await api.post('question.delete', { id });
          this.showToast('Soal berhasil dihapus', 'success', '🗑️');
          await this.loadAdminQuestions(this.admin.questionsQuizId, this.admin.questionsQuizTitle);
          return;
        }
        this.showToast('Berhasil dihapus', 'success', '🗑️');
        await this.loadAdminTab(this.admin.tab);
      } catch (e) {
        this.showToast(e.message, 'error', '❌');
      }
    },

    // Helper to build question form with blank options
    buildQuestionForm(quizId) {
      return {
        quiz_id: quizId,
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
