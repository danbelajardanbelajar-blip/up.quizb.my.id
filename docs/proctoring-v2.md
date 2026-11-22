# Verifikasi kamera QuizB — protokol v2

## Arsitektur produksi: mengikuti LPPAI

QuizB PHP hosting → cURL HTTPS → `https://face.lppai.quizb.my.id` → mesin YuNet/SFace di VPS pengguna 43.173.50.61. Implementasi ini mengikuti `includes/pretes-face-engine.php` serta layanan `services/face-engine` di proyek LPPAI lokal. Health endpoint yang diperiksa mengembalikan ready=true, engine opencv-sface-v1, capture_protocol pose-burst-v2 dan max_frames=9. Tidak perlu mengaktifkan proc_open/proc_close di shared hosting. Konfigurasi driver remote tidak pernah fallback ke proses PHP atau menganggap kegagalan mesin sebagai MATCH.

Foto referensi kanonis dan frame kamera dikirim server-ke-server ke VPS milik pengguna yang sama seperti LPPAI, melalui HTTPS terverifikasi dan bearer token privat. Token tidak dikirim ke browser, URL, Git atau log. Layanan LPPAI existing tidak diubah dan tidak mempersistenkan foto/embedding. Konfigurasi di `config/proctoring.php` diabaikan Git dan dihalangi akses HTTP oleh aturan hosting existing. Script CLI `bin/configure-proctoring-vps.php` dapat mengambil URL/token dari konfigurasi privat LPPAI di hosting yang sama tanpa mencetak token.

Check-in menggunakan sembilan foto: tiga posisi lurus, tiga menoleh mengikuti arah acak server, tiga kembali lurus. Server memvalidasi kepemilikan kuis/tugas/referensi, nonce dan kedaluwarsa 90 detik. Hasil gerakan dipercaya hanya dari engine VPS. Skor/boolean browser tidak pernah membuka ujian. Setelah check-in, satu foto cukup untuk pemeriksaan berkala/akhir. Ambang cosine remote 0,45 mengikuti default LPPAI; ini indeks kemiripan, bukan persentase kepastian identitas.

Gerakan dasar **bukan PAD/liveness bersertifikasi**, belum menjamin penolakan foto/video palsu, kamera virtual atau deepfake. Enrollment mandiri bukan pembuktian identitas resmi siswa. Gangguan kamera menjeda pengerjaan dan mempertahankan jawaban; tidak otomatis mengubah nilai atau menuduh kecurangan. Jeda pengawasan >30 detik ditandai untuk tinjauan manusia. Kuota inferensi LPPAI dibagi dengan QuizB; antrean penuh mendapat respons sibuk dan perlu retry.

## Penerapan manual di hosting

Branch rilis `codex/camera-server-verification`; produksi masih memakai commit lama sampai preflight berhasil. Pengguna memilih mengetik perintah terminal sendiri. Akses SSH awal ditolak; setelah pengguna memberikan kredensial VPS yang berlaku, login berhasil. Layanan LPPAI diperiksa aktif/running. Probe HTTPS terautentikasi dengan JPEG hitam buatan menghasilkan engine=opencv-sface-v1, status=NO_FACE, match=false. Layanan VPS tidak diubah. Deployment aplikasi QuizB di hosting masih menunggu perintah manual pengguna.

1. Fetch branch tanpa mengganti checkout produksi. Ekstrak `includes/proctoring-remote.php`, `includes/proctoring-verification.php`, `bin/configure-proctoring-vps.php` dan `bin/check-proctoring.php` ke folder privat staging dengan struktur yang sama dan subfolder config.
2. Jalankan script configure staging dengan path konfigurasi privat LPPAI. Lokasi mengikuti deployment LPPAI: `~/public_html/lppai.quizb.my.id/web/config/pretes-face.local.php`. Script menolak path tidak ada, token pendek, URL lain, atau menimpa konfigurasi yang sudah ada. Jangan mengirim isi file/token ke chat.
3. Jalankan `php bin/check-proctoring.php` dari staging. Harus health READY dan authenticated blank-frame probe NO_FACE. Probe memakai JPEG hitam buatan GD, bukan data peserta. Ini memeriksa token diterima dan gambar tanpa wajah ditolak; tidak membuktikan akurasi populasi.
4. Sesudah PASS, salin konfigurasi privat ke config/proctoring.php produksi dan gunakan izin 600. Pull branch rilis fast-forward pada `~/public_html/up.quizb.my.id`. Pertahankan perubahan hosting .htaccess/SSO yang tidak terkait. Tabel proctoring_receipts dibuat aditif ketika pertama diperlukan.
5. Ulangi preflight pada checkout aplikasi yang diperbarui. Lakukan tugas uji berizin: enrollment, sembilan foto gerakan, soal terbuka, putus/pulih kamera, akhir waktu dan pengumpulan, tinjauan guru. Pengujian melalui PHP web tetap diperlukan karena konfigurasinya bisa berbeda dari CLI. Jangan menyatakan selesai online sebelum langkah ini terbukti.

