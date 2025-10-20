
# URL Shortener - SaaS Style


URL Shortener berbasis PHP native dengan landing page profesional bergaya SaaS. User dapat membuat short URL tanpa login atau dengan login untuk fitur management lengkap.

## 🚀 Fitur









### Untuk Semua User (Tanpa Login):
- ✅ Membuat short URL langsung dari landing page
- ✅ Auto-generated short code (6 karakter)
- ✅ Instant redirect
- ✅ Gratis, tanpa registrasi

### Untuk Registered User (Dengan Login):
- ✅ Semua fitur di atas, PLUS:
- ✅ Custom short code (personalisasi URL)
- ✅ CRUD penuh (Create, Read, Update, Delete) untuk URL
- ✅ Dashboard management lengkap
- ✅ Statistik dan Analytics detail:
  - 📊 Total clicks per URL
  - 👥 Unique visitors
  - 📅 Clicks by date (grafik 30 hari)
  - 🔗 Top referrers
  - 📝 Recent clicks detail (IP, timestamp, referer)
- ✅ Edit URL destination kapan saja
- ✅ Aktivasi/deaktivasi URL
- ✅ Title/label untuk setiap URL

### Fitur Teknis:
- 🔐 Password hashing dengan bcrypt
- 🛡️ SQL injection protection (prepared statements)
- 🔒 Session-based authentication
- ✅ Input validation & sanitization
- 🎨 Responsive design untuk semua device
- ⚡ Clean URLs dengan .htaccess

## 📋 Requirement

- PHP 7.0 atau lebih tinggi
- MySQL/MariaDB
- Apache dengan mod_rewrite enabled
- Hosting dengan PHP dan MySQL support

## 🔧 Instalasi

1. **Upload semua file** ke hosting Anda (directory: `/home/vol4_2/infinityfree.com/if0_40199145/htdocs/`)

2. **Jalankan instalasi** dengan mengakses:
   ```
   http://am.ct.ws/install.php
   ```

3. **Login** dengan kredensial default:
   - Username: `admin`
   - Password: `admin123`

4. **Ubah password** setelah login pertama kali!

5. **Selesai!** Website siap digunakan di:
   ```
   http://am.ct.ws/
   ```

## 📁 Struktur File

```
/
├── config/
│   └── database.php          # Konfigurasi database
├── .htaccess                 # URL rewriting

├── index.php                 # Landing page + shortener form
├── redirect.php              # Handler untuk redirect short URLs
├── login.php                 # Halaman login
├── logout.php                # Logout handler





├── dashboard.php             # Dashboard management (user URLs only)
├── create.php                # Buat URL baru (with custom code)
├── edit.php                  # Edit URL (owner only)
├── delete.php                # Hapus URL (owner only)
├── analytics.php             # Statistik & analytics (owner only)
├── install.php               # Setup database
└── README.md                 # Dokumentasi
```


## 🗄️ Database Schema


Database: `if0_40199145_url_shortner`

- `users` - Menyimpan data admin users

## 💡 Cara Penggunaan

### Tabel `users`
```sql
id, username, password, email, created_at
```
### Membuat Short URL
### Tabel `urls`
```sql
id, short_code, original_url, title, user_id (NULL untuk anonymous),
status, created_at, updated_at
```

### Tabel `url_clicks`
```sql
id, url_id, ip_address, user_agent, referer, clicked_at
```


1. Login ke dashboard

### A. Tanpa Login (Anonymous)
3. Masukkan URL asli
1. Buka `http://am.ct.ws/`
2. Paste URL panjang Anda di form
3. Klik "Shorten"
4. Copy short URL yang dihasilkan
5. **Catatan**: URL anonymous tidak bisa di-manage atau dilihat statistiknya

### B. Dengan Login (Registered User)

#### Login
1. Klik "Login / Manage URLs" di navigasi
2. Masukkan username dan password
3. Akses dashboard

#### Membuat Short URL dengan Custom Code
4. (Opsional) Masukkan title dan custom code
5. Klik "Create Short URL"




3. Masukkan:
   - **Original URL** (wajib)
   - **Title** (opsional) - untuk identifikasi
   - **Custom Short Code** (opsional) - misal: "promo2024"
4. Klik "Create Short URL"
5. URL akan tersimpan di akun Anda dan bisa di-manage
1. Di dashboard, klik tombol "View" pada URL yang ingin dilihat


