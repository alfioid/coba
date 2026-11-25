# TEST_PLAN.md

## Tujuan
Dokumen ini berisi rencana pengujian (test plan) untuk aplikasi web yang dibangun pada **Laragon 5**. Fokus utama meliputi:
- Autentikasi dan otorisasi (role)
- Operasi CRUD
- Validasi input
- Penanganan error API
- Responsifitas tampilan (layout)
- Audit kualitas (Lighthouse)
- Deployment ke lingkungan staging/production

## Daftar Test Case
| TC ID | Deskripsi | Langkah | Expected Result | Actual Result (diisi setelah eksekusi) |
|-------|-----------|---------|----------------|----------------------------------------|
| TC-01 | Login dengan kredensial valid | Masuk ke halaman login, isi username & password yang valid, klik **Login** | HTTP 200, diarahkan ke dashboard, token diterima | |
| TC-02 | Login dengan password salah | Masuk ke halaman login, isi username yang valid dan password salah, klik **Login** | HTTP 401, pesan error generik "Invalid credentials" | |
| TC-03 | Akses halaman admin tanpa hak admin (viewer) | Login sebagai user dengan role **viewer**, coba akses URL `/admin` atau lakukan **DELETE** pada resource | HTTP 403, tidak ada akses, UI menampilkan pesan "Forbidden" | |
| TC-04 | Submit form alat dengan nama kosong | Pada halaman *Tambah Alat*, biarkan field **Nama Alat** kosong, submit | HTTP 422, respons berisi field error "Nama Alat tidak boleh kosong" | |
| TC-05 | Simulasi kegagalan basis data API | Matikan service database (misalnya stop MySQL) lalu panggil endpoint API yang membutuhkan DB | HTTP 500, UI menampilkan pesan error generik "Terjadi kesalahan pada server" | |
| TC-06 | Layout pada viewport 320px (mobile) | Buka aplikasi di browser, ubah viewport menjadi 320px width | Tidak ada horizontal scroll, semua elemen menyesuaikan (responsive) | |
| TC-07 | Registrasi user baru dengan email duplikat | Registrasi dengan email yang sudah terdaftar sebelumnya | HTTP 409, pesan "Email sudah terdaftar" | |
| TC-08 | Update profil dengan data tidak valid (email format salah) | Isi form profil dengan `invalid-email` dan submit | HTTP 422, field error pada email | |
| TC-09 | akses API tanpa token (unauthenticated) | Kirim request GET ke endpoint `/api/items` tanpa header Authorization | HTTP 401, pesan "Authentication required" | |
| TC-10 | CSRF protection test – submit form tanpa token | Kirim POST ke `/api/create` menggunakan curl tanpa cookie CSRF | HTTP 403, pesan "Invalid CSRF token" | |
| TC-11 | Pagination – minta halaman ke‑2 dengan `page=2` | Request GET `/api/items?page=2` | Respons berisi data halaman ke‑2, `meta.current_page = 2` | |
| TC-12 | Performance – halaman utama load < 2 detik pada koneksi 3G | Buka halaman utama dengan throttling 3G (Chrome DevTools) | Load time ≤ 2 s, tidak ada error JS | |

## Catatan Eksekusi
- **Baseline**: Jalankan semua test case di atas pada *localhost* (Laragon 5) dan simpan hasil aktual di bagian **Actual Result**.
- **Lighthouse Audit**: Lakukan audit pada halaman utama (`http://localhost`) menggunakan Chrome Lighthouse (tab *Audits*). Catat issue pada kategori *Accessibility* dan *Performance*.
- **Perbaikan**: Identifikasi minimal tiga issue, perbaiki, kemudian jalankan kembali audit untuk memastikan perbaikan berhasil.
- **Deployment**: Setelah semua test lulus, push kode ke repository remote, buat *branch* `staging` dan `production`. Deploy ke server staging/production yang dapat diakses dosen.
- **Production Hardening**:
  - Pastikan `display_errors = Off` pada `php.ini` atau `.htaccess`.
  - Semua secret (API key, DB password) disimpan di file `.env` yang tidak di‑commit (gunakan `.gitignore`).
  - Set `error_reporting = E_ALL & ~E_NOTICE & ~E_STRICT` pada production.

