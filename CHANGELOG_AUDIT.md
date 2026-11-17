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
