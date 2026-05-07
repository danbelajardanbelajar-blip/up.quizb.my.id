<!DOCTYPE html>
<html lang="id" x-data="QuizBApp()" x-init="init()" :class="{ 'dark': darkMode }">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title x-text="pageTitle">QuizB — Platform Kuis Modern</title>
  <meta name="description" content="Platform kuis online modern dengan berbagai kategori, leaderboard, dan ujian interaktif." />

  <!-- Favicon (SVG emoji inline — tidak perlu file eksternal) -->
  <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🎯</text></svg>" />

  <!-- Google Fonts -->
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />

  <!-- Tailwind CSS CDN -->
  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    tailwind.config = {
      darkMode: 'class',
      theme: {
        extend: {
          fontFamily: { sans: ['Inter', 'sans-serif'] },
          colors: {
            primary: { 50:'#eef2ff',100:'#e0e7ff',200:'#c7d2fe',300:'#a5b4fc',400:'#818cf8',500:'#6366f1',600:'#4f46e5',700:'#4338ca',800:'#3730a3',900:'#312e81' },
            brand:   { DEFAULT:'#6366f1', dark:'#4f46e5' }
          },
          animation: {
            'fade-in': 'fadeIn 0.3s ease-out',
            'slide-up': 'slideUp 0.4s ease-out',
            'pulse-slow': 'pulse 3s infinite',
          },
          keyframes: {
            fadeIn:  { from:{ opacity:0 }, to:{ opacity:1 } },
            slideUp: { from:{ opacity:0, transform:'translateY(16px)' }, to:{ opacity:1, transform:'translateY(0)' } },
          }
        }
      }
    }
  </script>

  <!-- Custom CSS -->
  <link rel="stylesheet" href="assets/css/app.css" />