## Opsional CI Pipeline
Jika ingin menambahkan pipeline CI untuk syntax check PHP, simpan file berikut di `.github/workflows/ci.yml`.
```yaml
name: PHP Syntax Check
on: [push, pull_request]
jobs:
  lint:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.4'
      - name: PHP lint
        run: find . -name "*.php" -print0 | xargs -0 -n1 php -l
```

---
**Instruksi Selanjutnya**
1. Simpan file ini di direktori proyek (`c:/laragon/www/coba`).
2. Jalankan test case secara manual atau dengan framework testing (mis. PHPUnit) dan isi kolom **Actual Result**.
3. Lakukan audit Lighthouse dan perbaiki issue yang terdeteksi.
4. Deploy ke staging/production dan verifikasi konfigurasi production.
5. Tambahkan dua negative test yang belum ada (contoh: TC‑07 & TC‑10 sudah negative, Anda dapat menambah TC‑13 & TC‑14 bila diperlukan).

Silakan beri tahu saya bila Anda memerlukan contoh script automatisasi test (mis. PHPUnit) atau bantuan lebih lanjut untuk langkah‑langkah tertentu.

| TC-13 | Akses admin API dengan token kedaluwarsa | Kirim request ke `/api/admin` dengan JWT token yang sudah kadaluarsa | HTTP 401, pesan "Token expired" | |
| TC-14 | Upload file melebihi batas ukuran maksimum (5MB) | Pada form upload, pilih file >5MB dan submit | HTTP 413, pesan "Payload Too Large" | |

## Audit Issues & Perbaikan (before‑after)

| No. | Isu Kualitas | Dampak Masalah | Solusi | File yang Diperbaiki |
|-----|--------------|----------------|--------|----------------------|
| 1 | Kontras warna teks pada tombol **Submit** (ratio 3.2:1) | Pengguna dengan gangguan penglihatan sulit membaca tombol, menurunkan skor Accessibility. | Ubah warna teks menjadi `#ffffff` dan latar menjadi `#2563eb` sehingga rasio kontras menjadi 4.5:1. | `public/css/styles.css` |
| 2 | Gambar QR Code tidak lazy‑loaded | Gambar besar dimuat pada render pertama, menambah **First Contentful Paint** dan menurunkan skor Performance. | Tambahkan atribut `loading="lazy"` pada tag `<img>` yang menampilkan QR Code. | `user/pembayaran.php` |
| 3 | Skrip JavaScript `dashboard.js` dimuat secara sinkron | Memblokir rendering halaman, meningkatkan **Time to Interactive** dan menurunkan skor Performance. | Tambahkan `defer` pada tag `<script src="../js/dashboard.js"></script>` atau pindahkan ke akhir `<body>`. | `public/js/dashboard.js` |
| 4 | Tidak ada validasi CSRF pada form pembayaran | Risiko **Cross‑Site Request Forgery**, dapat dimanfaatkan oleh attacker untuk melakukan pembayaran palsu. | Implementasikan token CSRF di session (`session_start(); $_SESSION['csrf_token'] = bin2hex(random_bytes(32));`) dan verifikasi pada request POST. | `user/pembayaran.php` |
| 5 | Gambar dekoratif tanpa `alt` attribute | Mengurangi pengalaman pembaca layar bagi pengguna tunanetra. | Tambahkan `alt=""` pada gambar dekoratif atau deskripsi yang relevan pada gambar bermakna. | `public/html/header.php` |

*Setelah memperbaiki masing‑masing isu di atas, jalankan kembali Lighthouse dan isi kolom *Nilai Akhir* serta lampirkan screenshot pada tabel ini.*
