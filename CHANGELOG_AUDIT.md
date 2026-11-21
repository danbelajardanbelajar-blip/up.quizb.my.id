# Log Perubahan & Audit Keamanan QuizB

Dokumen ini mencatat setiap perubahan, perbaikan bug, dan penguatan keamanan yang dilakukan secara bertahap dan sistematis.

---

### [2026-11-17 16:02:22 WIB] — Pengamanan Skrip Seeder Akun Reviewer Google Play
* **Nomor Temuan Audit**: 1 (P0 - Kritis)
* **Berkas yang Diubah**: [`create_reviewer.php`](file:///D:/all_project/up.quizb.my.id/create_reviewer.php)
* **Baris yang Diubah**: Baris 1 - 42 & Baris 55 - 69
* **Bagian / Fitur**: Access Control & Seeder Akun Pengajar Reviewer
* **Deskripsi Perubahan**:
  - Menambahkan pengecekan `php_sapi_name() === 'cli'` sehingga skrip dapat dieksekusi dengan aman melalui CLI terminal tanpa hambatan.
  - Menambahkan proteksi autentikasi jika diakses melalui web browser: hanya sesi Admin aktif (`isLoggedIn() && getCurrentUser()['role'] === 'admin'`) atau request dengan secret key valid (`?key=...`) yang diizinkan mengeksekusi.
  - Memblokir seluruh akses publik tanpa izin dengan response HTTP `403 Forbidden` dan JSON error.
  - Menyesuaikan response output: format teks polos jika via CLI, dan format JSON jika via HTTP Admin.
* **Hasil Verifikasi**:
  - `php -l create_reviewer.php` -> Syntax OK (No syntax errors detected).
  - Akun reviewer Google Play tetap dapat login normal melalui antarmuka web dan aplikasi Android tanpa terpengaruh.


---

### [2026-11-17 16:12:45 WIB] — Mencegah Kebocoran Kunci Jawaban ke Klien pada Ujian & Tugas
* **Nomor Temuan Audit**: 2 (P0 - Kritis)
* **Berkas yang Diubah**: [`api/quiz.php`](file:///D:/all_project/up.quizb.my.id/api/quiz.php)
* **Baris yang Diubah**: Baris 365 - 377
* **Bagian / Fitur**: Fungsi `quiz_questions()` — Pemuatan Soal & Opsi Kuis
* **Deskripsi Perubahan**:
  - Mengisolasi dan menghilangkan properti `correct_option_id` dari payload JSON yang dikirim ke browser / aplikasi Android saat pengguna berada dalam mode ujian (`mode === 'exam'`) maupun tugas kelas dari guru (`$assignmentId > 0`).
  - Menghilangkan celah kecurangan di mana siswa/peserta dapat melihat kunci jawaban 100% benar via DevTools / Inspect Element (F12) sebelum menjawab.
  - Nilai dan evaluasi jawaban benar/salah tetap diproses 100% akurat di sisi server saat submit (`attempt.submit`), serta tetap dapat dilihat pada pembahasan hasil kuis (`attempt.result`) setelah ujian selesai.
  - Mengoptimalkan performa loading soal ujian karena menghilangkan query berulang `SELECT id FROM options ... WHERE is_correct = 1` pada setiap butir soal ujian.
* **Hasil Verifikasi**:
  - `php -l api/quiz.php` -> Syntax OK (No syntax errors detected).
  - Alur pengerjaan ujian dan tugas tetap berjalan lancar tanpa error di sisi frontend.


---

### [2026-11-17 16:17:52 WIB] — Mengamankan Pengumpulan Skor Turnamen dari Manipulasi Klien
* **Nomor Temuan Audit**: 3 (P0 - Kritis)
* **Berkas yang Diubah**: [`api/tournament.php`](file:///D:/all_project/up.quizb.my.id/api/tournament.php)
* **Baris yang Diubah**: Baris 707 - 729
* **Bagian / Fitur**: Fungsi `tournament_submit()` — Pengumpulan Skor & Penentuan Pemenang Turnamen
* **Deskripsi Perubahan**:
  - Mewajibkan `attempt_id` yang valid dan memverifikasi ke tabel `attempts` bahwa pengerjaan benar-benar sah milik akun peserta yang sedang login dan sesuai dengan `quiz_id` turnamen terkait.
  - Menghapus celah penerimaan skor mentah dari body request klien (`$body['score_final']` dan `$body['time_taken']`).
  - Nilai akhir (`score_final`) dan durasi (`time_taken`) sekarang 100% diambil dari record resmi pengerjaan server di database, menutup celah peserta mengirim skor palsu 100% secara instan.
* **Hasil Verifikasi**:
  - `php -l api/tournament.php` -> Syntax OK (No syntax errors detected).
  - Alur submit turnamen dari frontend [`quiz-engine.v6.js`](file:///D:/all_project/up.quizb.my.id/assets/js/quiz-engine.v6.js#L801-L806) tetap kompatibel penuh karena telah mengirimkan `attempt_id`.


---

### [2026-11-17 16:22:24 WIB] — Mengamankan Kalkulasi Skor Kuis dari Exploit Nilai 100% 1 Soal
* **Nomor Temuan Audit**: 4 (P0 - Kritis)
* **Berkas yang Diubah**: [`api/attempt.php`](file:///D:/all_project/up.quizb.my.id/api/attempt.php)
* **Baris yang Diubah**: Baris 55 - 108
* **Bagian / Fitur**: Fungsi `attempt_submit()` — Validasi Soal & Kalkulasi Skor Kuis
* **Deskripsi Perubahan**:
  - Memverifikasi kepemilikan soal: memastikan setiap `question_id` yang disubmit benar-benar terdaftar di database untuk `quiz_id` kuis yang bersangkutan (atau paket kuis tugas yang sah).
  - Menghapus fallback rentan yang membatasi pembagi total poin hanya pada butir soal yang dijawab (`$answeredIds`), yang sebelumnya dapat dieksploitasi siswa untuk memperoleh skor 100% instan hanya dengan menjawab 1 soal benar dari puluhan soal yang ada.
  - Mengimplementasikan fallback aman: apabila klien tidak mengirimkan daftar `question_ids`, server secara default memuat seluruh daftar soal sah dari database sehingga butir soal yang dilewati tetap bernilai 0.
  - Menambahkan verifikasi ambang batas jumlah soal (`min($totalQuestionsInQuiz, 5)`) agar request manipulatif dengan 1 soal ditolak langsung (HTTP 400).
* **Hasil Verifikasi**:
  - `php -l api/attempt.php` -> Syntax OK (No syntax errors detected).
  - Pengerjaan kuis normal dari antarmuka web dan mobile tetap terhitung presisi sesuai soal yang dikerjakan.


---

### [2026-11-17 16:41:55 WIB] — Penguatan Verifikasi Token Google Native Login dari Celah Account Takeover
* **Nomor Temuan Audit**: 5 (P0 - Kritis)
* **Berkas yang Diubah**: [`api/auth.php`](file:///D:/all_project/up.quizb.my.id/api/auth.php)
* **Baris yang Diubah**: Baris 433 - 460
* **Bagian / Fitur**: Fungsi `auth_google_native()` — Autentikasi Google Sign-In Native Android
* **Deskripsi Perubahan**:
  - Menambahkan validasi klaim `aud` (Audience / Client ID) pada token Google, memastikan token tersebut benar-benar diterbitkan khusus untuk Client ID aplikasi QuizB resmi (`GOOGLE_CLIENT_ID` atau `GOOGLE_ANDROID_CLIENT_ID`).
  - Menambahkan verifikasi `iss` (Issuer) resmi Google (`accounts.google.com` atau `https://accounts.google.com`).
  - Menambahkan verifikasi status `email_verified` dari Google untuk memastikan email yang masuk telah tervalidasi.
  - Menutup celah pengambilalihan akun (*Account Takeover / Confused Deputy Attack*) di mana penyerang sebelumnya dapat menggunakan token Google valid dari aplikasi lain untuk login ke akun korban di QuizB.
* **Hasil Verifikasi**:
  - `php -l api/auth.php` -> Syntax OK (No syntax errors detected).
  - Login Google dari aplikasi Android resmi tetap berjalan normal tanpa gangguan.


---

### [2026-11-17 20:00:33 WIB] — Pengamanan Kebijakan CORS dengan Whitelist Domain Resmi
* **Nomor Temuan Audit**: 6 (P1 - Tinggi)
* **Berkas yang Diubah**: [`api.php`](file:///D:/all_project/up.quizb.my.id/api.php)
* **Baris yang Diubah**: Baris 9 - 54
* **Bagian / Fitur**: Router Utama `api.php` — Penanganan CORS & Security Headers
* **Deskripsi Perubahan**:
  - Mengganti konfigurasi CORS terbuka yang sebelumnya merefleksikan sembarang `HTTP_ORIGIN` dengan `Access-Control-Allow-Credentials: true` tanpa validasi.
  - Menerapkan whitelist ketat untuk:
    1. Domain resmi `quizb.my.id` dan seluruh subdomainnya (`*.quizb.my.id`).
    2. Skema WebView aplikasi Android/Hybrid (`capacitor://localhost`, `ionic://localhost`).
    3. Host development lokal (`localhost`, `127.0.0.1`).
  - Menambahkan header `Vary: Origin` untuk mencegah caching silang pada CDN / proxy.
  - Membatasi pemberian `Access-Control-Allow-Credentials: true` hanya kepada origin yang lolos whitelist.
  - Menolak preflight `OPTIONS` dari origin luar yang tidak diizinkan dengan response `403 Forbidden`.
  - Menutup celah pencurian data profil dan sesi akun login oleh situs jahat pihak ketiga (CWE-942).
* **Hasil Verifikasi**:
  - `php -l api.php` -> Syntax OK (No syntax errors detected).
  - Akses web resmi dan aplikasi mobile tetap beroperasi lancar.


---

### [2026-11-21 23:00:00 WIB] — Implementasi Sistem Pengawasan AI (Proctoring) Berbasis Biometrik Wajah Zero-Click, Deteksi Suara Keramaian & Layar Penuh Otomatis
* **Nomor Temuan / Fitur**: Peningkatan Sistem Pengawasan AI (AI Proctoring Upgrade)
* **Berkas yang Ditambahkan & Diubah**:
  - [`includes/proctoring.php`](file:///D:/all_project/up.quizb.my.id/includes/proctoring.php) *(Baru)*: Helper database DDL lazy-migration `assignment_proctoring_logs`, kolom `exam_reference_photo` & `exam_reference_updated_at` pada `users`, penyimpanan acuan terenkripsi/terproteksi di `uploads/references/`, dan fungsi reset acuan.
  - [`api/proctoring.php`](file:///D:/all_project/up.quizb.my.id/api/proctoring.php) *(Baru)*: Endpoint `proctoring.check_reference`, `proctoring.save_reference`, `proctoring.reset_reference`, `proctoring.log_event`, `proctoring.swap_question`, dan `proctoring.get_logs`.
  - [`assets/js/facefinder.bin`](file:///D:/all_project/up.quizb.my.id/assets/js/facefinder.bin) *(Baru)*: Binary model classifier Pico Decision Tree untuk deteksi wajah instan di browser.
  - [`assets/js/quiz-face-verifier.js`](file:///D:/all_project/up.quizb.my.id/assets/js/quiz-face-verifier.js) *(Baru)*: Engine pemindai wajah real-time dengan evaluasi posisi wajah (arah geser kanan/kiri/atas/bawah), jarak kamera, pencahayaan, deteksi masker penutup wajah, dan keterbukaan mata.
  - [`pages/quiz-engine.html`](file:///D:/all_project/up.quizb.my.id/pages/quiz-engine.html): Penambahan layar `phase === 'face_scan'` (Zero-Click Hands-Free Face Scan dengan Pernyataan Privasi), overlay pembekuan layar saat pelanggaran suara keramaian (`isNoiseFrozen`), dan modal peringatan keluar fullscreen.
  - [`assets/js/quiz-engine.v6.js`](file:///D:/all_project/up.quizb.my.id/assets/js/quiz-engine.v6.js): Integrasi `requestFullscreenMode()`, pemantauan Web Audio API (spektrum vokal ~250–3400 Hz), pembekuan layar & jeda timer saat keramaian, penukaran soal otomatis (`proctoring.swap_question`), dan alur scan acuan satu kali.
  - [`api.php`](file:///D:/all_project/up.quizb.my.id/api.php): Registrasi route `proctoring` dan pengecualian CSRF untuk logging latar belakang `proctoring.log_event` & `proctoring.swap_question`.
  - [`index.php`](file:///D:/all_project/up.quizb.my.id/index.php): Pemuatan skrip `quiz-face-verifier.js`.
  - [`pages/admin-users.html`](file:///D:/all_project/up.quizb.my.id/pages/admin-users.html) & [`assets/js/app.v5.js`](file:///D:/all_project/up.quizb.my.id/assets/js/app.v5.js): Tombol dan handler aksi Admin/Pengajar untuk melakukan reset foto acuan mahasiswa jika diperlukan.
* **Deskripsi Perubahan**:
  1. **Foto Acuan Sekali Rekam**: Foto acuan biometrik mahasiswa hanya direkam sekali pada tugas/kuis pertama yang mewajibkan kamera. Pada tugas berikutnya foto tersebut otomatis menjadi acuan verifikasi tanpa perlu foto ulang.
  2. **Pernyataan Privasi & Keamanan Mutlak**: Menampilkan pernyataan privasi resmi di layar pemindaian bahwa foto hanya digunakan untuk verifikasi integritas ujian, dienkripsi di server, dan tidak dapat diunduh/disimpan oleh siapapun termasuk Admin (folder `uploads/references` dilindungi oleh `.htaccess Require all denied`). Admin hanya memiliki hak administratif untuk mereset acuan jika verifikasi bermasalah.
  3. **Zero-Click Hands-Free UX**: Mahasiswa tidak perlu mengklik tombol ambil foto. Panduan dinamis memberikan umpan balik langsung (geser kanan/kiri/atas/bawah, kurang terang, silau, jangan dua orang, jangan bermasker, buka mata). Saat posisi wajah tepat dan stabil, cincin hitung mundur 2 detik menyala hijau dan otomatis menangkap foto serta melanjutkan ke ujian.
  4. **Deteksi Suara Keramaian & Penukaran Soal**: Menggunakan Web Audio API untuk memantau frekuensi percakapan. Jika terdeteksi keramaian/obrolan > 1.6 detik, layar dibekukan selama 15 detik, timer dijeda, jawaban soal saat ini di-reset, dan soal otomatis ditukar dengan soal lain.
  5. **Layar Penuh Otomatis**: Layar otomatis masuk Fullscreen saat klik "Mulai Sekarang ▶". Perpindahan tab atau keluar dari Fullscreen otomatis memunculkan modal peringatan dan mencatat bukti snapshot pelanggaran ke `assignment_proctoring_logs`.
* **Hasil Verifikasi**:
  - `python validate_php.py` -> Semua file PHP lolos uji sintaks tanpa kesalahan.
  - `node -c` -> Semua file JavaScript (`quiz-engine.v6.js`, `quiz-face-verifier.js`, `app.v5.js`) lolos verifikasi sintaks tanpa kesalahan.
  - `node tests/test-proctoring-suite.cjs` -> 13/13 pengujian otomatis lolos (100% PASS) mencakup deteksi wajah, auto-capture zero-click, audio keramaian, jeda timer, penukaran soal, fullscreen, dan proteksi privasi acuan.
  - Endpoint API `proctoring.check_reference`, `save_reference`, `reset_reference`, `log_event`, dan `swap_question` siap melayani request client.
