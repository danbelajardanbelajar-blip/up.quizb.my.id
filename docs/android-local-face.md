# Pemeriksaan wajah lokal Android 1.0.7

Android membundel YuNet 2023mar dan SFace 2021dec dari OpenCV Zoo. Model dan lisensi disimpan bersama manifest checksum di `app/src/main/assets/face_models` pada proyek Android. Pencocokan menggunakan cosine similarity dengan ambang operasional 0,50; angka ini bukan probabilitas identitas atau klaim akurasi.

## Alur sesi

1. Login, keanggotaan tugas, verifikasi server awal, dan pelepasan soal tetap menggunakan mekanisme sebelumnya.
2. Android memanggil POST `api.php?action=proctoring.local_reference` dengan JSON `{"session":"TOKEN_SESI_TERVERIFIKASI"}` menggunakan cookie login dan CSRF yang sama.
3. Endpoint memvalidasi pemilik sesi, akses tugas, tahap verifikasi awal, umur verifikasi (maksimal 60 detik), serta versi foto acuan. Parameter ID pengguna lain tidak digunakan. Respons memuat foto acuan kanonik pengguna sendiri sebagai data URI dan hash versinya; path upload tidak diekspos.
4. Android menghitung embedding acuan di HP, lalu memeriksa gambar kamera dengan target satu sampel per detik. Tidak ada request verifikasi maupun unggahan foto dari loop ini. Sampel dan inferensi diproses berurutan, tanpa menumpuk pekerjaan; HP yang lebih lambat dari satu detik per inferensi menghasilkan interval aktual lebih panjang.
5. Jawaban baru dapat dipilih setelah kecocokan lokal pertama. Dua kegagalan berurutan menampilkan peringatan dan menutup interaksi soal. Dua kecocokan memulihkan keadaan setelah peringatan. Kegagalan kamera/engine langsung menutup interaksi; tidak dianggap cocok.
6. Waktu pengerjaan tetap berjalan saat peringatan. Pengumpulan mengambil foto baru dan tetap membutuhkan verifikasi akhir server beserta receipt yang sah.

Foto acuan dan frame tidak diarsipkan oleh pemeriksaan lokal. Embedding acuan digunakan dalam memori selama ViewModel aktif dan dilepas ketika ViewModel ditutup. Hanya bobot model publik disalin ke direktori privat aplikasi untuk dipakai ulang. Log HTTP tidak boleh mencetak body respons foto acuan.

## Pemasangan

Pull backend sebelum memasang APK 1.0.7. Berkas produksi yang berubah untuk endpoint ini hanya `api/proctoring.php`; endpoint baru menggunakan helper otorisasi yang sudah ada. Tidak ada migrasi tabel tambahan untuk fitur lokal ini. Persyaratan migrasi pengumpulan tugas v2 sebelumnya tetap berlaku.

Web tetap memakai pemeriksaan web yang sudah ada. Tidak ada perubahan kontrak server yang menganggap hasil lokal sebagai bukti verifikasi server. Jika tidak ada pemeriksaan server berkala lebih dari 30 detik, kebijakan monitoring gap yang sudah ada dapat menandai receipt sebagai perlu tinjauan. Ini disengaja: hasil lokal tidak merupakan bukti pengawasan server yang terus-menerus, dan tidak mengubah nilai otomatis.

## Batas keandalan dan pengujian

Pencocokan lokal bukan deteksi presentation attack tersertifikasi dan tidak membuktikan bahwa APK/perangkat bebas modifikasi. Verifikasi server awal/akhir dipertahankan. Uji perangkat dengan pencahayaan, orientasi, serta kemampuan CPU berbeda sebelum dipakai untuk keputusan berisiko tinggi.

Pengujian memakai foto contoh publik OpenCV, bukan foto mahasiswa. Pengujian model desktop memeriksa wajah sama/berbeda, rotasi, beberapa wajah, gambar kosong, serta referensi rusak. Pengujian Android instrumentation disiapkan untuk JNI dan model asli. Peluncuran emulator pada sesi implementasi ditolak pemeriksaan otomatis (`blocked by policy`); hasil instrumentation tidak boleh dinyatakan lulus sebelum dijalankan pada perangkat/emulator yang diizinkan.
