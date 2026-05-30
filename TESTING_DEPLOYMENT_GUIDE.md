## 📋 TESTING & DEPLOYMENT GUIDE
### Multiple Question Packages per Assignment Feature

---

## ✅ PRE-DEPLOYMENT CHECKLIST

### 1. Database Setup
- [ ] Stop web server / close active sessions
- [ ] Backup database
- [ ] Jalankan SQL migration:
  ```bash
  mysql -u username -p database_name < add_assignment_quiz_packages.sql
  ```
- [ ] Verify tabel `assignment_quiz_packages` sudah dibuat:
  ```sql
  DESC assignment_quiz_packages;
  SHOW TABLES LIKE 'assignment_quiz%';
  ```

### 2. File Updates
- [ ] Confirm semua files sudah terupdate:
  - `api/assignment.php` ✅
  - `pages/classroom-detail.html` ✅
  - `assets/js/app.js` ✅

### 3. Browser Cache
- [ ] Clear browser cache (Ctrl+Shift+Delete atau Cmd+Shift+Delete)
- [ ] Close all tabs aplikasi
- [ ] Reload halaman fresh

---

## 🧪 MANUAL TESTING SCENARIOS

### Scenario 1: Buat Assignment dengan 1 Paket Soal (Backward Compatibility)
**Expected**: Assignment berfungsi normal, hanya beda UI-nya

**Steps**:
1. Login sebagai Guru
2. Buka Classroom > Buat Tugas
3. Isi Judul Tugas
4. **Click dropdown paket soal** → pilih 1 quiz
5. Lihat quiz muncul sebagai **tag/badge**
6. Klik X di tag → quiz terhapus
7. Klik add button → dropdown muncul lagi
8. Atur setting lain, klik **"Buat Tugas"**
9. **Verify**:
   - ✅ Assignment muncul di list
   - ✅ Tampilkan 1 paket soal dengan jumlah soal
   - ✅ Assignment di database berisi data yang benar

### Scenario 2: Buat Assignment dengan 2+ Paket Soal (NEW FEATURE)
**Expected**: Multiple packages bisa dipilih, dilihat sebagai tags

**Steps**:
1. Login sebagai Guru
2. Buka Classroom > Buat Tugas
3. Isi Judul Tugas
4. **Click dropdown** → select quiz 1 → click dropdown lagi
5. **Select quiz 2** → dua quiz muncul sebagai tags
6. Optional: add quiz 3, 4, dst
7. Klik X di salah satu tag → quiz itu dihapus
8. Atur setting, klik **"Buat Tugas"**
9. **Verify**:
   - ✅ Semua tags terbentuk dengan benar
   - ✅ Assignment list menampilkan semua paket soal
   - ✅ Response API include `quiz_packages` array

### Scenario 3: Edit Assignment
**Expected**: Bisa lihat paket soal yang ada, bisa ubah settings lain

**Steps**:
1. Di Classroom, cari assignment yang sudah dibuat
2. Klik tombol **"Edit"**
3. Modal terbuka dengan "✏️ Edit Tugas"
4. **Verify**:
   - ✅ Judul terisi
   - ✅ Paket soal ditampilkan sebagai badges (read-only)
   - ✅ Bisa ubah Title, Mode, Deadline, dll
   - ✅ Quiz packages tidak bisa diubah (sesuai design)
5. Ubah beberapa settings, klik **"Simpan Perubahan"**
6. **Verify**:
   - ✅ Toast success muncul
   - ✅ Assignment terupdate
   - ✅ Paket soal tetap sama

### Scenario 4: Student Mengerjakan Assignment dengan Multiple Packages
**Expected**: Semua soal dari packages digabung jadi satu quiz

**Steps**:
1. Login sebagai Siswa/Pelajar
2. Lihat Assignment di kelas
3. Klik **"Kerjakan"**
4. **Verify**:
   - ✅ Quiz engine terbuka
   - ✅ Soal dari semua packages dimuat
   - ✅ Jumlah soal = total dari semua packages
   - ✅ Bisa kerjakan sampai selesai
   - ✅ Score dihitung dengan benar

### Scenario 5: Lihat Results (Guru)
**Expected**: Hasil assignment dengan multiple packages ditampilkan dengan benar

**Steps**:
1. Login sebagai Guru
2. Assignment list > klik **"📊 Hasil"**
3. **Verify**:
   - ✅ Semua siswa yang submit ditampilkan
   - ✅ Score, completion count benar
   - ✅ Tidak ada error di data

---

## 🐛 BUG TESTING

### Network Issues
- [ ] Test dengan network throttling (Chrome DevTools)
- [ ] Test disconnect/reconnect saat edit modal terbuka
- [ ] Verify error handling muncul dengan baik

### Browser Compatibility
- [ ] Chrome/Chromium
- [ ] Firefox
- [ ] Safari
- [ ] Edge

### UI/UX Edge Cases
- [ ] Pilih 1 quiz → hapus → lihat error message muncul
- [ ] Pilih 10+ quizzes → scroll di dropdown
- [ ] Modal terbuka > press ESC → close dengan benar
- [ ] Mobile view (responsive)

---

## 📊 API TESTING

### Gunakan Postman / cURL untuk test API:

#### 1. Test Create Assignment (Single Package - Backward Compat)
```bash
curl -X POST http://localhost/api.php?action=assignment.create \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "class_id": 1,
    "quiz_id": 5,
    "title": "Test Assignment Single",
    "mode": "bebas"
  }'
```

