# Deployment notifikasi QuizB — Rumahweb

Source di server: `/home/quic1934/public_html/subdomain/u.quizb.my.id/`.
Alamat publik: `https://quizb.my.id/`, dipetakan oleh konfigurasi domain utama.
Jalankan Git pull pada folder source tersebut, bukan pada folder domain utama yang berbeda.

## Setelah pull

```sh
cd /home/quic1934/public_html/subdomain/u.quizb.my.id
git pull --ff-only
```

Cara otomatis (memeriksa konfigurasi, migrasi, OAuth, dan memasang satu cron tanpa mengubah cron lain):

```sh
bash bin/setup-notifications.sh
```

Jika PHP CLI default terlalu lama, gunakan `PHP_BIN=/path/ke/php8 bash bin/setup-notifications.sh` dengan path PHP yang tersedia di hosting. Jika akses `crontab` dibatasi, script menampilkan command yang dapat dimasukkan ke cPanel. Cara manual atau pemeriksaan ulang:

```sh
command -v php
php -v
php bin/notification-worker.php --migrate --check --probe-firebase
```

PHP CLI harus versi 8 atau lebih baru, dengan PDO MySQL, cURL, dan OpenSSL. Gunakan executable PHP yang sesuai dengan versi PHP website jika `php -v` menunjukkan versi lama. Catat path absolut dari `command -v php`; path itu dipakai untuk cron.

Perintah `--migrate` membuat tabel outbox, delivery, perangkat, dan penyembunyian tantangan tanpa menghapus tabel/data lama. Endpoint juga dapat menginisialisasi tabel pendukung saat pertama dibutuhkan. `--check` memeriksa konfigurasi dan menampilkan jumlah job per status. `--probe-firebase` menguji pertukaran OAuth ke Google tanpa mencetak token dan tanpa mengirim notifikasi kepada pengguna.

Hasil yang diharapkan: `Notification schema ready`, seluruh pemeriksaan `OK`, dan `OK Google OAuth accepted`. Periksa log error hosting jika ada kegagalan. Jangan lanjut menyatakan push Android siap sebelum perintah ini lulus di server, meskipun pemeriksaan lokal lulus.

## Kredensial

Default yang kompatibel dengan instalasi sebelumnya adalah `config/firebase-service-account.json`. File tersebut tetap diabaikan Git. Versi terbaru tidak lagi memuat server key legacy dalam source.

Lebih baik simpan service account di luar `public_html`, misalnya `/home/quic1934/private/quizb-firebase.json`, dengan izin hanya untuk user hosting. Jika ingin memakai lokasi itu, buat `config/notifications.php` di server:

```php
<?php
return ['credentials' => '/home/quic1934/private/quizb-firebase.json'];
```

Jangan melakukan commit file kredensial atau file konfigurasi lokal ini. `config/notifications.example.php` tersedia sebagai contoh. `QUIZB_FIREBASE_CREDENTIALS` juga dapat dipakai sebagai environment variable jika hosting menyediakan pengaturannya.

Folder `config`, `bin`, `tests`, dan `.git` diblokir dari HTTP oleh aturan yang disertakan. Setelah pull, periksa dengan HEAD saja, tanpa mengunduh isi file kredensial:

```sh
curl -I https://quizb.my.id/config/firebase-service-account.json
curl -I https://quizb.my.id/bin/notification-worker.php
curl -I https://quizb.my.id/.git/HEAD
```

Harus menghasilkan 403/404, bukan 200. Pastikan pemetaan `.htaccess` domain utama tetap menerapkan pembatasan di folder source. File worker juga menolak eksekusi dari web secara langsung.

Server key yang dahulu tertulis di source tetap ada dalam riwayat Git. Tinjau dan cabut/rotasi key lama di konsol penyedia jika masih aktif. Menghapus literal dari versi terbaru bukan pencabutan key. Jangan menghapus atau mengganti service-account JSON yang sedang digunakan tanpa menyiapkan penggantinya.

## Cron cPanel

Buka cPanel → Cron Jobs, pilih setiap menit: `* * * * *`.

Jika `command -v php` menghasilkan `/usr/local/bin/php`, perintahnya:

```sh
/usr/local/bin/php -q /home/quic1934/public_html/subdomain/u.quizb.my.id/bin/notification-worker.php --limit=100 --seconds=40 >> /home/quic1934/quizb-notifications.log 2>&1
```

Jika hasilnya path lain, ganti hanya `/usr/local/bin/php` dengan path absolut tersebut. Log sengaja ditempatkan di luar `public_html`. Hindari memasukkan service-account atau token perangkat ke command cron.

Antrean diproses maksimal 40 detik per run. Advisory lock mencegah dua worker mengirim job yang sama secara bersamaan. Kegagalan jaringan, pembatasan laju, masalah autentikasi, dan error sementara dicoba ulang dengan jeda meningkat, maksimum enam percobaan. `Retry-After` dihormati. Receipt per perangkat mencegah pengiriman ulang ke perangkat yang telah berhasil saat perangkat lain perlu retry.

Job lebih dari 24 jam dilewati agar undangan lama tidak tiba terlambat. Payload sensitif dibersihkan setelah job terminal; metadata hasil disimpan hingga 30 hari. Token dengan respons FCM `UNREGISTERED` dicabut. `INVALID_ARGUMENT` tidak otomatis menghapus token karena dapat menunjukkan kesalahan payload.

Tanpa cron, notifikasi web/DB dan popup browser tetap bekerja, tetapi push Android akan menumpuk dalam antrean. Push Android lewat cron bisa terlambat sampai jadwal menit berikutnya; popup browser tetap memakai polling 10 detik.

