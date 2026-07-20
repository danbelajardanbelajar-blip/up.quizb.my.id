<?php
// Bootstrap flash message dari session (diset oleh verify-email.php)
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';
startSecureSession();
$flashType  = $_SESSION['flash_type']  ?? '';
$flashMsg   = $_SESSION['flash_msg']   ?? '';
$isNewUser  = !empty($_SESSION['is_new_user']);
unset($_SESSION['flash_type'], $_SESSION['flash_msg'], $_SESSION['is_new_user']);
?>
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
            'fade-in': 'fadeIn 0.12s ease-out',
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
<body class="bg-gray-50 dark:bg-gray-950 text-gray-900 dark:text-gray-100 font-sans h-screen flex flex-col md:flex-row overflow-hidden transition-colors duration-300">

  <!-- Page Loader -->
  <div id="page-loader" class="fixed inset-0 z-[99999] flex items-center justify-center bg-white text-primary-600" style="background:#f8fafc;color:#4f46e5;">
    <div class="flex flex-col items-center gap-4 px-6 py-6 rounded-[28px] shadow-2xl border border-slate-200/80 bg-white">
      <div class="loader-ring"></div>
      <div class="text-center">
        <p class="text-xl font-semibold">QuizB sedang dimuat</p>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Silakan tunggu sebentar, kami menyiapkan kontennya.</p>
      </div>
    </div>
  </div>
  <noscript><style>#page-loader{display:none!important;}</style></noscript>

  <!-- Toast Notification -->
  <div x-show="toast.show" x-transition:enter="transition ease-out duration-300"
       x-transition:enter-start="opacity-0 translate-y-2" x-transition:enter-end="opacity-100 translate-y-0"
       x-transition:leave="transition ease-in duration-200" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
       class="fixed top-4 right-4 z-[9999] flex items-center gap-3 px-4 py-3 rounded-xl shadow-2xl text-white text-sm font-medium max-w-sm"
       :class="{ 'bg-green-500': toast.type==='success', 'bg-red-500': toast.type==='error', 'bg-blue-500': toast.type==='info', 'bg-yellow-500': toast.type==='warning' }">
    <span x-text="toast.icon" class="text-lg"></span>
    <span x-text="toast.message"></span>
  </div>

  
  <!-- ── DESKTOP SIDEBAR ── -->
  <aside x-show="!currentRoute.startsWith('/play/') && currentRoute !== '/onboarding'"
         class="hidden md:flex flex-col w-64 h-full bg-white dark:bg-gray-900 border-r border-gray-200/50 dark:border-gray-800/50 flex-shrink-0 z-50 shadow-sm relative">
    <div class="h-16 flex items-center px-6 border-b border-gray-100 dark:border-gray-800/50 flex-shrink-0">
      <a :href="user ? '#/dashboard' : '#/'" @click.prevent="navigate(user ? '/dashboard' : '/')" class="flex items-center gap-2 group">
        <div class="w-8 h-8 bg-gradient-to-br from-primary-500 to-primary-700 rounded-lg flex items-center justify-center shadow-lg group-hover:scale-105 transition-transform">
          <span class="text-white font-bold text-sm">Q</span>
        </div>
        <span class="font-bold text-xl bg-gradient-to-r from-primary-600 to-primary-400 bg-clip-text text-transparent">QuizB</span>
      </a>
    </div>
    <div class="px-4 py-4 border-b border-gray-100 dark:border-gray-800/50 flex-shrink-0" x-data="{ focused: false }">
      <div class="relative">
        <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
        </svg>
        <input type="search" x-model="search.q"
               @focus="focused = true"
               @input.debounce.300ms="if(search.q.trim().length >= 2) { if(currentRoute !== '/search') navigate('/search'); loadSearch(search.q); } else { search.results = []; }"
               @keydown.escape="search.q=''; search.results=[]; $el.blur(); focused=false"
               @keydown.enter="search.q.trim().length >= 2 && (currentRoute !== '/search' ? navigate('/search') : loadSearch(search.q))"
               placeholder="Cari quiz..."
               class="w-full pl-9 pr-3 py-2 text-sm bg-gray-100 dark:bg-gray-800 border border-transparent rounded-xl focus:outline-none focus:ring-2 focus:ring-primary-500/30 focus:border-primary-400 focus:bg-white dark:focus:bg-gray-700 transition-all placeholder-gray-400"/>
      </div>
    </div>
    <nav class="flex-1 overflow-y-auto px-3 py-4 space-y-1 custom-scrollbar">
      <template x-for="item in navItems" :key="item.href">
        <a :href="'#' + item.href" @click.prevent="navigate(item.href)"
           class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium transition-all duration-150"
           :class="currentRoute === item.href ? 'bg-primary-100 dark:bg-primary-900/40 text-primary-600 dark:text-primary-400' : 'text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800 hover:text-gray-900 dark:hover:text-gray-100'">
             <span x-text="item.label"></span>
        </a>
      </template>
    </nav>
    <div class="p-4 border-t border-gray-100 dark:border-gray-800 flex flex-col gap-3 flex-shrink-0">
      <div class="flex items-center justify-between">
        <button @click="toggleDark()" class="flex items-center justify-center p-2 rounded-xl text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors flex-1 mr-2" title="Toggle dark mode">
          <svg x-show="!darkMode" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/></svg>
          <svg x-show="darkMode"  class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
        </button>
        <template x-if="user">
          <div class="flex items-center gap-2">
            <div class="relative" x-data="{ isOpen: false }">
              <button @click="isOpen=!isOpen; if(isOpen) loadNotifications()" class="relative p-2 rounded-xl text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors" title="Notifikasi">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
                <span x-show="notif.unreadCount > 0" class="absolute top-0.5 right-0.5 min-w-[1.1rem] h-[1.1rem] bg-red-500 text-white text-[10px] font-bold rounded-full flex items-center justify-center px-0.5" x-text="notif.unreadCount > 99 ? '99+' : notif.unreadCount"></span>
              </button>
              <div x-show="isOpen" @click.outside="isOpen=false" x-transition class="absolute bottom-full left-0 mb-2 w-80 bg-white dark:bg-gray-900 rounded-2xl shadow-2xl border border-gray-200 dark:border-gray-800 z-50 flex flex-col overflow-hidden" style="max-height:480px">
                   <div class="flex items-center justify-between px-4 py-3 border-b border-gray-100 dark:border-gray-800 flex-shrink-0">
                     <span class="font-semibold text-sm text-gray-800 dark:text-gray-100">🔔 Notifikasi</span>
                     <div class="flex items-center gap-2">
                       <button x-show="notif.unreadCount > 0" @click="markAllNotifRead()" class="text-xs text-primary-600 dark:text-primary-400 hover:underline">Baca semua</button>
                       <button x-show="notif.list.some(n => n.is_read)" @click="clearReadNotif()" class="text-xs text-gray-400 hover:text-red-500 hover:underline">Hapus yg dibaca</button>
                     </div>
                   </div>
                   <div class="overflow-y-auto flex-1 custom-scrollbar">
                     <div x-show="notif.loading" class="flex justify-center py-8"><div class="w-5 h-5 border-2 border-primary-200 border-t-primary-600 rounded-full animate-spin"></div></div>
                     <div x-show="!notif.loading && notif.list.length === 0" class="text-center py-10 text-gray-400"><p class="text-3xl mb-2">🔔</p><p class="text-xs">Tidak ada notifikasi</p></div>
                     <template x-for="n in notif.list" :key="n.id">
                       <div @click="clickNotif(n); isOpen=false" class="flex items-start gap-3 px-4 py-3 border-b border-gray-50 dark:border-gray-800 hover:bg-gray-50 dark:hover:bg-gray-800/50 cursor-pointer transition-colors" :class="!n.is_read ? 'bg-primary-50/50 dark:bg-primary-900/10' : ''">
                         <div class="w-8 h-8 rounded-full flex items-center justify-center text-lg flex-shrink-0 mt-0.5" :class="!n.is_read ? 'bg-primary-100 dark:bg-primary-900/40' : 'bg-gray-100 dark:bg-gray-800'" x-text="{challenge:'⚔️',challenge_result:'🏆',message:'💬',system:'📢',new_user:'👤',new_question:'📝'}[n.type] || '🔔'"></div>
                         <div class="flex-1 min-w-0">
                           <p class="text-sm font-medium text-gray-900 dark:text-white leading-snug" :class="!n.is_read ? 'font-semibold' : ''" x-text="n.title"></p>
                           <p class="text-xs text-gray-300 dark:text-gray-600 mt-1" x-text="formatRelative(n.created_at)"></p>
                         </div>
                         <div x-show="!n.is_read" class="w-2 h-2 rounded-full bg-primary-500 flex-shrink-0 mt-2"></div>
                       </div>
                     </template>
                   </div>
              </div>
            </div>
            <button @click="navigate('/messages')" class="relative p-2 rounded-xl text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors" title="Pesan">
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
              <span x-show="msgs.unreadCount > 0" class="absolute top-0.5 right-0.5 min-w-[1.1rem] h-[1.1rem] bg-red-500 text-white text-[10px] font-bold rounded-full flex items-center justify-center px-0.5" x-text="msgs.unreadCount > 99 ? '99+' : msgs.unreadCount"></span>
            </button>
          </div>
        </template>
      </div>
      <template x-if="!user">
        <div class="flex flex-col gap-2 mt-2">
          <a href="#/login" @click.prevent="navigate('/login')" class="btn-secondary text-sm px-4 py-2 text-center w-full">Masuk</a>
          <a href="#/register" @click.prevent="navigate('/register')" class="btn-primary text-sm px-4 py-2 text-center w-full">Daftar</a>
        </div>
      </template>
      <template x-if="user">
        <div class="relative w-full" x-data="{ isOpen: false }">
          <button @click="isOpen=!isOpen" class="flex items-center gap-3 p-2 w-full rounded-xl hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors">
            <div class="w-9 h-9 rounded-full bg-gradient-to-br from-primary-400 to-primary-600 flex items-center justify-center text-white font-semibold flex-shrink-0" x-text="user.name.charAt(0).toUpperCase()"></div>
            <div class="flex flex-col items-start flex-1 min-w-0">
               <span class="text-sm font-semibold text-gray-800 dark:text-gray-100 truncate w-full text-left" x-text="user.name"></span>
               <span class="text-xs text-gray-500 dark:text-gray-400 truncate w-full text-left" x-text="user.role === 'admin' ? 'Administrator' : 'Pengguna'"></span>
            </div>
            <svg class="w-4 h-4 text-gray-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7"/></svg>
          </button>
          <div x-show="isOpen" @click.outside="isOpen=false" x-transition class="absolute bottom-full left-0 mb-2 w-full bg-white dark:bg-gray-800 rounded-xl shadow-xl border border-gray-200 dark:border-gray-700 py-1 z-50">
            <a href="#/profile" @click.prevent="navigate('/profile');isOpen=false" class="flex items-center gap-2 px-4 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700">👤 Profil</a>
            <a href="#/history" @click.prevent="navigate('/history');isOpen=false" class="flex items-center gap-2 px-4 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700">📋 Histori</a>
            <hr class="my-1 border-gray-200 dark:border-gray-700"/>
            <button @click="logout();isOpen=false" class="w-full flex items-center gap-2 px-4 py-2 text-sm text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-gray-700">🚪 Keluar</button>
          </div>
        </div>
      </template>
    </div>
  </aside>

  <!-- ── MAIN CONTENT WRAPPER ── -->
  <div class="flex flex-col flex-1 h-full overflow-y-auto relative w-full scroll-smooth custom-scrollbar">

    <!-- MOBILE TOP BAR (Only visible on Mobile) -->
    <nav x-show="!currentRoute.startsWith('/play/') && currentRoute !== '/onboarding'"
         class="md:hidden sticky top-0 z-50 bg-white/90 dark:bg-gray-900/90 backdrop-blur-md border-b border-gray-200/50 dark:border-gray-800/50">
      <div class="flex items-center justify-center h-14 w-full">
        <a :href="user ? '#/dashboard' : '#/'" @click.prevent="navigate(user ? '/dashboard' : '/')" class="flex items-center gap-2 group">
          <div class="w-8 h-8 bg-gradient-to-br from-primary-500 to-primary-700 rounded-lg flex items-center justify-center shadow-lg group-hover:scale-105 transition-transform">
            <span class="text-white font-bold text-sm">Q</span>
          </div>
          <span class="font-bold text-xl bg-gradient-to-r from-primary-600 to-primary-400 bg-clip-text text-transparent">QuizB</span>
        </a>
      </div>
    </nav>


    <!-- ACTIVITY TICKER — sticky tepat di bawah nav (top-0 saat fullscreen quiz) -->
  <div x-data="globalTicker()"
       x-show="currentItem && currentRoute !== '/onboarding' && currentRoute !== '/messages' && !currentRoute.startsWith('/play/')"
       x-cloak
       class="sticky z-40 border-b border-gray-200/30 dark:border-gray-700/30 bg-white/20 dark:bg-gray-900/20 backdrop-blur-sm md:top-0"
       :class="currentRoute.startsWith('/play/') ? 'top-0' : 'top-14'">

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

  <!-- Page Transition Overlay — cepat 120ms, logo Q -->
  <!-- Nav Progress Bar — thin 2px bar, no blur, no lag -->
  <div id="nav-progress"
       style="position:fixed;top:0;left:0;height:2px;width:0%;opacity:0;z-index:9999;pointer-events:none;background:linear-gradient(to right,#6366f1,#818cf8,#a5b4fc);border-radius:0 2px 2px 0;will-change:width,opacity;"></div>

  <!-- PAGE CONTAINER -->
    <!-- PAGE CONTAINER -->
  <main id="app"
        class="pb-16 md:pb-0 flex-grow flex-shrink-0"
        :class="currentRoute.startsWith('/play/') ? 'min-h-screen' : 'min-h-[calc(100vh-4rem)]'">

    <!-- SEARCH PAGE -->
    <div x-show="currentRoute === '/search'">
      <?php include 'pages/search.html'; ?>
    </div>

    <!-- HOME PAGE -->
    <div x-show="currentRoute === '/'">
      <?php include 'pages/home.html'; ?>
    </div>

    <!-- CATEGORIES PAGE -->
    <div x-show="currentRoute === '/categories'">
      <?php include 'pages/categories.html'; ?>
    </div>

    <!-- QUIZ LIST PAGE -->
    <div x-show="currentRoute === '/quizzes'">
      <?php include 'pages/quizzes.html'; ?>
    </div>

    <!-- QUIZ DETAIL PAGE -->
    <div x-show="currentRoute.startsWith('/quiz/')">
      <?php include 'pages/quiz-detail.html'; ?>
    </div>

    <!-- QUIZ ENGINE PAGE -->
    <template x-if="currentRoute.startsWith('/play/')">
      <div>
        <?php include 'pages/quiz-engine.html'; ?>
      </div>
    </template>

    <!-- RESULT PAGE -->
    <div x-show="currentRoute.startsWith('/result/')">
      <?php include 'pages/result.html'; ?>
    </div>

    <!-- LEADERBOARD PAGE -->
    <div x-show="currentRoute === '/leaderboard'">
      <?php include 'pages/leaderboard.html'; ?>
    </div>

    <!-- DASHBOARD PAGE -->
    <div x-show="currentRoute === '/dashboard'">
      <?php include 'pages/dashboard.html'; ?>
    </div>

    <!-- HISTORY PAGE -->
    <div x-show="currentRoute === '/history'">
      <?php include 'pages/history.html'; ?>
    </div>

    <!-- LOGIN PAGE -->
    <div x-show="currentRoute === '/login'">
      <?php include 'pages/login.html'; ?>
    </div>

    <!-- REGISTER PAGE -->
    <div x-show="currentRoute === '/register'">
      <?php include 'pages/register.html'; ?>
    </div>

    <!-- EMAIL SENT PAGE (setelah register) -->
    <div x-show="currentRoute === '/email-sent'">
      <?php include 'pages/email-sent.html'; ?>
    </div>

    <!-- VERIFY ERROR PAGE (token tidak valid) -->
    <div x-show="currentRoute === '/verify-error'">
      <?php include 'pages/verify-error.html'; ?>
    </div>

    <!-- GOOGLE SETUP PAGE -->
    <div x-show="currentRoute === '/google-setup'">
      <?php include 'pages/google-setup.html'; ?>
    </div>

    <!-- PROFILE PAGE -->
    <div x-show="currentRoute === '/profile'">
      <?php include 'pages/profile.html'; ?>
    </div>

    <!-- SETTINGS PAGE -->
    <div x-show="currentRoute === '/settings'">
      <?php include 'pages/settings.html'; ?>
    </div>

    <!-- CLASSROOM LIST PAGE -->
    <div x-show="currentRoute === '/classroom'">
      <?php include 'pages/classroom.html'; ?>
    </div>

    <!-- CLASSROOM DETAIL PAGE -->
    <div x-show="currentRoute.startsWith('/classroom/') && currentRoute !== '/classroom'">
      <?php include 'pages/classroom-detail.html'; ?>
    </div>

    <!-- CHALLENGES PAGE -->
    <div x-show="currentRoute.startsWith('/assignment/')">
      <?php include 'pages/assignment-results.html'; ?>
      <?php include 'pages/assignment-monitor.html'; ?>
    </div>

    <div x-show="currentRoute === '/challenges'">
      <?php include 'pages/challenges.html'; ?>
    </div>

    <!-- ACTIVITY PAGE (public) -->
    <div x-show="currentRoute === '/activity'">
      <?php include 'pages/activity.html'; ?>
    </div>

    <!-- PUBLIC HISTORY PAGE (by user / quiz / mode) -->
    <div x-show="currentRoute === '/public-history'">
      <?php include 'pages/public-history.html'; ?>
    </div>

    <!-- ADMIN PAGE -->
    <div x-show="currentRoute.startsWith('/admin')">
      <?php include 'pages/admin.html'; ?>
    </div>

    <!-- MESSAGES PAGE (full height, no footer) -->
    <div x-show="currentRoute === '/messages'" x-cloak>
      <?php include 'pages/messages.html'; ?>
    </div>

    <!-- ONBOARDING PAGE -->
    <div x-show="currentRoute === '/onboarding'">
      <?php include 'pages/onboarding.html'; ?>
    </div>

    <!-- ABOUT PAGE -->
    <div x-show="currentRoute === '/about'">
      <?php include 'pages/about.html'; ?>
    </div>

    <!-- PRIVACY PAGE -->
    <div x-show="currentRoute === '/privacy'">
      <?php include 'pages/privacy.html'; ?>
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
  <footer x-show="!currentRoute.startsWith('/play/') && currentRoute !== '/onboarding' && currentRoute !== '/messages' && currentRoute !== '/play'"
          class="bg-white dark:bg-gray-900 border-t border-gray-200 dark:border-gray-800 mt-auto flex-shrink-0 sticky bottom-0 z-40">
    <div class="w-full py-8">
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
          <a href="#/about" @click.prevent="navigate('/about')" class="hover:text-primary-500 transition-colors">Tentang</a>
          <a href="#/privacy" @click.prevent="navigate('/privacy')" class="hover:text-primary-500 transition-colors">Privasi</a>
        </div>
      </div>
    </div>
  </footer>

  <!-- Alpine.js CDN -->
  <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>

  <!-- Flash message dari PHP session (diset oleh verify-email.php) -->
  <script>
    window._phpFlash = {
      type: <?= json_encode($flashType) ?>,
      msg:  <?= json_encode($flashMsg) ?>,
      isNewUser: <?= $isNewUser ? 'true' : 'false' ?>
    };
  </script>

  <!-- SheetJS untuk export Excel -->
  <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>

  <!-- html2pdf untuk export PDF -->
  <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>

  <!-- JSZip untuk export Word (DOCX) -->
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>

  <!-- App Scripts -->
  <script src="assets/js/utils.js"></script>
  <script src="assets/js/quiz-engine.js"></script>
  <script src="assets/js/app.js"></script>

  <!-- ═══════════════════════════════════════════════
       MOBILE BOTTOM NAV — hanya tampil di layar kecil
       Tersembunyi di /play/, /onboarding, /messages
  ═══════════════════════════════════════════════ -->
  <nav x-show="!currentRoute.startsWith('/play/') && currentRoute !== '/onboarding'"
       x-cloak
       class="fixed bottom-0 left-0 right-0 z-50 md:hidden bg-white/95 dark:bg-gray-900/95 backdrop-blur-md border-t border-gray-200 dark:border-gray-800 safe-area-pb"
       style="padding-bottom: env(safe-area-inset-bottom)">
    <div class="flex items-stretch h-14">

      <!-- Beranda -->
      <button @click="navigate(user ? '/dashboard' : '/')"
              class="flex-1 flex flex-col items-center justify-center gap-0.5 transition-colors"
              :class="(user ? currentRoute === '/dashboard' : currentRoute === '/') ? 'text-primary-600 dark:text-primary-400' : 'text-gray-500 dark:text-gray-400'">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
        </svg>
        <span class="text-[10px] font-medium leading-none">Beranda</span>
      </button>

      <!-- Pencarian -->
      <button @click="navigate('/search')"
              class="flex-1 flex flex-col items-center justify-center gap-0.5 transition-colors"
              :class="currentRoute === '/search' ? 'text-primary-600 dark:text-primary-400' : 'text-gray-500 dark:text-gray-400'">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
        </svg>
        <span class="text-[10px] font-medium leading-none">Cari</span>
      </button>

      <!-- Slot 3: Tantangan (login) / Kategori (guest) -->
      <template x-if="user">
        <button @click="navigate('/challenges')"
                class="flex-1 flex flex-col items-center justify-center gap-0.5 transition-colors relative"
                :class="currentRoute === '/challenges' ? 'text-primary-600 dark:text-primary-400' : 'text-gray-500 dark:text-gray-400'">
          <span class="relative inline-flex">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M14.121 14.121L19 19m-7-7l7-7m-7 7l-2.879 2.879M12 12L9.121 9.121m0 5.758a3 3 0 10-4.243 4.243 3 3 0 004.243-4.243zm0-5.758a3 3 0 10-4.243-4.243 3 3 0 004.243 4.243z"/>
            </svg>
            <span x-show="challenge.pendingCount > 0"
                  class="absolute -top-1.5 -right-1.5 w-4 h-4 bg-red-500 text-white text-[9px] font-bold rounded-full flex items-center justify-center"
                  x-text="challenge.pendingCount > 9 ? '9+' : challenge.pendingCount"></span>
          </span>
          <span class="text-[10px] font-medium leading-none">Tantangan</span>
        </button>
      </template>
      <template x-if="!user">
        <button @click="navigate('/categories')"
                class="flex-1 flex flex-col items-center justify-center gap-0.5 transition-colors"
                :class="currentRoute === '/categories' ? 'text-primary-600 dark:text-primary-400' : 'text-gray-500 dark:text-gray-400'">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zm10 0a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zm10 0a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"/>
          </svg>
          <span class="text-[10px] font-medium leading-none">Kategori</span>
        </button>
      </template>

      <!-- Slot 4: Pesan (login) / Leaderboard (guest) -->
      <template x-if="user">
        <button @click="navigate('/messages')"
                class="flex-1 flex flex-col items-center justify-center gap-0.5 transition-colors relative"
                :class="currentRoute === '/messages' ? 'text-primary-600 dark:text-primary-400' : 'text-gray-500 dark:text-gray-400'">
          <span class="relative inline-flex">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/>
            </svg>
            <span x-show="user && msgs.unreadCount > 0"
                  class="absolute -top-1.5 -right-1.5 min-w-[1rem] h-4 bg-red-500 text-white text-[9px] font-bold rounded-full flex items-center justify-center px-0.5"
                  x-text="msgs.unreadCount > 99 ? '99+' : msgs.unreadCount"></span>
          </span>
          <span class="text-[10px] font-medium leading-none">Pesan</span>
        </button>
      </template>
      <template x-if="!user">
        <button @click="navigate('/leaderboard')"
                class="flex-1 flex flex-col items-center justify-center gap-0.5 transition-colors"
                :class="currentRoute === '/leaderboard' ? 'text-primary-600 dark:text-primary-400' : 'text-gray-500 dark:text-gray-400'">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
          </svg>
          <span class="text-[10px] font-medium leading-none">Peringkat</span>
        </button>
      </template>

      <!-- Slot 5: Notifikasi (login) / Aktivitas (guest) — dengan panel slide-up -->
        <div class="flex-1 relative" x-data="{ isOpen: false }">

        <!-- Guest: tombol Aktivitas -->
        <template x-if="!user">
          <button @click="navigate('/activity')"
                  class="w-full h-full flex flex-col items-center justify-center gap-0.5 transition-colors"
                  :class="currentRoute === '/activity' ? 'text-primary-600 dark:text-primary-400' : 'text-gray-500 dark:text-gray-400'">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/>
            </svg>
            <span class="text-[10px] font-medium leading-none">Aktivitas</span>
          </button>
        </template>

        <!-- Login: tombol Notifikasi -->
        <template x-if="user">
        <button @click="isOpen=!isOpen; isOpen && loadNotifications()"
                class="w-full h-full flex flex-col items-center justify-center gap-0.5 transition-colors relative"
                :class="isOpen ? 'text-primary-600 dark:text-primary-400' : 'text-gray-500 dark:text-gray-400'">
          <span class="relative inline-flex">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
            </svg>
            <span x-show="user && notif.unreadCount > 0"
                  class="absolute -top-1.5 -right-1.5 w-4 h-4 bg-red-500 text-white text-[9px] font-bold rounded-full flex items-center justify-center"
                  x-text="notif.unreadCount > 99 ? '99+' : notif.unreadCount"></span>
          </span>
          <span class="text-[10px] font-medium leading-none">Notifikasi</span>
        </button>
        </template>

        <!-- Notif slide-up panel (mobile) — hanya muncul saat login -->
        <template x-if="user">
        <div x-show="isOpen" @click.outside="isOpen=false" x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="opacity-0 translate-y-4" x-transition:enter-end="opacity-100 translate-y-0"
             x-transition:leave="transition ease-in duration-150"
             x-transition:leave-start="opacity-100 translate-y-0" x-transition:leave-end="opacity-0 translate-y-4"
             class="absolute bottom-full right-0 mb-2 w-80 max-h-[70vh] bg-white dark:bg-gray-900 rounded-2xl shadow-2xl border border-gray-200 dark:border-gray-800 flex flex-col overflow-hidden z-50"
             style="right: -4rem">
          <div class="flex items-center justify-between px-4 py-3 border-b border-gray-100 dark:border-gray-800 flex-shrink-0">
            <span class="font-semibold text-sm text-gray-800 dark:text-gray-100">🔔 Notifikasi</span>
            <div class="flex items-center gap-2">
              <button x-show="notif.unreadCount > 0" @click="markAllNotifRead()"
                      class="text-xs text-primary-600 dark:text-primary-400 hover:underline">Baca semua</button>
              <button x-show="notif.list.some(n => n.is_read)" @click="clearReadNotif()"
                      class="text-xs text-gray-400 hover:text-red-500 hover:underline">Hapus yg dibaca</button>
              <button @click="isOpen=false" class="text-gray-400 hover:text-gray-600 text-lg leading-none ml-1">×</button>
            </div>
          </div>
          <div class="overflow-y-auto flex-1">
            <div x-show="notif.loading" class="flex justify-center py-8">
              <div class="w-5 h-5 border-2 border-primary-200 border-t-primary-600 rounded-full animate-spin"></div>
            </div>
            <div x-show="!notif.loading && notif.list.length === 0" class="text-center py-10 text-gray-400">
              <p class="text-3xl mb-2">🔔</p>
              <p class="text-xs">Tidak ada notifikasi</p>
            </div>
            <template x-for="n in notif.list" :key="n.id">
              <div @click="clickNotif(n); isOpen=false"
                   class="flex items-start gap-3 px-4 py-3 border-b border-gray-50 dark:border-gray-800 hover:bg-gray-50 dark:hover:bg-gray-800/50 cursor-pointer transition-colors"
                   :class="!n.is_read ? 'bg-primary-50/50 dark:bg-primary-900/10' : ''">
                <div class="w-8 h-8 rounded-full flex items-center justify-center text-lg flex-shrink-0 mt-0.5"
                     :class="!n.is_read ? 'bg-primary-100 dark:bg-primary-900/40' : 'bg-gray-100 dark:bg-gray-800'"
                     x-text="{challenge:'⚔️',challenge_result:'🏆',message:'💬',system:'📢',new_user:'👤',new_question:'📝'}[n.type] || '🔔'"></div>
                <div class="flex-1 min-w-0">
                  <p class="text-sm font-medium text-gray-900 dark:text-white leading-snug"
                     :class="!n.is_read ? 'font-semibold' : ''" x-text="n.title"></p>
                  <p class="text-xs text-gray-300 dark:text-gray-600 mt-1" x-text="formatRelative(n.created_at)"></p>
                </div>
                <div x-show="!n.is_read" class="w-2 h-2 rounded-full bg-primary-500 flex-shrink-0 mt-2"></div>
              </div>
            </template>
          </div>
        </div>
        </template>

      </div>

      <!-- Profil -->
      <div class="flex-1 relative" x-data="{ isOpen: false }">
        <button @click="user ? (isOpen=!isOpen) : navigate('/login')"
                class="w-full h-full flex flex-col items-center justify-center gap-0.5 transition-colors"
                :class="(currentRoute==='/profile'||currentRoute==='/dashboard'||currentRoute==='/settings'||currentRoute==='/history') ? 'text-primary-600 dark:text-primary-400' : 'text-gray-500 dark:text-gray-400'">
          <template x-if="user">
            <div class="w-5 h-5 rounded-full bg-gradient-to-br from-primary-400 to-primary-600 flex items-center justify-center text-white font-bold text-[10px]"
                 x-text="user.name.charAt(0).toUpperCase()"></div>
          </template>
          <template x-if="!user">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
            </svg>
          </template>
          <span class="text-[10px] font-medium leading-none">Profil</span>
        </button>

        <!-- Profile mini menu (mobile) -->
        <div x-show="isOpen" @click.outside="isOpen=false"
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="opacity-0 translate-y-2" x-transition:enter-end="opacity-100 translate-y-0"
             x-transition:leave="transition ease-in duration-150"
             x-transition:leave-start="opacity-100 translate-y-0" x-transition:leave-end="opacity-0 translate-y-2"
             class="absolute bottom-full right-0 mb-2 w-52 bg-white dark:bg-gray-800 rounded-2xl shadow-2xl border border-gray-200 dark:border-gray-700 py-1 z-50"
             style="right: 0">
          <!-- User info -->
          <div class="px-4 py-3 border-b border-gray-100 dark:border-gray-700">
            <p class="font-semibold text-sm text-gray-900 dark:text-white" x-text="user?.name"></p>
            <p class="text-xs text-gray-400 truncate" x-text="user?.email"></p>
          </div>
          <!-- Navigasi utama (sama seperti desktop) -->
          <div class="pt-1">
            <a @click.prevent="navigate(user ? '/dashboard' : '/');isOpen=false" :href="user ? '#/dashboard' : '#/'"
               class="flex items-center gap-2 px-4 py-2.5 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700"
               :class="(user ? currentRoute==='/dashboard' : currentRoute==='/') ? 'text-primary-600 dark:text-primary-400 font-medium' : ''">🏠 Beranda</a>
            <a @click.prevent="navigate('/activity');isOpen=false" href="#/activity"
               class="flex items-center gap-2 px-4 py-2.5 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700"
               :class="currentRoute==='/activity' ? 'text-primary-600 dark:text-primary-400 font-medium' : ''">🌐 Aktivitas</a>
            <template x-if="user">
              <a @click.prevent="navigate('/classroom');isOpen=false" href="#/classroom"
                 class="flex items-center gap-2 px-4 py-2.5 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700"
                 :class="currentRoute.startsWith('/classroom') ? 'text-primary-600 dark:text-primary-400 font-medium' : ''">🏫 Kelas</a>
            </template>
            <template x-if="user">
              <a @click.prevent="navigate('/challenges');isOpen=false" href="#/challenges"
                 class="flex items-center gap-2 px-4 py-2.5 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700"
                 :class="currentRoute==='/challenges' ? 'text-primary-600 dark:text-primary-400 font-medium' : ''">
                <span>⚔️ Tantangan</span>
                <span x-show="user && challenge.pendingCount > 0"
                      class="ml-auto text-[10px] px-1.5 py-0.5 bg-red-500 text-white rounded-full font-bold"
                      x-text="challenge.pendingCount > 9 ? '9+' : challenge.pendingCount"></span>
              </a>
            </template>
          </div>

          <!-- Pemisah -->
          <div class="border-t border-gray-100 dark:border-gray-700 my-1"></div>

          <!-- Menu akun -->
          <a @click.prevent="navigate('/dashboard');isOpen=false" href="#/dashboard"
             class="flex items-center gap-2 px-4 py-2.5 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700">📊 Dashboard</a>
          <a @click.prevent="navigate('/profile');isOpen=false" href="#/profile"
             class="flex items-center gap-2 px-4 py-2.5 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700">👤 Profil</a>
          <a @click.prevent="navigate('/history');isOpen=false" href="#/history"
             class="flex items-center gap-2 px-4 py-2.5 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700">📋 Histori</a>
          <a @click.prevent="navigate('/settings');isOpen=false" href="#/settings"
             class="flex items-center gap-2 px-4 py-2.5 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700">⚙️ Pengaturan</a>
          <template x-if="user && user.role === 'admin'">
            <a @click.prevent="navigate('/admin');isOpen=false" href="#/admin"
               class="flex items-center gap-2 px-4 py-2.5 text-sm text-purple-600 dark:text-purple-400 hover:bg-gray-50 dark:hover:bg-gray-700">🛡️ Admin Panel</a>
          </template>

          <!-- Pemisah + Logout -->
          <div class="border-t border-gray-100 dark:border-gray-700 mt-1 pt-1">
            <button @click="logout();isOpen=false"
                    class="w-full flex items-center gap-2 px-4 py-2.5 text-sm text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-gray-700 rounded-b-2xl transition-colors">
              🚪 Keluar
            </button>
          </div>
        </div>
      </div>

    </div>
  </nav>

