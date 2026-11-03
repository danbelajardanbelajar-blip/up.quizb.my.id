<?php $panelId = !empty($notificationPanelMobile) ? 'notification-panel-mobile' : 'notification-panel-desktop'; ?>
<section id="<?= $panelId ?>" x-show="notif.show" x-cloak x-transition.opacity
         @click.outside="if (notif.show && !$event.target.closest('[data-notification-toggle]')) closeNotifications(false)"
         @keydown.escape.stop.prevent="closeNotifications()"
         aria-labelledby="<?= $panelId ?>-title"
         class="notification-panel <?= !empty($notificationPanelMobile) ? 'notification-panel-mobile' : 'notification-panel-desktop' ?>">
  <header class="notification-panel-header">
    <div>
      <p class="notification-eyebrow">PUSAT INFORMASI</p>
      <h2 id="<?= $panelId ?>-title">Notifikasi <span x-text="notif.unreadCount" x-show="notif.unreadCount > 0"></span></h2>
    </div>
    <button type="button" @click="closeNotifications()" class="notification-close" aria-label="Tutup notifikasi">✕</button>
  </header>
  <div class="notification-toolbar">
    <button type="button" @click="markAllNotifRead()" :disabled="notif.busy || notif.readingIds.length > 0 || notif.unreadCount === 0">Baca semua</button>
    <button type="button" @click="clearReadNotif()" :disabled="notif.busy || notif.readingIds.length > 0 || !notif.list.some(n => n.is_read)">Hapus yang dibaca</button>
  </div>
  <div class="notification-scroll" :aria-busy="notif.loading || notif.busy">
    <div x-show="notif.error" class="notification-error" role="alert">
      <p x-text="notif.error"></p>
      <button type="button" @click="notif.error = ''; loadNotifications(); fetchNotificationCounts()" :disabled="notif.loading">Coba lagi</button>
    </div>
    <p x-show="notif.loading" class="notification-status" role="status">Memuat notifikasi…</p>
    <div x-show="!notif.loading && !notif.error && notif.list.length === 0" class="notification-empty">
      <span aria-hidden="true">🔔</span><h3>Semua sudah beres</h3><p>Belum ada notifikasi untuk ditampilkan.</p>
    </div>
    <template x-for="n in notif.list" :key="n.id">
      <button type="button" @click="clickNotif(n)" class="notification-item"
              :class="!n.is_read ? 'notification-item-unread' : ''"
              :disabled="notif.busy || notif.readingIds.includes(n.id)">
        <span class="notification-icon" aria-hidden="true"
              x-text="{challenge:'⚔️',challenge_accepted:'🤝',challenge_result:'🏆',new_user:'👤',new_question:'📝',app_update:'🚀',system:'📢'}[n.type] || '🔔'"></span>
        <span class="notification-copy">
          <span class="notification-title" x-text="n.title"></span>
          <span class="notification-body" x-show="n.body" x-text="n.body"></span>
          <span class="notification-time" x-text="formatRelative(n.created_at)"></span>
          <span x-show="!n.is_read" class="sr-only">Belum dibaca</span>
        </span>
        <span class="notification-dot" x-show="!n.is_read" aria-hidden="true"></span>
      </button>
    </template>
    <button type="button" x-show="notif.list.length < notif.total" @click="loadNotifications(true)"
            class="notification-more" :disabled="notif.loading || notif.busy">Muat notifikasi sebelumnya ↓</button>
  </div>
  <footer class="notification-panel-footer" x-show="notif.total > 0">
    <span x-text="notif.list.length + ' dari ' + notif.total + ' notifikasi'"></span>
    <button type="button" @click="loadNotifications(); fetchNotificationCounts()" :disabled="notif.loading || notif.busy">Segarkan</button>
  </footer>
</section>
