
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