# QuizB — Panduan Deployment ke cPanel Shared Hosting

## 📋 Prasyarat
- PHP 7.4+ (disarankan PHP 8.1+)
- MySQL 5.7+ atau MariaDB 10.3+
- Apache dengan mod_rewrite aktif
- PDO + PDO_MySQL extension aktif

---

## 🚀 Langkah Deploy

### 1. Upload File ke Hosting

Upload **seluruh isi folder** proyek ini ke folder `public_html` di cPanel File Manager atau via FTP:

```
public_html/
├── .htaccess
├── index.php
├── api.php
├── database.sql
├── config/
├── includes/
├── api/
├── assets/
└── pages/
```

> ⚠️ Pastikan `.htaccess` terupload (file tersembunyi, aktifkan "Show Hidden Files" di File Manager)

---

### 2. Buat Database MySQL

Di cPanel → **MySQL Databases**:
1. Buat database baru (misal: `quizb_db`)
2. Buat user baru (misal: `quizb_user`) dengan password kuat
3. Assign user ke database dengan **ALL PRIVILEGES**

---

### 3. Import Schema Database

Di cPanel → **phpMyAdmin**:
1. Pilih database yang baru dibuat
2. Klik tab **Import**
3. Upload file `database.sql`
4. Klik **Go**

---

### 4. Konfigurasi Database

Edit file `config/db.php`:

```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'nama_database_anda');   // ganti ini
define('DB_USER', 'nama_user_anda');       // ganti ini
define('DB_PASS', 'password_anda');        // ganti ini
```

> ⚠️ **JANGAN** commit file ini ke Git setelah diisi kredensial!

---

### 5. Tes Deployment

Buka browser → `https://yourdomain.com`

Cek:
- [ ] Halaman home tampil
- [ ] Login admin: `admin@quizb.my.id` / `admin123`
- [ ] Quiz bisa dimulai
- [ ] Submit quiz berhasil

---

## 🔐 Akun Default (Seed Data)

| Role  | Email                  | Password  |
|-------|------------------------|-----------|
| Admin | admin@quizb.my.id      | admin123  |
| User  | budi@example.com       | user123   |
| User  | sari@example.com       | user123   |

> ⚠️ **Segera ganti password default setelah deploy ke production!**

---

## 🛡️ Keamanan Production

Setelah deploy, lakukan:

1. **Ganti password seed** via Admin Panel → Pengguna
2. **Hapus atau rename** `database.sql` dari public folder
3. Pastikan folder `config/` dan `includes/` tidak accessible langsung (sudah diproteksi `.htaccess`)
4. Aktifkan HTTPS (Let's Encrypt via cPanel SSL/TLS)

---

## 🐛 Troubleshooting

### 404 saat navigate
→ Pastikan `mod_rewrite` aktif & `.htaccess` terupload

### Database connection failed
→ Cek `config/db.php`, pastikan kredensial benar

### API mengembalikan HTML bukan JSON
→ Pastikan request URL ke `api.php` bukan ke `index.php`

### CSRF error (403)
→ Clear cookies browser, login ulang

---

## 📁 Struktur Final

```
up.quizb.my.id/
├── .htaccess              ← Apache SPA routing
├── index.php              ← SPA shell (Alpine.js entry)
├── api.php                ← REST API router
├── database.sql           ← Schema + seed data
├── config/
│   └── db.php             ← PDO connection (isi kredensial!)
├── includes/
│   ├── auth.php           ← Session helpers
│   ├── csrf.php           ← CSRF protection
│   └── response.php       ← JSON response helpers
├── api/
│   ├── auth.php           ← Login/register/logout
│   ├── quiz.php           ← Quiz endpoints
│   ├── category.php       ← Category endpoints
│   ├── question.php       ← Question CRUD (admin)
│   ├── attempt.php        ← Attempt/result/history
│   ├── leaderboard.php    ← Leaderboard
│   ├── search.php         ← Realtime search
│   └── admin.php          ← Admin management
├── assets/
│   ├── css/app.css        ← Custom styles
│   └── js/
│       ├── app.js         ← Main Alpine.js app
│       ├── quiz-engine.js ← Quiz engine component
│       └── utils.js       ← API client & utilities
└── pages/
    ├── home.html
    ├── login.html
    ├── register.html
    ├── categories.html
    ├── quizzes.html
    ├── quiz-detail.html
    ├── quiz-engine.html
    ├── result.html
    ├── leaderboard.html
    ├── dashboard.html
    ├── history.html
    └── admin.html
```