#### Melihat Analytics
   - Unique visitors
   - Clicks by date





   - Total clicks & unique visitors
   - Grafik clicks per hari (30 hari terakhir)
   - Top 10 referrer sources
   - 50 recent clicks terakhir dengan detail
1. Klik tombol "Edit" pada URL yang ingin diubah




#### Mengubah URL
1. Klik tombol "Edit" pada URL
2. Ubah:
   - Original URL (redirect destination)
   - Title
   - Status (active/inactive)

4. **Catatan**: Short code tidak bisa diubah
1. Klik tombol "Delete" pada URL yang ingin dihapus



#### Menghapus URL
1. Klik tombol "Delete"
## ⚙️ Konfigurasi

3. URL dan semua data analytics akan terhapus permanent
### Mengubah Base URL
## 🎨 Landing Page Sections

### 1. Hero Section
- Judul besar dan tagline
- Form shortener langsung (no login required)
- CTA untuk login

### 2. Features Section
- 6 keunggulan utama dengan icons
- Penjelasan fitur lengkap

### 3. Stats Section
- Total URLs shortened
- Total clicks
- Uptime percentage

### 4. CTA Section
- Call-to-action untuk register/login
- Quick access ke shortener form


Edit file `config/database.php` dan ubah:
```php


Edit file `config/database.php`:

### Koneksi Database

Sudah dikonfigurasi sesuai server.txt:
- Host: sql110.infinityfree.com

- Password: 12rizqi3




```php
define('DB_HOST', 'sql110.infinityfree.com');
define('DB_USER', 'if0_40199145');
define('DB_PASS', '12rizqi3');
define('DB_NAME', 'if0_40199145_url_shortner');
```
- Password di-hash menggunakan bcrypt

## 🔒 Security Features
- Session management untuk authentication




- **Password**: Bcrypt hashing (cost: 10)
- **SQL Injection**: Prepared statements untuk semua queries
- **XSS Protection**: htmlspecialchars() untuk output
- **Session**: Secure session management
- **Access Control**: User hanya bisa manage URL miliknya sendiri
- **Foreign Key**: CASCADE delete untuk data consistency
Setiap kali short URL diklik, sistem akan mencatat:
- IP Address pengunjung
- User Agent (browser/device)





Setiap kali short URL diklik, sistem otomatis mencatat:
- ✅ IP Address pengunjung
- ✅ User Agent (browser/device info)
- ✅ Referer (dari website mana link diklik)
- ✅ Timestamp (waktu akses)
- Chrome (Latest)

Data ini hanya bisa dilihat oleh pemilik URL (jika registered user).
- Safari (Latest)





## 🌐 URL Structure
Free to use and modify.
- **Landing Page**: `http://am.ct.ws/`
- **Short URL**: `http://am.ct.ws/{short_code}`
- **Dashboard**: `http://am.ct.ws/dashboard.php`
- **Login**: `http://am.ct.ws/login.php`
- **Analytics**: `http://am.ct.ws/analytics.php?id={url_id}`

## 🎯 User Flow

### Flow 1: Quick Shortening (No Login)
```
Landing Page → Input URL → Get Short Link → Done
```

### Flow 2: Managed URLs (With Login)
```
Landing Page → Login → Dashboard → Create URL (+ Custom Code)
→ Manage URLs → View Analytics → Edit/Delete
```

## 📱 Browser Support

- ✅ Chrome (Latest)
- ✅ Firefox (Latest)
- ✅ Safari (Latest)
- ✅ Edge (Latest)
- ✅ Mobile browsers (iOS Safari, Chrome Mobile)

## 🚀 Performance

- **Redirect Speed**: < 100ms
- **Page Load**: Optimized CSS (inline)
- **Database**: Indexed columns untuk query cepat
- **Caching**: Browser caching untuk static assets


## 👨‍💻 Support

Jika ada pertanyaan atau masalah, silakan hubungi administrator.

Jika ada pertanyaan atau masalah:
1. Cek dokumentasi ini terlebih dahulu
2. Pastikan database sudah di-setup dengan benar
3. Cek error logs di hosting panel

## 🔄 Update Notes

### v2.0 (Current)
- ✨ Landing page bergaya SaaS
- ✨ Anonymous URL shortening (no login)
- ✨ User-specific URL management
- ✨ Improved security (user ownership validation)
- ✨ Better UI/UX dengan animasi
- ✨ Responsive navigation

### v1.0
- Initial release with basic CRUD
- Dashboard dan analytics
- Login system