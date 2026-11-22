# Verifikasi kamera tugas — protokol v2

## Perubahan dan batas kemampuan

Browser hanya memberi petunjuk keberadaan/posisi wajah. Server membandingkan foto referensi kanonis dengan frame terkini menggunakan YuNet + SFace dan alignment yang sama. Skor histogram buatan browser, descriptor kosong, boolean dari browser, dan kegagalan model tidak pernah menjadi MATCH. Referensi tidak dikirim kembali ke browser. Frame verifikasi diproses pada hosting yang sama dan tidak dikirim ke layanan AI eksternal.

Peserta harus memiringkan lalu meluruskan kepala pada frame terpisah. Nonce sekali pakai, hash frame, identitas akun, lingkup kuis/tugas, versi referensi, dan masa berlaku diverifikasi di server. Soal dilindungi sampai pemeriksaan awal berhasil. Pengumpulan memerlukan pemeriksaan akhir maksimal 15 detik sebelumnya. Pengulangan respons pengumpulan menggunakan hasil yang sama, tanpa membuat attempt kedua.

Gerakan kepala dan pencegahan replay sederhana **bukan jaminan anti foto/video palsu**. Pendaftaran wajah mandiri juga bukan verifikasi identitas resmi siswa. Ambang cosine 0,50 adalah parameter operasional konservatif, bukan persentase probabilitas identitas. Tujuh sampel smoke test tidak membuktikan akurasi populasi; lakukan uji terkontrol dengan persetujuan peserta sebelum penggunaan luas. Gangguan kamera tidak otomatis mengubah nilai atau menuduh kecurangan. Jeda pengawasan >30 detik ditandai untuk tinjauan manusia.

## Prasyarat dan urutan penerapan

Jangan pull kode gate v2 pada produksi sebelum pemeriksa server siap. Tidak ada fallback MATCH jika runtime tidak tersedia.

1. PHP >=8 dengan GD, PDO MySQL dan kemampuan menjalankan proses worker terbatas; Python >=3.9 dengan venv/pip. Instalasi membuat runtime dan model di `~/.local/share/quizb-proctoring`, di luar public_html. Model diverifikasi SHA-256 dari commit resmi OpenCV Zoo. Dua slot worker, satu thread CPU per proses, batas inferensi 12 detik.
2. Fetch branch rilis. Ekstrak `bin/setup-proctoring.py` dan `bin/face-verify.py` dari ref rilis ke direktori privat sementara **tanpa mengganti checkout produksi**, lalu jalankan installer dengan Python yang tersedia. Pada Rumahweb tersedia `/opt/alt/python311/bin/python3`.
3. Jika perlu, salin `config/proctoring.example.php` menjadi `config/proctoring.php` dan isi path absolut interpreter serta model. Konfigurasi privat diabaikan Git.
4. Periksa `proctoringRuntime([], true)` lewat PHP yang digunakan aplikasi: harus mengembalikan READY. Periksa pula konfigurasi PHP web; hasil CLI saja tidak membuktikan PHP web siap.
5. Setelah siap, pull fast-forward ref rilis pada `/home/quic1934/public_html/up.quizb.my.id`. Jangan menimpa modifikasi server `.htaccess` atau file SSO yang tidak dilacak. Tabel `proctoring_receipts` dibuat secara aditif ketika pertama diperlukan.
6. Jalankan satu tugas uji dengan akun dan foto uji berizin: enrollment, tilt/center, soal muncul, kamera putus/pulih, akhir waktu, pengumpulan, tinjauan guru. Jangan mengubah nilai/data peserta nyata untuk smoke test.

Pemeriksaan hosting 22 November 2026: PHP CLI 8.4.28, Python 3.11.18 tersedia, tetapi `function_exists("proc_open")` menghasilkan false. Checkout produksi masih `4cb9290`. Aktivasi v2 menunggu penyelesaian batas runtime ini; jangan mengklaim sudah aktif online. Menyalakan fungsi proses PHP memerlukan persetujuan perubahan konfigurasi keamanan, atau gunakan worker lokal terisolasi yang dirancang dan diuji terpisah. Jangan mengaktifkan seluruh fungsi yang dibatasi.

## Pengujian

- `node --test tests/test-proctoring-suite.cjs`: 16 skenario state UI/HTTP, termasuk format envelope API, CSRF, lintas sesi akun, kamera berakhir, callback terlambat, retry dan lifecycle.
- `php tests/proctoring-state.php`: 37 assertion protokol server, nonce, replay, scope, receipt, umur bukti, withholding soal dan tinjauan manusia. Menggunakan DB mock; bukan pengujian MySQL produksi.
- `QUIZB_FACE_FIXTURES=/path/private python tests/proctoring-model.py`: 7 inferensi nyata terhadap foto sampel OpenCV di luar repo; bukan foto siswa.
- `node tests/notifications-api-session.cjs`: lulus, memastikan utilitas API tetap memisahkan sesi akun.
- `node tests/notifications-frontend.cjs`: gagal pada assertion poller login di `app.v5.js` yang tidak berubah pada patch ini; dicatat sebagai kegagalan lama di luar scope kamera, bukan disembunyikan sebagai lulus.

Tidak ada kredensial, model biner, venv, atau foto wajah uji yang dimasukkan Git. Pengujian integrasi MySQL dan kamera nyata di hosting tetap wajib sebelum menyatakan penerapan selesai.

- `php tests/proctoring-runtime.php`: 4 pemeriksaan nyata PHP → Python → model, termasuk runtime hilang dan frame invalid.
- `PLAYWRIGHT_MODULE_DIR=/path/playwright ALPINE_JS=/path/alpine.min.js node tests/proctoring-browser.cjs`: template produksi dijalankan dengan Alpine 3.14.9 di Edge; tombol pemulihan, pengumpulan ulang, teardown tanpa error JavaScript. API/kamera pada uji template ini disimulasikan.
