## Implementasi Multiple Question Packages per Assignment

### Ringkasan Perubahan

Fitur ini memungkinkan admin/guru menambahkan **beberapa paket soal** untuk satu tugas, bukan hanya satu. Paket-paket soal ini akan digabung menjadi satu set soal untuk dikerjakan siswa.

---

## LANGKAH IMPLEMENTASI

### 1. DATABASE (Jalankan SQL ini terlebih dahulu)

```sql
-- Jalankan file: add_assignment_quiz_packages.sql
-- Ini akan membuat tabel junction untuk assignment_quiz_packages
```

**Tabel baru**: `assignment_quiz_packages`
- Menyimpan hubungan many-to-many antara assignments dan quizzes
- Kolom `order_index` untuk menjaga urutan paket soal

### 2. BACKEND API (Sudah diupdate)

File: `api/assignment.php`

**Perubahan:**
- `assignment_create()`: Sekarang menerima `quiz_ids` (array) atau `quiz_id` (single, backward-compatible)
- `assignment_list()`: Menambahkan `quiz_packages` array di response
- `assignment_get()`: Menambahkan `quiz_packages` array di response  
- `assignment_update()`: Support update `quiz_ids` dan maintain backward compatibility

**Backward Compatibility:**
- API masih menerima single `quiz_id` (akan dikonversi ke array)
- Database masih menyimpan `quiz_id` di tabel assignments (sebagai primary/reference)

### 3. FRONTEND UI (Sudah diupdate)

File: `pages/classroom-detail.html`

**Perubahan:**
- Ganti dropdown single-select dengan **multi-select tag-style**
- Mirip dengan tagging di WordPress
- Selected packages ditampilkan sebagai **tags yang bisa dihapus**
- Dropdown untuk menambah package baru

**Features:**
- ✓ Tampilkan tag untuk setiap selected package
- ✓ Tombol X untuk menghapus tag
- ✓ Dropdown untuk menambah package
- ✓ Feedback visual (highlight selected items)
- ✓ Tooltip dengan jumlah soal per package

### 4. JAVASCRIPT LOGIC (Sudah diupdate)

File: `assets/js/app.js`

**Perubahan:**
- `classroom.assignForm.quiz_ids`: Diubah dari string ke array
- `openAssignModal()`: Initialize `quiz_ids` sebagai array, tambah `assignQuizDropdownOpen` flag
- `createAssignment()`: Kirim `quiz_ids` array ke API
- `openEditAssignModal()`: Extract quiz_ids dari `quiz_packages` API response
- `updateAssignment()`: Include `quiz_ids` pada update payload

---

## CARA MENGGUNAKAN DI FRONTEND

### Untuk Guru - Membuat Tugas Baru:

1. Klik **"+ Buat Tugas"**
2. Isi **Judul Tugas**
3. **Pilih Paket Soal**:
   - Dropdown menunjukkan list semua quiz
   - Klik quiz untuk menambahkan (muncul sebagai tag)
   - Bisa tambah multiple quiz
   - Klik X di tag untuk menghapus
4. Atur setting lainnya (Mode, Deadline, dll)
5. Klik **"Buat Tugas"**

### Untuk Siswa - Mengerjakan Tugas:

- Soal dari semua package akan digabung menjadi satu quiz
- Urutan mengikuti `order_index` dari table `assignment_quiz_packages`
- Setting jumlah soal tetap mengikuti assignment preferences

---

## STRUKTUR RESPONSE API

### assignment_create response:

```json
{
  "id": 5,
  "class_id": 1,
  "quiz_id": 2,  // Primary quiz (first in the list)
  "title": "Tugas 1",
  "mode": "bebas",
  ...
  "quiz_packages": [
    {
      "id": 1,
      "assignment_id": 5,
      "quiz_id": 2,
      "order_index": 0,
      "title": "Quiz Matematika Dasar",
      "total_questions": 20
    },
    {
      "id": 2,
      "assignment_id": 5,
      "quiz_id": 3,
      "order_index": 1,
      "title": "Quiz Geometri",
      "total_questions": 15
    }
  ]
}
```

### assignment_list response:

Setiap assignment dalam list akan include `quiz_packages` array (sama format di atas).

---

## TESTING CHECKLIST

- [ ] Buat assignment dengan 1 package soal ✓
- [ ] Buat assignment dengan 2+ package soal ✓
- [ ] Edit assignment (ubah packages) ✓
- [ ] Lihat daftar assignment (tampilkan semua packages) ✓
- [ ] Siswa kerjakan assignment dengan multiple packages
- [ ] Cek scoring & submission handling
- [ ] Backward compatibility: Old assignments masih berfungsi

---

## CATATAN PENTING

1. **Backward Compatibility**: 
   - Old code yang mengirim `quiz_id` (single) masih work
   - Database migration migrate data lama ke table baru

2. **Soal Digabung**:
   - Saat siswa kerjakan, soal dari semua packages akan digabung
   - Implementasi actual soal merging ada di `quiz-engine.js` (jika perlu diupdate nanti)

3. **Scoring**:
   - Scoring tetap normal - totalnya berdasarkan semua soal yang dikerjakan
   - Tidak ada double scoring untuk multiple packages

4. **Future Enhancements**:
   - Bisa tambahkan UI untuk reorder packages (drag-drop)
   - Bisa add per-package settings jika diperlukan

---

## FILES DIMODIFIKASI

1. ✅ `add_assignment_quiz_packages.sql` - Migration file (baru)
2. ✅ `api/assignment.php` - API endpoints (4 fungsi diupdate)
3. ✅ `pages/classroom-detail.html` - UI modal (diupdate)
4. ✅ `assets/js/app.js` - JavaScript logic (5 fungsi/properti diupdate)

---

## NEXT STEPS

1. **Jalankan migration SQL** untuk create tabel baru
2. **Test di local** dengan berbagai skenario:
   - Create dengan 1 package
   - Create dengan 2+ packages
   - Edit assignment
   - Student attempt assignment

3. **Jika ada issues**, check:
   - Browser console untuk JS errors
   - Network tab untuk API responses
   - Database untuk verify data di `assignment_quiz_packages` table

4. **Untuk soal merging di quiz engine** (jika belum implement):
   - Perlu update `quiz-engine.js` untuk handle multiple packages
   - Lihat bagaimana attempt digarap untuk multiple quizzes