## Pemeriksaan dan pemulihan

```sh
cd /home/quic1934/public_html/subdomain/u.quizb.my.id
php bin/notification-worker.php --check
php bin/notification-worker.php --limit=20 --seconds=20
```

Ringkasan worker berisi `sent`, `skipped`, `retry`, `failed`, dan `busy`, tanpa token perangkat. Status `sent` berarti FCM menerima pengiriman, bukan jaminan pengguna sudah melihat notifikasi.

Jika konfigurasi sempat salah, perbaiki konfigurasi lalu jadwalkan ulang job gagal yang notifikasinya masih berumur kurang dari sehari:

```sh
php bin/notification-worker.php --retry-failed
php bin/notification-worker.php --limit=100 --seconds=40
```

Receipt `sent` dan token invalid tidak dikirim ulang. Kesalahan permanen payload harus diperbaiki pada sumbernya. Worker yang berhenti mendadak dapat dipulihkan setelah lease lima menit. Tidak ada retry tanpa batas.

## Uji penerimaan di website dan Android

1. Dari tab tamu, login sebagai A. Pastikan badge notifikasi/pesan diperbarui tanpa reload.
2. Buat notifikasi publik/undangan dari akun lain. Panel menampilkan judul, body, waktu, dan status belum dibaca. Tombol “Muat notifikasi sebelumnya” membuka lebih dari 30 item.
3. Klik dua kali cepat pada item yang sama; jumlah belum dibaca tidak boleh berkurang dua kali. Uji “Baca semua”, “Hapus yang dibaca”, kondisi offline/Coba lagi, keyboard Enter/Escape, mobile, dan dark mode.
4. Logout A lalu login B pada tab sama. Daftar, badge, dan pesan A harus hilang. Respons lama tidak boleh mengisi data B.
5. Klik undangan dari lonceng. Harus masuk ke konfirmasi Terima/Tolak, bukan menunggu di arena tanpa menerima. Undangan kedaluwarsa ditolak server. Peserta hanya menyembunyikan kartunya; akun yang tidak terkait tidak boleh menghapus room.
6. Tambahkan soal ke kuis privat. Pengguna di luar kuis tidak menerima broadcast global. Broadcast pembaruan aplikasi hanya boleh dilakukan admin dan membuka Google Play secara eksternal.
7. Pesan dengan tautan `/#/messages?thread=ID` membuka percakapan yang benar dan menolak thread milik akun lain.
8. Pada Android, daftarkan token pada akun A, tunggu pengiriman melalui cron, lalu pindah akun B pada perangkat yang sama. Notifikasi akun A berikutnya tidak boleh dikirim ke perangkat B. Periksa log worker serta tampilan/tujuan saat notifikasi Android disentuh.

## Kontrak klien Android

Server mempertahankan endpoint `POST auth.update_fcm_token` dengan body `{"fcm_token":"..."}`. Aplikasi harus mengirim token setelah login/pergantian akun dan ketika Firebase mengganti token. Token dipindahkan secara atomik ke satu akun aktif. Registrasi lama yang ambigu tidak dipakai untuk pengiriman.

Gunakan sesi/cookie yang sama pada registrasi dan `POST auth.logout`, agar server dapat melepas token perangkat sesi tersebut. Klien juga dapat mengirim token pada body logout atau memanggil `POST auth.unregister_fcm_token` sebelum logout. Logout satu perangkat tidak menghapus perangkat lain. Sesi yang didaftarkan sebelum pembaruan mungkin belum menyimpan identitas perangkat; minta registrasi ulang setelah login/startup.

Payload baru menyertakan `recipient_user_id` dan `notification_id` selain field lama. Klien sebaiknya membandingkan penerima dengan akun aktif sebelum menampilkan data message atau membuka tujuan. Field `link` merupakan tujuan web; undangan `challenge` sebaiknya membuka lobby/konfirmasi, bukan menerima otomatis. Notifikasi Android menggunakan tag berdasarkan ID untuk mengurangi duplikasi tampilan.

Repository ini tidak memuat source APK Android. Perubahan klien, izin notifikasi Android, serta perilaku saat aplikasi background/ditutup harus diverifikasi pada APK sebenarnya. Keberhasilan OAuth lokal tidak membuktikan delivery Android atau konfigurasi cron server.

## Menjalankan tes lokal

Tes backend menolak DSN di luar database lokal bernama `quizb_test_*`. Database ini boleh dikosongkan karena fixture tes dibuat ulang. Jangan menggunakan database website.

```sh
QUIZB_TEST_DSN='mysql:host=127.0.0.1;port=33077;dbname=quizb_test_notifications' php tests/notifications-push.php
node tests/notifications-frontend.cjs
node tests/notifications-api-session.cjs
bash tests/notifications-cron.sh
```

Tes push mencakup backend dan worker dengan transport Firebase tiruan; tidak menghubungi perangkat atau Firebase. Browser regression memerlukan Playwright dan Chromium/Edge lokal:

```sh
node tests/notifications-browser.cjs
```

Gunakan `QUIZB_BROWSER` untuk path executable browser alternatif. Pemeriksaan sintaks tidak menggantikan uji transaksi, pergantian sesi, dan pengiriman per perangkat.

Dokumentasi protokol: [FCM error codes](https://firebase.google.com/docs/cloud-messaging/error-codes), [pengiriman HTTP v1](https://firebase.google.com/docs/cloud-messaging/send/v1-api).