**Expected Response**:
```json
{
  "status": "success",
  "data": {
    "id": 10,
    "quiz_id": 5,
    "quiz_packages": [
      {
        "id": 1,
        "assignment_id": 10,
        "quiz_id": 5,
        "order_index": 0,
        "title": "Quiz ABC",
        "total_questions": 20
      }
    ]
  }
}
```

#### 2. Test Create Assignment (Multiple Packages - NEW)
```bash
curl -X POST http://localhost/api.php?action=assignment.create \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "class_id": 1,
    "quiz_ids": [5, 6, 7],
    "title": "Test Assignment Multiple",
    "mode": "bebas"
  }'
```

**Expected Response**:
```json
{
  "status": "success",
  "data": {
    "id": 11,
    "quiz_id": 5,
    "quiz_packages": [
      {
        "id": 2,
        "assignment_id": 11,
        "quiz_id": 5,
        "order_index": 0,
        "title": "Quiz ABC",
        "total_questions": 20
      },
      {
        "id": 3,
        "assignment_id": 11,
        "quiz_id": 6,
        "order_index": 1,
        "title": "Quiz DEF",
        "total_questions": 15
      },
      {
        "id": 4,
        "assignment_id": 11,
        "quiz_id": 7,
        "order_index": 2,
        "title": "Quiz GHI",
        "total_questions": 10
      }
    ]
  }
}
```

#### 3. Test Get Assignment
```bash
curl http://localhost/api.php?action=assignment.get&id=11 \
  -H "Authorization: Bearer YOUR_TOKEN"
```

**Expected**: Response include `quiz_packages` array

#### 4. Test Update Assignment
```bash
curl -X PUT http://localhost/api.php?action=assignment.update&id=11 \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "title": "Updated Title",
    "mode": "exam",
    "quiz_ids": [5, 8]
  }'
```

**Expected**: Assignment updated dengan quiz_ids baru

---

## 📈 PERFORMANCE TESTING

### Load Testing
- [ ] Test dengan 100+ students di 1 classroom
- [ ] Test dengan 50+ assignments
- [ ] Measure query time untuk assignment.list
- [ ] Check database indexes (created automatically)

### Database Verification
```sql
-- Check records di assignment_quiz_packages
SELECT * FROM assignment_quiz_packages;

-- Check assignment dengan multiple packages
SELECT 
  a.id, a.title, a.quiz_id,
  COUNT(aqp.id) as package_count
FROM assignments a
LEFT JOIN assignment_quiz_packages aqp ON a.id = aqp.assignment_id
GROUP BY a.id
HAVING package_count > 1;

-- Verify foreign keys work
PRAGMA foreign_key_list(assignment_quiz_packages);
```

---

## 🔄 ROLLBACK PROCEDURE

Jika ada issue yang serious, berikut cara rollback:

### 1. Database Rollback
```sql
-- IMPORTANT: Backup data dulu!
-- DROP tabel (akan menghapus data):
DROP TABLE assignment_quiz_packages;

-- Atau jika ingin soft-delete, just disable foreign keys:
ALTER TABLE assignment_quiz_packages DISABLE KEYS;
```

### 2. Code Rollback
- Revert commits di git
- Restore old versions dari API, HTML, JS files
- Clear browser cache

### 3. Full Rollback
- Restore database dari backup
- Restore code dari git
- Restart services
- Clear all caches

---

## 📝 KNOWN LIMITATIONS

1. **Edit Assignment**: Tidak bisa ubah quiz packages saat edit
   - Rationale: Jika sudah ada student attempts, mengubah packages bisa kompleks
   - Solusi: Buat tugas baru jika butuh packages berbeda

2. **Soal Merging**: Implementasi soal merging di quiz-engine.js bisa perlu refinement
   - Check apakah attempt handling sudah support multiple quizzes

3. **Backward Compatibility**: Old assignments masih work tapi tanpa quiz_packages
   - Gradual migration bisa dijalankan di background

---

## 📞 TROUBLESHOOTING

### Issue: Dropdown tidak muncul / tidak bisa select
**Solution**:
- Clear cache browser (Ctrl+Shift+Delete)
- Check console untuk JS errors
- Verify `classroom.assignQuizDropdownOpen` initialized

### Issue: "Pilih minimal satu paket soal" error saat create
**Solution**:
- Verify quiz sudah ter-load di `classroom.quizListForAssign`
- Check API `quiz.list` response
- Verify `quiz_ids` array not empty sebelum submit

### Issue: API error 403/404
**Solution**:
- Verify token/auth valid
- Verify classroom/quiz IDs valid
- Check user punya akses ke kelas

### Issue: Database FK constraint error
**Solution**:
- Verify tabel `assignment_quiz_packages` sudah dibuat
- Verify FK constraints not violated
- Check delete cascade behavior

---

## ✨ SUCCESS INDICATORS

Fitur dianggap sukses jika:

✅ Guru bisa create assignment dengan multiple packages  
✅ UI menampilkan tags untuk setiap package  
✅ API mengembalikan `quiz_packages` array  
✅ Student bisa kerjakan assignment dengan soal merged  
✅ Scoring berfungsi correct  
✅ Backward compatibility maintained untuk old assignments  
✅ No error di console browser  
✅ No error di server logs  
✅ Database records valid  

---

## 🎉 DEPLOYMENT READY

Jika semua checklist di atas sudah ✅, fitur siap untuk production!

**Next Steps**:
1. Announce ke users tentang fitur baru
2. Prepare documentation/tutorial
3. Monitor error logs untuk 1-2 minggu setelah deployment
4. Gather user feedback dan improvement suggestions

---

*Last Updated: 2024 | Feature: Multiple Question Packages per Assignment*