</head>
<body class="bg-gray-50 dark:bg-gray-950 text-gray-900 dark:text-gray-100 font-sans min-h-screen transition-colors duration-300">

  <!-- Toast Notification -->
  <div x-show="toast.show" x-transition:enter="transition ease-out duration-300"
       x-transition:enter-start="opacity-0 translate-y-2" x-transition:enter-end="opacity-100 translate-y-0"
       x-transition:leave="transition ease-in duration-200" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
       class="fixed top-4 right-4 z-[9999] flex items-center gap-3 px-4 py-3 rounded-xl shadow-2xl text-white text-sm font-medium max-w-sm"
       :class="{ 'bg-green-500': toast.type==='success', 'bg-red-500': toast.type==='error', 'bg-blue-500': toast.type==='info', 'bg-yellow-500': toast.type==='warning' }">
    <span x-text="toast.icon" class="text-lg"></span>
    <span x-text="toast.message"></span>
  </div>

  <!-- NAVBAR -->
  <nav x-show="!currentRoute.startsWith('/play/')"
       class="sticky top-0 z-50 bg-white/80 dark:bg-gray-900/80 backdrop-blur-md border-b border-gray-200/50 dark:border-gray-800/50">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
      <div class="flex items-center justify-between h-16">

        <!-- Logo -->
        <a href="#/" @click.prevent="navigate('/')" class="flex items-center gap-2 group">
          <div class="w-8 h-8 bg-gradient-to-br from-primary-500 to-primary-700 rounded-lg flex items-center justify-center shadow-lg group-hover:scale-105 transition-transform">
            <span class="text-white font-bold text-sm">Q</span>
          </div>
          <span class="font-bold text-xl bg-gradient-to-r from-primary-600 to-primary-400 bg-clip-text text-transparent">QuizB</span>
        </a>

        <!-- Desktop Nav -->
        <div class="hidden md:flex items-center gap-1">
          <template x-for="item in navItems" :key="item.href">
            <a :href="'#' + item.href" @click.prevent="navigate(item.href)"
               class="px-3 py-2 rounded-lg text-sm font-medium transition-all duration-200"
               :class="currentRoute === item.href ? 'bg-primary-100 dark:bg-primary-900/40 text-primary-600 dark:text-primary-400' : 'text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800 hover:text-gray-900 dark:hover:text-gray-100'"
               x-text="item.label"></a>
          </template>
        </div>

        <!-- Right Actions -->
        <div class="flex items-center gap-2">
          <!-- Search -->
          <div class="relative hidden sm:block" x-data="{ open: false, q: '' }">
            <input type="text" placeholder="Cari quiz..." x-model="q" @focus="open=true" @blur="setTimeout(()=>open=false,200)"
                   @input.debounce.300ms="$dispatch('search', { q })"
                   class="w-40 focus:w-56 transition-all duration-300 pl-9 pr-3 py-1.5 text-sm bg-gray-100 dark:bg-gray-800 border border-transparent focus:border-primary-300 dark:focus:border-primary-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary-500/20" />
            <svg class="absolute left-2.5 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
          </div>

          <!-- Dark Mode Toggle -->
          <button @click="toggleDark()" class="p-2 rounded-lg text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors" title="Toggle dark mode">
            <svg x-show="!darkMode" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/></svg>
            <svg x-show="darkMode" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
          </button>

          <!-- Auth Buttons / User Menu -->
          <template x-if="!user">
            <div class="flex items-center gap-2">
              <a href="#/login" @click.prevent="navigate('/login')" class="text-sm font-medium text-gray-600 dark:text-gray-400 hover:text-primary-600 dark:hover:text-primary-400 transition-colors">Masuk</a>
              <a href="#/register" @click.prevent="navigate('/register')" class="btn-primary text-sm px-4 py-1.5">Daftar</a>
            </div>
          </template>
          <template x-if="user">
            <div class="relative" x-data="{ open: false }">
              <button @click="open=!open" class="flex items-center gap-2 p-1 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors">
                <div class="w-8 h-8 rounded-full bg-gradient-to-br from-primary-400 to-primary-600 flex items-center justify-center text-white font-semibold text-sm" x-text="user.name.charAt(0).toUpperCase()"></div>
                <span class="hidden sm:block text-sm font-medium" x-text="user.name"></span>
                <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
              </button>
              <div x-show="open" @click.outside="open=false" x-transition class="absolute right-0 mt-2 w-48 bg-white dark:bg-gray-800 rounded-xl shadow-xl border border-gray-200 dark:border-gray-700 py-1 z-50">
                <a href="#/dashboard" @click.prevent="navigate('/dashboard');open=false" class="flex items-center gap-2 px-4 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700">📊 Dashboard</a>
                <a href="#/profile" @click.prevent="navigate('/profile');open=false" class="flex items-center gap-2 px-4 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700">👤 Profil</a>
                <a href="#/history" @click.prevent="navigate('/history');open=false" class="flex items-center gap-2 px-4 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700">📋 Histori</a>
                <a href="#/settings" @click.prevent="navigate('/settings');open=false" class="flex items-center gap-2 px-4 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700">⚙️ Pengaturan</a>
                <template x-if="user && user.role === 'admin'">
                  <a href="#/admin" @click.prevent="navigate('/admin');open=false" class="flex items-center gap-2 px-4 py-2 text-sm text-purple-600 dark:text-purple-400 hover:bg-gray-50 dark:hover:bg-gray-700">⚙️ Admin Panel</a>
                </template>
                <hr class="my-1 border-gray-200 dark:border-gray-700" />
                <button @click="logout();open=false" class="w-full flex items-center gap-2 px-4 py-2 text-sm text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-gray-700">🚪 Keluar</button>
              </div>
            </div>
          </template>

          <!-- Mobile menu button -->
          <button @click="mobileMenu=!mobileMenu" class="md:hidden p-2 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
          </button>
        </div>
      </div>

      <!-- Mobile Menu -->
      <div x-show="mobileMenu" x-transition class="md:hidden pb-3 border-t border-gray-200 dark:border-gray-800 pt-3 space-y-1">
        <template x-for="item in navItems" :key="item.href">
          <a :href="'#'+item.href" @click.prevent="navigate(item.href);mobileMenu=false"
             class="block px-3 py-2 rounded-lg text-sm font-medium transition-colors"
             :class="currentRoute===item.href ? 'bg-primary-100 dark:bg-primary-900/40 text-primary-600' : 'text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800'"
             x-text="item.label"></a>
        </template>
      </div>
    </div>
  </nav>

  <!-- ACTIVITY TICKER — sticky tepat di bawah nav (top-0 saat fullscreen quiz) -->
  <div x-data="globalTicker()"
       x-show="currentItem"
       x-cloak
       class="sticky z-40 border-b border-gray-200/30 dark:border-gray-700/30 bg-white/20 dark:bg-gray-900/20 backdrop-blur-sm"
       :class="currentRoute.startsWith('/play/') ? 'top-0' : 'top-16'">

    <div class="px-4 py-1 text-center">
      <!-- teks animasi -->
      <p class="inline text-gray-500/80 dark:text-gray-400/80 leading-4"
         :style="'font-size:0.8rem; transition:opacity 0.4s cubic-bezier(.4,0,.2,1),transform 0.4s cubic-bezier(.4,0,.2,1); ' + (visible ? 'opacity:1;transform:translateY(0)' : 'opacity:0;transform:translateY(5px)')">

        <span class="font-semibold text-gray-600/90 dark:text-gray-300/90"
              x-text="currentItem?.is_anon ? 'Tamu' : (currentItem?.user_name || 'Tamu')"></span>
        <span class="mx-0.5 text-gray-400/70">menyelesaikan</span>
        <span class="font-semibold text-gray-600/90 dark:text-gray-300/90"
              x-text="currentItem?.quiz_title"></span>
        <span class="mx-0.5 text-gray-400/50">·</span>
        <span class="text-blue-500/80 dark:text-blue-400/80"
              x-text="'mode ' + formatModeLabel(currentItem?.mode)"></span>
        <span class="mx-0.5 text-gray-400/50">·</span>
        <span class="font-bold"
              :class="(currentItem?.score ?? 0) >= 60 ? 'text-green-500/80 dark:text-green-400/80' : 'text-red-500/80 dark:text-red-400/80'"
              x-text="currentItem?.score"></span>
        <span class="ml-1.5 text-gray-400/50 dark:text-gray-600/50"
              x-text="formatTimeAgo(currentItem?.completed_at)"></span>
      </p>
    </div>
  </div>

  <!-- PAGE CONTAINER -->
  <main id="app"
        :class="currentRoute.startsWith('/play/') ? 'min-h-screen' : 'min-h-[calc(100vh-4rem)]'">

    <!-- HOME PAGE -->
    <div x-show="currentRoute === '/'" x-transition:enter="animate-fade-in">
      <?php include 'pages/home.html'; ?>
    </div>

    <!-- CATEGORIES PAGE -->
    <div x-show="currentRoute === '/categories'" x-transition:enter="animate-fade-in">
      <?php include 'pages/categories.html'; ?>
    </div>

    <!-- QUIZ LIST PAGE -->
    <div x-show="currentRoute === '/quizzes'" x-transition:enter="animate-fade-in">
      <?php include 'pages/quizzes.html'; ?>
    </div>

    <!-- QUIZ DETAIL PAGE -->
    <div x-show="currentRoute.startsWith('/quiz/')" x-transition:enter="animate-fade-in">
      <?php include 'pages/quiz-detail.html'; ?>
    </div>

    <!-- QUIZ ENGINE PAGE -->
    <template x-if="currentRoute.startsWith('/play/')">
      <div x-transition:enter="animate-fade-in">
        <?php include 'pages/quiz-engine.html'; ?>
      </div>
    </template>

    <!-- RESULT PAGE -->
    <div x-show="currentRoute.startsWith('/result/')" x-transition:enter="animate-fade-in">
      <?php include 'pages/result.html'; ?>
    </div>

    <!-- LEADERBOARD PAGE -->
    <div x-show="currentRoute === '/leaderboard'" x-transition:enter="animate-fade-in">
      <?php include 'pages/leaderboard.html'; ?>
    </div>

    <!-- DASHBOARD PAGE -->
    <div x-show="currentRoute === '/dashboard'" x-transition:enter="animate-fade-in">
      <?php include 'pages/dashboard.html'; ?>
    </div>

    <!-- HISTORY PAGE -->
    <div x-show="currentRoute === '/history'" x-transition:enter="animate-fade-in">
      <?php include 'pages/history.html'; ?>
    </div>

    <!-- LOGIN PAGE -->
    <div x-show="currentRoute === '/login'" x-transition:enter="animate-fade-in">
      <?php include 'pages/login.html'; ?>
    </div>

    <!-- REGISTER PAGE -->
    <div x-show="currentRoute === '/register'" x-transition:enter="animate-fade-in">
      <?php include 'pages/register.html'; ?>
    </div>

    <!-- PROFILE PAGE -->
    <div x-show="currentRoute === '/profile'" x-transition:enter="animate-fade-in">
      <?php include 'pages/profile.html'; ?>
    </div>

    <!-- SETTINGS PAGE -->
    <div x-show="currentRoute === '/settings'" x-transition:enter="animate-fade-in">
      <?php include 'pages/settings.html'; ?>
    </div>

    <!-- CLASSROOM LIST PAGE -->
    <div x-show="currentRoute === '/classroom'" x-transition:enter="animate-fade-in">
      <?php include 'pages/classroom.html'; ?>
    </div>

    <!-- CLASSROOM DETAIL PAGE -->
    <div x-show="currentRoute.startsWith('/classroom/') && currentRoute !== '/classroom'" x-transition:enter="animate-fade-in">
      <?php include 'pages/classroom-detail.html'; ?>
    </div>

    <!-- CHALLENGES PAGE -->
    <div x-show="currentRoute.startsWith('/assignment/')" x-transition:enter="animate-fade-in">
      <?php include 'pages/assignment-results.html'; ?>
      <?php include 'pages/assignment-monitor.html'; ?>
    </div>

    <div x-show="currentRoute === '/challenges'" x-transition:enter="animate-fade-in">
      <?php include 'pages/challenges.html'; ?>
    </div>

    <!-- ADMIN PAGE -->
    <div x-show="currentRoute.startsWith('/admin')" x-transition:enter="animate-fade-in">
      <?php include 'pages/admin.html'; ?>
    </div>

    <!-- 404 PAGE -->
    <div x-show="currentRoute === '/404'" class="flex flex-col items-center justify-center min-h-[60vh] text-center px-4">
      <div class="text-8xl mb-4">🔍</div>
      <h1 class="text-4xl font-bold text-gray-800 dark:text-gray-100 mb-2">404</h1>
      <p class="text-gray-500 dark:text-gray-400 mb-6">Halaman tidak ditemukan.</p>
      <button @click="navigate('/')" class="btn-primary">← Kembali ke Beranda</button>
    </div>

  </main>

  <!-- FOOTER -->
  <footer x-show="!currentRoute.startsWith('/play/')"
          class="bg-white dark:bg-gray-900 border-t border-gray-200 dark:border-gray-800 mt-auto">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
      <div class="flex flex-col md:flex-row items-center justify-between gap-4">
        <div class="flex items-center gap-2">
          <div class="w-7 h-7 bg-gradient-to-br from-primary-500 to-primary-700 rounded-lg flex items-center justify-center">
            <span class="text-white font-bold text-xs">Q</span>
          </div>
          <span class="font-bold text-gray-800 dark:text-gray-200">QuizB</span>
          <span class="text-gray-400 text-sm">— Platform Kuis Modern</span>
        </div>
        <p class="text-sm text-gray-400">© 2025 QuizB. Hak cipta dilindungi.</p>
        <div class="flex items-center gap-4 text-sm text-gray-400">
          <a href="#/categories" @click.prevent="navigate('/categories')" class="hover:text-primary-500 transition-colors">Kategori</a>
          <a href="#/leaderboard" @click.prevent="navigate('/leaderboard')" class="hover:text-primary-500 transition-colors">Leaderboard</a>
        </div>
      </div>
    </div>
  </footer>

  <!-- Alpine.js CDN -->
  <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>

  <!-- App Scripts -->
  <script src="assets/js/utils.js"></script>
  <script src="assets/js/quiz-engine.js"></script>
  <script src="assets/js/app.js"></script>
</body>
</html>