## Verifikasi lokal

- 17 skenario Node mencakup lifecycle kamera, nonce/respons/CSRF, pemisahan sesi akun, retry dan pengambilan sembilan frame dengan arahan server.
- 41 assertion PHP mencakup scope, replay, receipt, kedaluwarsa, withheld questions, tinjauan manusia dan attestation gerakan VPS.
- 12 pemeriksaan parser/konfigurasi bridge VPS mencakup skor invalid, identitas engine, boolean/string, gerakan dan fail closed.
- Template produksi dijalankan dengan Alpine 3.14.9 di Edge: pemulihan kamera, pengumpulan ulang dan teardown tanpa error JavaScript. API/kamera disimulasikan pada uji ini.
- Pengujian sesi API notifikasi lulus. Tes frontend notifikasi existing gagal di assertion poller login pada app.v5.js yang tidak diubah patch ini; tidak diklaim lulus.
- Driver lokal opsional lama: 7 inferensi model nyata dan 4 pemeriksaan PHP–Python lulus. Runtime privat yang sempat dipasang pada shared hosting tidak digunakan driver remote. Thread dibatasi sebelum import untuk mencegah OpenBLAS mencoba 80 thread. Tidak perlu mengaktifkan fungsi proses PHP.

Tidak ada kredensial, model biner, venv atau foto peserta yang dimasukkan Git. Integrasi MySQL dan kamera nyata online belum diverifikasi.

## Hasil preflight hosting dari terminal pengguna

Pada 22 November 2026 pengguna menjalankan staging privat di akun Rumahweb quic1934. Konfigurasi berhasil disalin dari LPPAI tanpa menampilkan token. Output: Engine health READY; Authenticated blank-frame probe NO_FACE; PASS model ready, credentials accepted, blank image rejected. Ini membuktikan PHP CLI hosting dapat memanggil VPS melalui cURL dengan konfigurasi yang benar. Proses berikutnya: salin konfigurasi privat ke QuizB, pull kode aplikasi dan uji kamera melalui PHP web. Tidak ada perubahan kode/database/konfigurasi asli LPPAI.

## Pemulihan setelah regresi 4d23db9 dan kemudahan gerakan

Atas permintaan pengguna, alur server-authoritative dari 1972cd3 dipulihkan: check-in VPS wajib sebelum soal, pemeriksaan berkala/akhir melalui VPS, receipt server untuk pengumpulan, penolakan acuan/descriptor kosong, CSRF, dan tidak ada fallback menyimpan acuan ketika mesin tidak tersedia. Instruksi singkat pada banner tetap dipertahankan. Panah kini membalik arah raw server untuk menyesuaikan preview bercermin seperti LPPAI.

Endpoint khusus QuizB dirancang di https://face.lppai.quizb.my.id/quizb, worker loopback 8018. Batas gerakan menoleh 0,08 (sebelumnya 0,12); toleransi kembali lurus 0,14 (sebelumnya 0,10). Ambang kemiripan identitas seluruh sampel tetap default 0,45. LPPAI tetap menggunakan worker 8017, kode/parameter aslinya. Lihat services/quizb-face-engine/README.md untuk isolasi, pemasangan dan batas pengujian. Toleransi ini perlu diuji pada kamera nyata; tidak diklaim sebagai peningkatan akurasi biometrik atau PAD.
