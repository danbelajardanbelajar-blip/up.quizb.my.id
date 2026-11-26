# Pengumpulan tugas Android: kontrak v2

Perubahan ini disiapkan secara lokal. Backend harus dipasang **sebelum** APK Android baru disebarkan. Android menolak memulai tugas jika server belum mendukung kontrak v2; kuis biasa tetap memakai alur lama.

## Berkas backend yang dipasang

- `api/attempt.php`
- `api/assignment.php`
- `includes/assignment-submission.php` (berkas baru)
- `includes/proctoring.php` (mencegah DDL pada pembacaan foto acuan di dalam transaksi)
- `bin/migrate-assignment-submission.php` (dijalankan melalui CLI)

Pasang berkas API dan helper bersama-sama, lalu jalankan migrasi CLI di bawah. Handler web `assignment.submit` tetap tersedia dan menerima pengiriman ulang hasil yang sama, termasuk setelah tenggat atau nilai 100. Web tidak harus langsung beralih ke kontrak baru.

## Persiapan database

Cadangkan database sebelum pemasangan. Tabel `assignments`, `assignment_submissions`, `attempts`, `attempt_answers`, `users`, dan `quizzes` harus memakai InnoDB. Tabel tambahan `assignment_attempts`, `submission_requests`, dan `proctoring_receipts` juga harus InnoDB jika sudah ada. Jangan mengubah engine tabel produksi tanpa mengecek indeks, foreign key, ukuran tabel, dan jadwal pemeliharaan.

Jalankan `php bin/migrate-assignment-submission.php` di server setelah menyiapkan cadangan. Skrip hanya menambahkan tabel yang belum ada dan memeriksa engine; tidak mengubah atau menghapus nilai mahasiswa. Endpoint kompatibilitas baru menyatakan siap setelah seluruh tabel tersedia dan memakai InnoDB.

Kode juga memastikan tabel tambahan tersedia sebelum transaksi dimulai. Akun database membutuhkan izin CREATE untuk alur tersebut, seperti implementasi proctoring sebelumnya. Jika CREATE tidak diizinkan, jalankan definisi CREATE di `assignmentSubmissionTables()` dan `proctoringReceiptTable()` melalui prosedur migrasi yang digunakan server, lalu sesuaikan strategi schema bootstrap sebelum rilis.

`submission_requests` memiliki primary key `(user_id, submission_id)`, hash jawaban, dan respons hasil yang disimpan dalam transaksi yang sama dengan nilai serta pengumpulan tugas. Jangan menghapus catatan idempotensi selama perangkat masih mungkin mengirim ulang ID tersebut; penghapusan terlalu cepat bisa menghasilkan percobaan ganda.

Untuk identitas backend yang stabil ketika database berpindah host, atur environment `QUIZB_BACKEND_ID` dengan nilai unik per database logis. Gunakan nilai yang sama pada dua domain hanya jika keduanya benar-benar memakai database yang sama. Nilai ini bukan password dan endpoint hanya mengirim hash-nya. Atur sebelum APK baru dipakai agar jurnal perangkat tidak kehilangan cakupan identitasnya.

## Verifikasi sebelum menyebarkan Android

Jalankan:

```powershell
python bin/check-assignment-backends.py
```

Kode keluar 0 berarti kedua domain mendukung kontrak v2 dan melaporkan identitas database sama. Identitas sama tidak menggantikan pemeriksaan data melalui akun uji. Pada pemeriksaan sebelum pemasangan, kedua domain masih mengembalikan 404 untuk endpoint baru; kesamaan database belum terbukti.

## Perilaku Android

- Sebelum pengiriman pertama, jawaban, soal, waktu, dan UUID permintaan disimpan secara atomik di direktori privat yang dikecualikan dari backup. Foto kamera tidak dimasukkan ke jurnal.
- Jurnal dipisah menurut URL backend, identitas database, akun, dan tugas. Buka tugas yang sama dengan akun yang sama untuk memulihkan pengumpulan setelah aplikasi ditutup. Jawaban dikunci setelah proses pengumpulan dimulai.
- Maksimal tiga pengiriman otomatis untuk kegagalan sementara, dengan jeda 1 dan 2 detik. Penolakan akses, tenggat, dan validasi tidak diulang otomatis.
- Server menyimpan nilai, jawaban, statistik pengguna, riwayat tugas, status pengumpulan, dan hasil idempotensi dalam satu transaksi. Permintaan ulang dengan ID serta jawaban sama mengembalikan hasil lama. ID sama dengan isi berbeda ditolak.
- Android hanya membuka hasil setelah menerima `assignment_submitted: true`. Jika respons hilang, Android memeriksa ID lama terlebih dahulu sebelum memperbarui bukti kamera.
- Jika hasil yang dahulu tersimpan sudah dihapus/reset oleh pengajar, server mengembalikan 410. Android menawarkan Kerjakan Ulang; jurnal lama hanya dibersihkan setelah pengguna memilih tindakan ini.
- Foto akhir diambil baru setelah verifikasi berkala selesai. Verifikasi berkala dihentikan selama pengiriman agar nonce tidak bertabrakan. Jika sesi sudah hilang, pengguna diminta melakukan verifikasi awal lagi tanpa mengganti jawaban/ID pengumpulan yang tersimpan.
- Jurnal hanya melindungi pengumpulan yang sudah dimulai; ini bukan penyimpanan otomatis pengerjaan sebelum tombol Kumpulkan ditekan. Hapus data aplikasi atau uninstall akan menghapus jurnal lokal.
- Timer memakai waktu monotonic agar jeda aplikasi di latar belakang tetap diperhitungkan. Durasi tugas dari server diprioritaskan.

## Pengujian

```powershell
# Android, dari D:/all_project/quizb
.\gradlew.bat testDebugUnitTest assembleDebug
# Build debug ke backend pembanding bila sudah siap
.\gradlew.bat assembleDebug -Pquizb.debugBaseUrl=https://up.quizb.my.id/

# Backend, dari folder ini, PHP dengan pdo_sqlite dan mbstring aktif
php tests/assignment-submission.php
php tests/proctoring-state.php
```

Pengujian transaksi menggunakan database SQLite sementara dan adapter SQL khusus pengujian; tidak menyentuh akun, foto, nilai, atau notifikasi produksi. Uji staging MySQL/InnoDB tetap diperlukan untuk penguncian/concurrency sesungguhnya. Uji perangkat diperlukan untuk izin kamera, orientasi foto, berhenti/pulih aplikasi, koneksi putus sesudah server menyimpan hasil, tenggat, penghentian oleh pengajar, dan kecocokan hasil Android dengan web pengajar. Tidak ada perangkat Android yang tersambung saat implementasi ini diperiksa.

## Batas perubahan

Alamat produksi Android tetap `https://quizb.my.id/`. Backend debug dapat dipilih dengan properti Gradle di atas. Kode lokal ini belum dipublikasikan ke server atau Google Play. Validasi server yang sudah ada untuk kuis/paket, keanggotaan, tenggat, nilai penuh, dan bukti kamera dipertahankan; ini tidak memperkenalkan pengawasan kamera offline atau menganggap tidak adanya laporan kendala sebagai bukti siap produksi.
