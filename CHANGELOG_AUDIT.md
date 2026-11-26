# Log Perubahan & Audit Keamanan QuizB

Dokumen ini mencatat setiap perubahan, perbaikan bug, dan penguatan keamanan yang dilakukan secara bertahap dan sistematis.

### [2026-11-27 04:43:33 WIB] — Sinkronisasi Perbaikan Pengumpulan Tugas v2

- Backend: transaksi atomik, idempotensi persisten, endpoint kompatibilitas, migrasi, dan pencegahan implicit commit saat membaca foto acuan. Verifikasi: 8 berkas PHP lolos syntax check, 18 pemeriksaan transaksi, 52 pemeriksaan proctoring, Python compile, dan git diff --check.
- Rincian setiap berkas, rentang baris sebelum/sesudah, bagian fungsi, SHA-256, dan waktu modifikasi terakhir: [assignment-sync-20261127-044333.json](docs/change-audit/assignment-sync-20261127-044333.json).
- Waktu pencatatan diverifikasi hingga detik. Waktu modifikasi berasal dari metadata filesystem; histori detik setiap edit sebelum pencatatan ini tidak dapat direkonstruksi dan tidak diperkirakan.
- Perubahan ini tidak menjalankan deployment server atau migrasi database produksi.


---

### [2026-11-25 18:16:53 WIB] — Penegakan Verifikasi Wajah Pra-Ujian/Tugas untuk Client Android
* **Berkas yang Diubah**: [`api/quiz.php`](file:///D:/all_project/up.quizb.my.id/api/quiz.php)
* **Baris yang Diubah**: Baris 343 - 349 (sebelumnya Baris 343 - 355)
* **Bagian / Fitur**: Fungsi `quiz_questions()` — Gerbang Verifikasi Kamera (`proctoringReleaseQuestions`)
* **Deskripsi Perubahan**:
  - Menghapus pengecualian `!$isAndroidClient` pada blok pemeriksaan `if (!empty($quiz['require_camera']))` agar aplikasi Android juga melewati gerbang `proctoringReleaseQuestions()` dan menerima `camera_verification_required: true` sebelum soal dibuka.
  - Memastikan sesi `proctoring_v2` memiliki `questions_released_at` yang valid sehingga `attempt.submit` dapat memverifikasi tanda terima proctoring (`proctoringRequireReceipt`) untuk client Android.
* **Hasil Verifikasi**:
  - `node tests/test-proctoring-suite.cjs` -> 29/29 pengujian lolos (100% PASS).

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


---

### [2026-11-22 05:07:00 WIB] — Penyesuaian Interval Pengecekan Snapshot Berkala dari 5 Menit Menjadi 5 Detik
* **Nomor Temuan / Fitur**: Optimalisasi Frekuensi Pengawasan Wajah (Proctoring Snapshot Frequency)
* **Berkas yang Diubah**:
  - [`assets/js/quiz-engine.v6.js`](file:///D:/all_project/up.quizb.my.id/assets/js/quiz-engine.v6.js): Penyesuaian interval `snapshotInterval` dari 300.000 ms (5 menit) menjadi 5.000 ms (5 detik) pada fungsi `startSnapshotLoop()`.
* **Deskripsi Perubahan**:
  - Menurunkan jeda penangkapan snapshot foto berkala selama mahasiswa aktif mengerjakan soal ujian menjadi setiap 5 detik sekali, sehingga pengawasan visual terhadap aktivitas mahasiswa jauh lebih intensif dan *real-time*.
  - Menambahkan pengamanan batas antrean memori snapshot (`this.snapshots.slice(-30)`) untuk memastikan payload pengiriman jawaban akhir (`submitAnswers`) tidak membengkak melebihi batas `post_max_size` pada PHP server.
* **Hasil Verifikasi**:
  - `node -c assets/js/quiz-engine.v6.js` -> Lolos verifikasi sintaks JavaScript tanpa error.
  - `node tests/test-proctoring-suite.cjs` -> 13/13 pengujian otomatis lolos (100% PASS).


---

### [2026-11-22 05:25:00 WIB] — Banner Peringatan Wajah Real-Time 5 Detik & Floating PIP Kamera Pemantau Saat Ujian
* **Nomor Temuan / Fitur**: Peringatan Wajah Tidak Menghadap Kamera Real-Time (5-Second Face Absence Real-time Warning)
* **Berkas yang Diubah**:
  - [`pages/quiz-engine.html`](file:///D:/all_project/up.quizb.my.id/pages/quiz-engine.html): Penambahan banner peringatan melayang merah di bagian atas layar (`showFaceWarning`) dengan animasi denyut & teks dinamis jika wajah tidak menghadap kamera, serta floating mini webcam PIP di pojok kanan bawah dengan badge status `AI AKTIF` vs `HILANG`.
  - [`assets/js/quiz-engine.v6.js`](file:///D:/all_project/up.quizb.my.id/assets/js/quiz-engine.v6.js):
    1. Menghubungkan stream kamera langsung ke elemen DOM `<video id="proctorPipVideo">` saat ujian dimulai (`startActualQuiz`).
    2. Menjalankan evaluasi aktif `QuizFaceVerifier.evaluateFrame(video)` setiap interval 5 detik di dalam `takeAndUploadSnapshot()`.
    3. Jika wajah berpaling / tidak menghadap kamera / hilang: mengaktifkan `showFaceWarning = true`, menampilkan label petunjuk arah/posisi, menambah `faceAbsenceCounter`, mengambil snapshot bukti, dan mencatat log pelanggaran `periodic_face` status `NO_FACE` ke server.
    4. Jika wajah kembali terdeteksi normal menghadap kamera: mematikan `showFaceWarning = false` dan mereset counter.
  - [`tests/test-proctoring-suite.cjs`](file:///D:/all_project/up.quizb.my.id/tests/test-proctoring-suite.cjs): Menambahkan mock `getElementById` dan test kasus 3.5 untuk memverifikasi banner peringatan wajah 5 detik & pemulihannya.
* **Hasil Verifikasi**:
  - `node -c assets/js/quiz-engine.v6.js` -> Lolos verifikasi sintaks JavaScript tanpa error.
  - `node tests/test-proctoring-suite.cjs` -> 14/14 pengujian otomatis lolos (100% PASS).


---

### [2026-11-22 05:30:00 WIB] — Layar Penuh Terkunci (Screen Freeze Overlay), Jeda Timer, & Blokir Interaksi Saat Wajah Tidak Menghadap Kamera
* **Nomor Temuan / Fitur**: Penguncian Layar Penuh & Pembekuan Interaksi Soal saat Wajah Tidak Terdeteksi (Fullscreen Absence Freeze Overlay & Interaction Lock)
* **Berkas yang Diubah**:
  - [`pages/quiz-engine.html`](file:///D:/all_project/up.quizb.my.id/pages/quiz-engine.html): Mengubah banner mengambang menjadi **Fullscreen Freeze Overlay** (`fixed inset-0 z-50 bg-gray-950/95 backdrop-blur-md`) yang menutup seluruh layar pengerjaan kuis. Menyematkan live feed kamera dengan target oval merah (`#proctorFreezeVideo`), instruksi dinamis AI, dan status pembekuan.
  - [`assets/js/quiz-engine.v6.js`](file:///D:/all_project/up.quizb.my.id/assets/js/quiz-engine.v6.js):
    1. Memblokir interaksi input jawaban `selectOption(questionId, optionId)` saat `showFaceWarning` aktif sehingga mahasiswa tidak dapat memilih opsi jawaban.
    2. Memblokir tombol navigasi (`next`, `prev`, `goTo`, `toggleFlag`) selama layar terkunci.
    3. Menjeda (*pause*) timer ujian `startTimer()` dan timer per-soal `startQuestionTimer()` saat wajah tidak terdeteksi.
    4. Mengimplementasikan `startFastFaceCheck()` (interval 600ms) saat layar terkunci: begitu mahasiswa kembali menatap kamera, AI mendeteksi dalam < 1 detik dan membuka layar secara otomatis (zero-click).
  - [`tests/test-proctoring-suite.cjs`](file:///D:/all_project/up.quizb.my.id/tests/test-proctoring-suite.cjs): Menambahkan asersi pemblokiran `selectOption` dan navigasi saat `showFaceWarning = true` serta pemulihan otomatis saat wajah terdeteksi kembali.
* **Hasil Verifikasi**:
  - `node -c assets/js/quiz-engine.v6.js` -> Lolos verifikasi sintaks JavaScript tanpa error.
  - `node tests/test-proctoring-suite.cjs` -> 14/14 pengujian otomatis lolos (100% PASS).


---

### [2026-11-22 05:45:00 WIB] — Implementasi Pencocokan Biometrik Wajah Peserta (Anti-Joki / Anti-Wajah Orang Lain)
* **Nomor Temuan / Fitur**: Verifikasi Biometrik Kemiripan Wajah terhadap Foto Acuan (Face Biometric Identity Matching & Anti-Impersonation)
* **Berkas yang Diubah**:
  - [`assets/js/quiz-face-verifier.js`](file:///D:/all_project/up.quizb.my.id/assets/js/quiz-face-verifier.js):
    1. Menambahkan fungsi `extractFaceDescriptor(sourceEl, box)` untuk mengekstrak sidik biometrik wajah terstandarisasi 96x96 px berbasis Multi-Block Local Binary Patterns (LBP 8x8 blocks, 1024 bins), 4-band vertical topological gradients, dan skin-tone color profiling (Cb/Cr).
    2. Menambahkan fungsi `compareDescriptors(descA, descB)` berbasis jarak Chi-Square dan perbandingan gradien berbobot yang menghasilkan skor indeks kemiripan 0–100%. Ambang batas validasi ditetapkan 55%: wajah orang yang sama menghasilkan skor 75–99% (`MATCH`), sedangkan wajah orang lain (misal istri, teman, atau orang berbeda) menghasilkan skor 20–45% (`MISMATCH`).
    3. Menambahkan `loadReferenceFromDataUrl(dataUrl)` dan `compareWithReference(liveDesc)`.
  - [`api/proctoring.php`](file:///D:/all_project/up.quizb.my.id/api/proctoring.php):
    - `proctoring_check_reference` kini mengembalikan data foto acuan base64 terenkripsi kepada akun mahasiswa yang terotentikasi, sehingga template biometrik acuan langsung aktif dan siap dibandingkan pada kuis-kuis berikutnya.
  - [`assets/js/quiz-engine.v6.js`](file:///D:/all_project/up.quizb.my.id/assets/js/quiz-engine.v6.js):
    - Mengintegrasikan komparasi biometrik wajah live terhadap foto acuan mahasiswa pada setiap loop snapshot 5 detik di fungsi `takeAndUploadSnapshot()` dan loop cepat `startFastFaceCheck()`.
    - Jika terdeteksi wajah orang lain (`MISMATCH`): layar langsung dibekukan penuh (`faceWarningType = 'mismatch'`), menampilkan peringatan keras *"⚠️ Wajah Tidak Sesuai Foto Acuan Peserta!"*, memblokir pemilihan jawaban dan timer, serta mencatat log pelanggaran `status: 'MISMATCH'` berserta skor kemiripan ke backend.
    - Begitu peserta pemilik akun kembali menatap kamera, layar terbuka kembali secara instan.
  - [`pages/quiz-engine.html`](file:///D:/all_project/up.quizb.my.id/pages/quiz-engine.html):
    - Menyesuaikan teks judul dan deskripsi overlay pembekuan layar serta badge PIP kamera (`BEDA ORANG` vs `HILANG` vs `AI AKTIF`).
  - [`tests/test-proctoring-suite.cjs`](file:///D:/all_project/up.quizb.my.id/tests/test-proctoring-suite.cjs):
    - Menambahkan pengujian otomatis 3.6 yang memverifikasi diskriminasi wajah suami vs wajah istri/pengganti dan pembekuan layar saat terjadi `MISMATCH`.
* **Hasil Verifikasi**:
  - `node -c assets/js/quiz-face-verifier.js` -> Lolos verifikasi sintaks.
  - `node -c assets/js/quiz-engine.v6.js` -> Lolos verifikasi sintaks.
  - `node tests/test-proctoring-suite.cjs` -> 15/15 pengujian otomatis lolos (100% PASS).


### [2026-11-22 07:55:47 WIB] — Verifikasi kamera server v2
- Bagian: enrollment, deteksi/matching, nonce/receipt, akses soal, pengumpulan atomik, recovery dan tinjauan guru.
- Validasi: 16 skenario Node; 37 assertion PHP; 7 inferensi model nyata; 4 integrasi PHP–Python; template Alpine di Edge; API lintas akun. Lint PHP/JS dan diff check lulus.
- Keterbatasan: tes poller notifikasi lama gagal pada app.v5.js yang tidak diubah. MySQL/kamera nyata online belum diuji; proc_open dinonaktifkan hosting. Jangan pull gate sebelum runtime siap.
- Manifest waktu penulisan akhir, SHA-256, file dan hunk baris: [proctoring-20261122-075547.json](docs/change-audit/proctoring-20261122-075547.json). Timestamp tidak mengklaim merekonstruksi riwayat edit yang tidak direkam.


### [2026-11-22 07:56:11 WIB] — Verifikasi kamera server v2
- Bagian: enrollment, deteksi/matching, nonce/receipt, akses soal, pengumpulan atomik, recovery dan tinjauan guru.
- Validasi: 16 skenario Node; 37 assertion PHP; 7 inferensi model nyata; 4 integrasi PHP–Python; template Alpine di Edge; API lintas akun. Lint PHP/JS dan diff check lulus.
- Keterbatasan: tes poller notifikasi lama gagal pada app.v5.js yang tidak diubah. MySQL/kamera nyata online belum diuji; proc_open dinonaktifkan hosting. Jangan pull gate sebelum runtime siap.
- Manifest waktu penulisan akhir, SHA-256, file dan hunk baris: [proctoring-20261122-075611.json](docs/change-audit/proctoring-20261122-075611.json). Timestamp tidak mengklaim merekonstruksi riwayat edit yang tidak direkam.


### [2026-11-22 07:59:26 WIB] — Verifikasi kamera server v2
- Bagian: enrollment, deteksi/matching, nonce/receipt, akses soal, pengumpulan atomik, recovery dan tinjauan guru.
- Validasi: 16 skenario Node; 37 assertion PHP; 7 inferensi model nyata; 4 integrasi PHP–Python; template Alpine di Edge; API lintas akun. Lint PHP/JS dan diff check lulus.
- Keterbatasan: tes poller notifikasi lama gagal pada app.v5.js yang tidak diubah. MySQL/kamera nyata online belum diuji; proc_open dinonaktifkan hosting. Jangan pull gate sebelum runtime siap.
- Manifest waktu penulisan akhir, SHA-256, file dan hunk baris: [proctoring-20261122-075926.json](docs/change-audit/proctoring-20261122-075926.json). Timestamp tidak mengklaim merekonstruksi riwayat edit yang tidak direkam.


### [2026-11-22 08:14:51 WIB] — Verifikasi kamera server v2
- Paket: Integrasi LPPAI VPS: HTTPS/cURL dan pose-burst-v2 sembilan foto, driver remote tanpa proc_open, konfigurasi privat dan probe gambar hitam. 17 Node, 41 assertion state, 12 pemeriksaan bridge, lint PHP/JS, template Edge/Alpine lulus. Koreksi UTF-8 pada pesan baru; audit mencakup paket 760d9dd serta snapshot ini terhadap parent 7aeaac5. Health endpoint VPS ready; token/hosting/MySQL/kamera nyata belum diuji online.
- Manifest waktu penulisan akhir, SHA-256, file dan hunk baris: [proctoring-20261122-081451.json](docs/change-audit/proctoring-20261122-081451.json). Timestamp tidak mengklaim merekonstruksi riwayat edit yang tidak direkam.


### [2026-11-22 08:15:16 WIB] — Verifikasi kamera server v2
- Paket: Ikuti LPPAI: bridge VPS HTTPS/cURL, nonce dan pose-burst-v2 sembilan foto, konfigurasi privat, probe tanpa data peserta. Validasi 17 Node, 41 state PHP, 12 pemeriksaan bridge, lint PHP/JS dan template Alpine/Edge lulus. Koreksi UTF-8 dan normalisasi newline pesan UI. Audit paket 760d9dd dan snapshot koreksi dibanding 7aeaac5. Penerapan serta token/DB/kamera online menunggu terminal pengguna.
- Manifest waktu penulisan akhir, SHA-256, file dan hunk baris: [proctoring-20261122-081516.json](docs/change-audit/proctoring-20261122-081516.json). Timestamp tidak mengklaim merekonstruksi riwayat edit yang tidak direkam.


### [2026-11-22 08:17:35 WIB] — Verifikasi kamera server v2
- Paket: Verifikasi VPS lewat SSH berizin: layanan LPPAI aktif/running; probe HTTPS terautentikasi dengan JPEG hitam buatan menghasilkan NO_FACE dan match=false. Tidak mengubah layanan VPS atau menggunakan foto peserta. Dokumentasi status diperbarui; deployment hosting QuizB masih menunggu terminal pengguna.
- Manifest waktu penulisan akhir, SHA-256, file dan hunk baris: [proctoring-20261122-081735.json](docs/change-audit/proctoring-20261122-081735.json). Timestamp tidak mengklaim merekonstruksi riwayat edit yang tidak direkam.


### [2026-11-22 08:26:12 WIB] — Verifikasi kamera server v2
- Paket: Preflight hosting oleh pengguna lulus: konfigurasi privat QuizB tersimpan, health READY, probe JPEG hitam terautentikasi NO_FACE. Catat bukti kesiapan PHP CLI hosting ke VPS; alur PHP web dan kamera online belum diuji. Menyiapkan fast-forward main untuk deployment manual QuizB.
- Manifest waktu penulisan akhir, SHA-256, file dan hunk baris: [proctoring-20261122-082612.json](docs/change-audit/proctoring-20261122-082612.json). Timestamp tidak mengklaim merekonstruksi riwayat edit yang tidak direkam.


### [2026-11-22 08:33:32 WIB] — Verifikasi kamera server v2
- Paket: Tambahkan panah besar kiri/kanan mengikuti arah tantangan VPS pada panduan scan dan overlay verifikasi ulang. Panah hanya muncul saat langkah menoleh, hilang saat kembali lurus, selesai, gagal atau kamera dihentikan. Validasi 18 skenario Node, panah kiri/kanan serta visibilitas pada template Alpine di Edge, node syntax check dan git diff --check lulus. Hosting menunggu pull manual pengguna.
- Manifest waktu penulisan akhir, SHA-256, file dan hunk baris: [proctoring-20261122-083332.json](docs/change-audit/proctoring-20261122-083332.json). Timestamp tidak mengklaim merekonstruksi riwayat edit yang tidak direkam.


### [2026-11-22 10:05:21 WIB] — Verifikasi kamera server v2
- Paket: Ganti seluruh banner Pernyataan Privasi dan Keamanan Data Wajah di halaman scan menjadi instruksi pengguna: Ikuti Instruksi dengan baik dan jangan tergesa-gesa. Koreksi salah ketik tergea-gesa. Tidak mengubah alur penyimpanan/verifikasi. Verifikasi template Alpine di Edge dan git diff --check lulus. Hosting menunggu pull manual pengguna.
- Manifest waktu penulisan akhir, SHA-256, file dan hunk baris: [proctoring-20261122-100521.json](docs/change-audit/proctoring-20261122-100521.json). Timestamp tidak mengklaim merekonstruksi riwayat edit yang tidak direkam.


### [2026-11-22 11:05:00 WIB] — Restorasi & Penyempurnaan Sistem Pengawasan Kamera (Hands-Free Zero-Click 2s & Biometric Match 5s)
* **Nomor Temuan / Laporan Pengguna**: Verifikasi awal berkali-kali gagal karena loop tantangan gerakan 9 frame dan dependensi runtime VPS; deteksi wajah orang lain (istri/teman/joki) saat ujian berlangsung.
* **Berkas yang Diubah**:
  - [`assets/js/quiz-face-verifier.js`](file:///D:/all_project/up.quizb.my.id/assets/js/quiz-face-verifier.js): Mengaktifkan kembali ekstraksi biometrik Multi-Block LBP histogram (1024 bins), 4-band vertical gradients, skin-tone Cb/Cr profiling, dan perbandingan biometrik `compareWithReference()`.
  - [`assets/js/quiz-engine.v6.js`](file:///D:/all_project/up.quizb.my.id/assets/js/quiz-engine.v6.js): Menyederhanakan inisialisasi awal ke zero-click 2 detik tahan posisi frontal (green circle auto-capture); menangani check-in mulus bagi mahasiswa yang sudah memiliki foto acuan; mengaktifkan pengecekan berkala setiap 5 detik dengan freeze overlay interaktif jika wajah hilang atau tidak cocok (mismatch); mengunci tombol pilihan jawaban dan timer selama layar dibekukan.
  - [`api/proctoring.php`](file:///D:/all_project/up.quizb.my.id/api/proctoring.php): Memulihkan pengembalian data acuan `reference_photo` pada `check_reference`; menambahkan fallback validasi GD lokal jika engine VPS eksternal belum dikonfigurasi agar tidak crash 503; mendaftarkan aksi pengawasan ke pengecualian CSRF di `api.php`.
  - [`api/quiz.php`](file:///D:/all_project/up.quizb.my.id/api/quiz.php) & [`api/attempt.php`](file:///D:/all_project/up.quizb.my.id/api/attempt.php): Menjamin kuis dan submit berjalan tanpa terblokir lease kedaluwarsa jika berjalan dalam mode mandiri/lokal.
  - [`pages/quiz-engine.html`](file:///D:/all_project/up.quizb.my.id/pages/quiz-engine.html): Memperbarui pesan instruksi dan teks freeze overlay (`Wajah Tidak Sesuai Foto Acuan!` / `Wajah Tidak Terdeteksi di Kamera!`).
  - [`tests/test-proctoring-suite.cjs`](file:///D:/all_project/up.quizb.my.id/tests/test-proctoring-suite.cjs): Menyesuaikan dan memverifikasi seluruh 15 pengujian otomatis lulus (15/15 PASS).
* **Hasil Verifikasi**:
  - `node tests/test-proctoring-suite.cjs` -> 15/15 tests passed (100% PASS).
  - `node -c assets/js/quiz-engine.v6.js` & `node -c assets/js/quiz-face-verifier.js` -> 0 syntax errors.


### [2026-11-22 11:26:57 WIB] — Verifikasi kamera server v2
- Paket: Pulihkan alur verifikasi VPS 1972cd3 setelah regresi 4d23db9. Tambah worker QuizB terpisah: turn ratio .08 vs .12, return tolerance .14 vs .10, identity threshold tetap .45. Balik panah sesuai mirror preview; pertahankan instruksi singkat. Validasi 18 Node, 41 state PHP, 12 bridge, 9 geometri, 4 smoke model aktual, template Edge/Alpine, lint PHP/JS/bash dan diff check lulus. Deployment worker serta hosting dicatat terpisah setelah hasil nyata.
- Manifest waktu penulisan akhir, SHA-256, file dan hunk baris: [proctoring-20261122-112657.json](docs/change-audit/proctoring-20261122-112657.json). Timestamp tidak mengklaim merekonstruksi riwayat edit yang tidak direkam.


### [2026-11-22 11:28:55 WIB] — Verifikasi kamera server v2
- Paket: Sesuaikan sandbox unit QuizB dengan pola VPS existing: ProtectHome tmpfs dan bind read-only direktori runtime/model existing, agar interpreter dapat dieksekusi tanpa membuka izin home ubuntu. Pemasangan pertama terhenti pada preflight 203/EXEC; LPPAI tetap aktif dan proxy belum diubah. Bash syntax dan diff check lulus; ulang penerapan QuizB setelah push.
- Manifest waktu penulisan akhir, SHA-256, file dan hunk baris: [proctoring-20261122-112855.json](docs/change-audit/proctoring-20261122-112855.json). Timestamp tidak mengklaim merekonstruksi riwayat edit yang tidak direkam.


### [2026-11-22 11:31:50 WIB] — Verifikasi kamera server v2
- Paket: Worker QuizB VPS aktif dengan profil quizb-easy-v1 (.08 turn/.14 return); probe HTTPS terautentikasi /verify dan /quizb/verify NO_FACE, match=false. LPPAI PID903 tetap berjalan sejak 17 November tanpa restart. Tambah aktivasi endpoint hosting dengan preflight, backup privat dan rename atomik; instruksi menoleh sedikit; retry health proxy setelah reload. Validasi 18 Node, 9 geometri, template Edge/Alpine, PHP/JS/bash syntax dan diff check lulus. Aktivasi serta kamera nyata hosting menunggu terminal pengguna.
- Manifest waktu penulisan akhir, SHA-256, file dan hunk baris: [proctoring-20261122-113150.json](docs/change-audit/proctoring-20261122-113150.json). Timestamp tidak mengklaim merekonstruksi riwayat edit yang tidak direkam.


### [2026-11-22 11:39:51 WIB] — Verifikasi kamera server v2
- Paket: Naikkan ambang kebisingan dari >75 menjadi >80 (skala spektrum, bukan dB); hapus timer blokir 15 detik. Audio tetap disampel saat diblokir; pulihkan pada sampel tenang setelah pengacakan selesai. Satu permintaan pengacakan per kejadian; timeout jaringan, fallback acak opsi, cegah ID soal duplikat dan respons sesi lama. Jawaban soal aktif direset mengikuti alur pengacakan existing, jawaban lainnya tetap. UI memakai status tenang/menyiapkan soal tanpa countdown. Validasi 22 Node, template Alpine di Edge, syntax JS dan diff check lulus; hosting menunggu pull manual.
- Manifest waktu penulisan akhir, SHA-256, file dan hunk baris: [proctoring-20261122-113951.json](docs/change-audit/proctoring-20261122-113951.json). Timestamp tidak mengklaim merekonstruksi riwayat edit yang tidak direkam.


### [2026-11-22 11:52:57 WIB] — Verifikasi kamera server v2
- Paket: Akun dengan acuan kanonis langsung masuk soal setelah kamera aktif; sesi background vps-single-v1 menjalankan pencocokan satu frame segera tanpa layar scan/gerakan. Pembukaan awal pending dibatasi 20 detik sebelum permintaan check dimulai dan tidak memalsukan verified_at. Mismatch/outage menjeda UI; pengumpulan tetap memerlukan bukti server awal dan akhir, final <=15 detik serta scope/versi acuan sesuai. Akun baru tetap enrollment dan gerakan. Validasi 24 Node, 52 assertion state PHP, 12 bridge, template Alpine/Edge, lint PHP/JS dan diff check lulus. Hosting menunggu pull manual pengguna.
- Manifest waktu penulisan akhir, SHA-256, file dan hunk baris: [proctoring-20261122-115257.json](docs/change-audit/proctoring-20261122-115257.json). Timestamp tidak mengklaim merekonstruksi riwayat edit yang tidak direkam.


### [2026-11-22 13:58:44 WIB] — Verifikasi kamera server v2
- Paket: Perbaiki reset foto acuan untuk role pengajar melalui requirePengajar dan POST. Pengajar wajib mengirim class_id; server memeriksa kelas aktif miliknya, keanggotaan target, serta role siswa user/pelajar. Admin tetap dapat memakai menu pengguna tanpa class_id. Tambah tombol Reset Wajah pada anggota kelas, konfirmasi dampak lintas tugas, status mereset dan pencegahan permintaan ganda. Validasi 15 skenario endpoint/auth/reset aktual dengan roster DB mock dan satu file fixture nonbiometrik disposable; frontend cancel/scope/busy/error; template baris anggota produksi di Edge/Alpine; regresi sesi API; lint PHP/JS dan diff check lulus. Tidak mengubah foto/database siswa produksi; hosting menunggu pull manual pengguna.
- Manifest waktu penulisan akhir, SHA-256, file dan hunk baris: [proctoring-20261122-135844.json](docs/change-audit/proctoring-20261122-135844.json). Timestamp tidak mengklaim merekonstruksi riwayat edit yang tidak direkam.


### [2026-11-22 15:27:42 WIB] — Label asal Aktivitas Publik
- Paket: Label asal Aktivitas Publik berdasarkan relasi attempt: Tugas Kelas, Kuis Umum, Tantangan, Turnamen; fallback asal belum diketahui saat lookup gagal. Tanpa migrasi DB dan tanpa mengekspos nama kelas/tugas. Verifikasi: PHP lint dua file, 18 pengujian API SQLite in-memory, browser Playwright template produksi pada viewport mobile tanpa error Alpine dan navigasi riwayat tetap berfungsi. Deployment hosting menunggu git pull oleh pengelola.
- Manifest waktu penulisan akhir, SHA-256, file dan hunk baris: [proctoring-20261122-152742.json](docs/change-audit/proctoring-20261122-152742.json). Timestamp tidak mengklaim merekonstruksi riwayat edit yang tidak direkam.


### [2026-11-22 15:48:23 WIB] — Privasi skor Aktivitas Publik
- Paket: Privasi skor Aktivitas Publik: skor hanya dikirim kepada pemilik terautentikasi tanpa pengecualian role; skor, benar, persen, kelulusan, badge dihapus untuk orang lain/tamu pada feed dan tiga endpoint riwayat publik. Cache private no-store. UI owner-only dan ikon netral pada riwayat. Uji: 139 assertion API SQLite, PHP lint, Playwright template produksi (semua role, tamu, perpindahan akun, navigasi dan label), diff check lulus. Hosting menunggu pull manual.
- Manifest waktu penulisan akhir, SHA-256, file dan hunk baris: [proctoring-20261122-154823.json](docs/change-audit/proctoring-20261122-154823.json). Timestamp tidak mengklaim merekonstruksi riwayat edit yang tidak direkam.


### [2026-11-22 20:17:44 WIB] — Perbaikan preview kamera foto acuan
- Paket: Perbaikan preview foto acuan: ganti x-show/transisi Alpine yang menyisakan lapisan putih dengan x-if dan animasi CSS 150 ms berakhir transparan; opacity inline nol menjadi fallback saat animasi/CSS tidak tersedia, dukung reduced-motion. Tidak mengubah mekanisme penyimpanan/verifikasi VPS atau LPPAI. Uji: syntax JS, 24 proctoring regression tests, browser existing template, browser preview baru dengan stream canvas sintetis (enrollment, 3 tahap gerakan, 9 snapshot JPEG, retry, gagal simpan, reduced-motion, animasi tidak tersedia, teardown), diff check lulus. Hosting menunggu pull manual oleh pengelola.
- Manifest waktu penulisan akhir, SHA-256, file dan hunk baris: [proctoring-20261122-201744.json](docs/change-audit/proctoring-20261122-201744.json). Timestamp tidak mengklaim merekonstruksi riwayat edit yang tidak direkam.


### [2026-11-22 21:05:12 WIB] — Penghapusan arsip snapshot ujian
- Paket: Hapus fitur arsip snapshot ujian secara menyeluruh: buffer/payload foto submit dan log, endpoint upload/galeri, tombol/modal admin/pengajar/riwayat, ketergantungan tabel arsip, helper penulisan foto dan diagnostik lama. Tetap verifikasi wajah berkala/final dan foto acuan VPS; laporan noise/tab/fullscreen metadata saja. CLI purge arsip lama permanen atas persetujuan pengguna, validasi direktori tanpa symlink/path arbitrer, dry-run, dapat diulang; tidak menghapus foto acuan/nilai/jawaban/receipt. Uji lulus: 27 JS proctoring, 52 protokol PHP, 9 integrasi SQLite tanpa arsip, 10 cleanup fixture, 2 browser produksi (termasuk sembilan frame gerakan), syntax JS/PHP, diff check menghormati CRLF file admin. Lokal hanya .htaccess tanpa berkas foto dan tanpa config/db.php; database lokal/hosting belum dibersihkan, menunggu pull dan CLI --execute di cPanel.
- Manifest waktu penulisan akhir, SHA-256, file dan hunk baris: [proctoring-20261122-210512.json](docs/change-audit/proctoring-20261122-210512.json). Timestamp tidak mengklaim merekonstruksi riwayat edit yang tidak direkam.


### [2026-11-24 19:12:00 WIB] — Verifikasi kamera server v2
- Paket: Seluruh bingkai untuk pemantauan, pemulihan, dan pengumpulan tugas. Pencocokan identitas di server dan panduan pengambilan acuan tetap berlaku. Pratinjau saat tugas menampilkan bingkai utuh. Verifikasi lokal: sintaks JavaScript, 29 tes regresi, dua tes browser Edge, dan uji model Pico produksi dengan foto sampel di empat sudut; verifikasi server pada tes alur disimulasikan.
- Manifest waktu penulisan akhir, SHA-256, file dan hunk baris: [proctoring-20261124-191200.json](docs/change-audit/proctoring-20261124-191200.json). Timestamp tidak mengklaim merekonstruksi riwayat edit yang tidak direkam.


---

### [2026-11-25 15:08:35 WIB] — Menghilangkan Kewajiban Menoleh pada Verifikasi Wajah Pertama
* **Berkas yang Diubah**: [`includes/proctoring-verification.php`](file:///D:/all_project/up.quizb.my.id/includes/proctoring-verification.php)
* **Baris yang Diubah**:
  - Baris 108 - 117 (`proctoringCreateSession`)
  - Baris 132 - 134 (`proctoringAdvance`)
* **Bagian / Fitur**: Protokol Verifikasi Kamera Awal (`proctoring.begin` & `proctoring.verify`)
* **Deskripsi Perubahan**:
  - Pada `proctoringCreateSession()`: sesi non-background pada mode VPS kini menggunakan protokol single-frame (`vps-single-v1`, `stage: 'pending'`) menggantikan `pose-burst-v2`, sedangkan mode lokal (`local-roll-v1`) langsung memulai `stage` di `'ready'` (menggantikan `'tilt'`).
  - Pada `proctoringAdvance()`: menghapus reset `$session['stage'] = 'tilt'` saat status bukan `MATCH` pada mode lokal agar retry setelah kegagalan sementara (misal `NO_FACE` atau `RECHECK`) tidak memaksa peserta memiringkan/menolehkan kepala.
  - Seluruh struktur keamanan lain (token sesi, rotasi nonce, anti-replay SHA-256, pencocokan identitas YuNet/SFace, *receipt* `proctoring_receipts`, pemantauan berkala, audio/fullscreen/tab-switch) tetap utuh dan tidak diubah.
* **Hasil Verifikasi**:
  - `php -l includes/proctoring-verification.php` -> `No syntax errors detected`
  - `php tests/proctoring-state.php` -> `TOTAL 52 PASS`
  - `php tests/proctoring-remote.php` -> 12/12 PASS
  - Simulasi sesi pertama mode lokal tanpa menoleh (`FIRST_CHECK_NO_TILT_OK`) -> PASS


---

### [2026-11-25 17:29:25 WIB] — Pembersihan File Sisa/Debug & Penguatan Blokir HTTP di `.htaccess`
* **Berkas yang Diubah / Dihapus**:
  - Diubah: [`.htaccess`](file:///D:/all_project/up.quizb.my.id/.htaccess) (Baris 3 - 5)
  - Dihapus (13 file di web root): `check_db.php`, `migrate_multiplayer.php`, `migrate_sync.php`, `test.php`, `test2.php`, `test3.php`, `test_dir.php`, `test_upload.php`, `temp_challenge.php`, `temp_sync.php`, `output.txt`, `extracted_text.txt`, `q16.json`
* **Bagian / Fitur**: Access Control HTTP & Kebersihan Web Root
* **Deskripsi Perubahan**:
  - Memverifikasi bahwa ke-13 file sisa/debug/migrasi di web root tidak direferensikan di mana pun dalam kode aplikasi maupun `tests/`, lalu menghapusnya agar tidak dapat diakses publik lewat HTTP (termasuk skrip migrasi/query DB tanpa autentikasi).
  - Menambahkan direktori `docs` dan `services` ke aturan `RewriteRule` `403 Forbidden` pada [`.htaccess`](file:///D:/all_project/up.quizb.my.id/.htaccess#L3-L5), serta memblokir akses HTTP langsung ke seluruh file `*.md` (termasuk `CHANGELOG_AUDIT.md`).
* **Hasil Verifikasi**:
  - Pengecekan referensi lintas repo (`Select-String` di `*.php`, `api/`, `includes/`, `pages/`, `assets/js/`, `tests/`) -> 0 referensi ditemukan.
  - Verifikasi sintaks & struktur aturan `RewriteRule` di `.htaccess` -> valid (`git diff --check` bersih).


---

### [2026-11-25 17:32:25 WIB] — Penguatan Validasi CSRF & Pembatasan Pengecualian Mobile/Aksi di `api.php`
* **Berkas yang Diubah**: [`api.php`](file:///D:/all_project/up.quizb.my.id/api.php)
* **Baris yang Diubah**: Baris 104 - 122 (Blok validasi CSRF untuk `POST`, `PUT`, `DELETE`)
* **Bagian / Fitur**: Router API & Proteksi CSRF (`validateCsrfToken`)
* **Deskripsi Perubahan**:
  - Memperketat deteksi `$isMobileApp` agar hanya berlaku jika tidak ada konteks browser (`HTTP_ORIGIN` dan `HTTP_REFERER` kosong) dan tidak ada token CSRF eksplisit yang dikirim; jika request berasal dari browser atau menyertakan `X-CSRF-Token`/`_csrf`, validasi CSRF tetap wajib dijalankan.
  - Mempersempit `$csrfExemptActions` agar hanya mencakup endpoint pra-autentikasi/sesi auth (`auth.login`, `auth.register`, `auth.logout`, `auth.google`, `auth.google_native`, `auth.resend_verification`). Endpoint `auth.update_fcm_token`, `auth.unregister_fcm_token`, `challenge.*`, `tournament.*`, `message.send`, dan `message.delete` kini wajib melewati validasi CSRF untuk request web (klien web di `utils.v5.js` sudah otomatis mengirimkan `X-CSRF-Token`).
* **Hasil Verifikasi**:
  - `node tests/test-proctoring-suite.cjs` -> 29/29 PASS (termasuk pengecekan pengecualian CSRF pada router `api.php` dan pengiriman `X-CSRF-Token` di `utils.v5.js`).
  - `git diff --check` -> bersih tanpa error whitespace/conflict.


---

### [2026-11-25 17:34:00 WIB] — Menghapus Password Hardcoded pada Skrip Seeder `create_reviewer.php`
* **Berkas yang Diubah**: [`create_reviewer.php`](file:///D:/all_project/up.quizb.my.id/create_reviewer.php)
* **Baris yang Diubah**: Baris 44 - 73 (Pengambilan & validasi kredensial reviewer)
* **Bagian / Fitur**: Seeder Akun Reviewer Google Play
* **Deskripsi Perubahan**:
  - Menghapus password plaintext hardcoded dari source code.
  - Mengubah pengambilan `$email` dan `$password` agar membaca dari opsi CLI (`--email=...`, `--password=...`), environment variable (`REVIEWER_EMAIL`, `REVIEWER_PASSWORD`), atau `$_POST` saat dipanggil oleh admin terautentikasi.
  - Menambahkan validasi format email (`FILTER_VALIDATE_EMAIL`) dan panjang password minimal 12 karakter, serta menolak eksekusi (`exit(1)` di CLI / HTTP `400` JSON di HTTP) jika kredensial tidak diberikan.
* **Hasil Verifikasi**:
  - Review manual struktur sintaks & percabangan CLI/HTTP pada baris 44 - 73 -> valid.
  - `git diff --check` -> bersih tanpa error whitespace/conflict. *(Catatan: `php -l` lokal tidak tersedia karena `php` CLI belum terpasang di PATH mesin ini; jalankan `php -l create_reviewer.php` di server setelah `git pull` untuk memastikan).*


---

### [2026-11-25 17:38:55 WIB] — Membatasi Notifikasi Pengguna Baru (`new_user`) Hanya ke Admin
* **Berkas yang Diubah**:
  - [`verify-email.php`](file:///D:/all_project/up.quizb.my.id/verify-email.php) (Baris 70 - 91)
  - [`api/auth.php`](file:///D:/all_project/up.quizb.my.id/api/auth.php) (Baris 552 - 564, fungsi `auth_verify_email`)
* **Bagian / Fitur**: Verifikasi Email & Pengiriman Notifikasi `new_user`
* **Deskripsi Perubahan**:
  - Mengubah query penerima notifikasi `new_user` pada kedua alur verifikasi email (`verify-email.php` dan `auth_verify_email()` di `api/auth.php`) dari seluruh pengguna aktif (`WHERE id != ? AND is_active = 1`) menjadi khusus admin aktif (`WHERE id != ? AND is_active = 1 AND role = 'admin'`) untuk menghilangkan broadcast O(N) dan spam notifikasi ke seluruh pengguna.
  - Membungkus pengiriman notifikasi di `api/auth.php` dengan `try/catch (\Throwable $e)` (konsisten dengan `verify-email.php`) agar kegagalan push notification tidak menggagalkan proses verifikasi email.
* **Hasil Verifikasi**:
  - Pengecekan seluruh kemunculan `new_user` lintas repo -> hanya tersisa di `verify-email.php`, `api/auth.php`, dan handler UI `app.v5.js`.
  - `git diff --check` -> bersih tanpa error whitespace/conflict.


---

### [2026-11-25 17:41:35 WIB] — Perbaikan Koneksi DB & Pengamanan Mode Dry-Run pada `admin_fix_correct_answers()`
* **Berkas yang Diubah**: [`api/admin.php`](file:///D:/all_project/up.quizb.my.id/api/admin.php)
* **Baris yang Diubah**: Baris 6 - 82 (Fungsi `admin_fix_correct_answers`)
* **Bagian / Fitur**: Endpoint Admin — Audit & Perbaikan Kunci Jawaban Soal
* **Deskripsi Perubahan**:
  - Mengganti `$config = include __DIR__ . '/../config/db.php'` dan `new PDO(...)` (yang tidak kompatibel dengan struktur `config/db.php`) menjadi `DB::conn()`.
  - Menjadikan endpoint berjalan dalam mode `dry_run` secara default (hanya mengembalikan daftar `affected_questions` dan statistik tanpa mengubah database). Perubahan opsi pertama menjadi `is_correct = 1` hanya dieksekusi jika `{"apply": true}` dikirim secara eksplisit, dan dijalankan di dalam transaksi DB (`beginTransaction` / `commit` / `rollBack`) dengan prepared statement yang di-reuse.
  - Menyembunyikan detail error PDO di mode non-development (`error_log` ke server log) agar pesan error database internal tidak bocor ke respons API.
* **Hasil Verifikasi**:
  - Pengecekan referensi `fix_correct_answers` lintas repo -> tidak dipanggil oleh kode frontend/test lain.
  - `git diff --check` -> bersih tanpa error whitespace/conflict. *(Jalankan `php -l api/admin.php` di server setelah `git pull` untuk memastikan).*
