# Integrasi database Alfian Kost

## Kondisi integrasi

Landing page membaca katalog kamar dari `GET /api/public-kamar.php`. Portal admin dan penghuni memakai sesi PHP dan endpoint bersama `api/portal.php`; perubahan profil, verifikasi, kamar/fasilitas, laporan, pembayaran, dan keuangan disimpan ke tabel MySQL yang sama. Permintaan perubahan mewajibkan token CSRF sesi.

Data kontak, galeri, dan fasilitas umum pada landing page masih berupa konten HTML. Pembayaran QRIS tetap memerlukan penerimaan dana melalui kanal pembayaran di luar aplikasi; portal hanya menyimpan konfirmasi dan menunggu verifikasi admin.

## Kolom katalog tambahan

API tetap berfungsi dengan kolom tabel kamar yang ada. Untuk menambahkan ukuran kamar, foto, dan pilihan menyembunyikan kamar dari katalog publik, jalankan satu kali file `migrations/001_public_catalog.sql` di phpMyAdmin pada database `pemweb_obe`. Setelah itu isi `ukuran_kamar` dan `foto_url` sesuai data kamar; kolom `jenis_penghuni` tetap opsional untuk kebutuhan internal.

Untuk portal admin/penghuni, jalankan `migrations/002_shared_portal.sql` setelah setup database dan migrasi katalog. Migrasi ini menambahkan tanggal mulai daftar, kolom bukti transfer, status penghuni keluar, memastikan kolom katalog ada, membuat master fasilitas, serta menetapkan pembayaran baru sebagai `Menunggu Verifikasi`. Jalankan migrasi satu kali pada database `pemweb_obe`; pastikan koneksi MySQL aktif sebelum memakai portal. Bukti transfer disimpan di `storage/payment-proofs` dan hanya dapat diakses melalui endpoint terautentikasi. Pastikan folder aplikasi dapat ditulisi oleh PHP. Jangan jalankan ulang `database/setup_db.php` pada database berisi data penting, karena skrip tersebut juga menyinkronkan akun demo.

Akun penghuni yang dihapus dari daftar dinonaktifkan dengan status `Keluar`; riwayat pembayaran dan pelaporan tetap tersimpan untuk keperluan audit. Kamar yang masih memiliki akun penghuni atau riwayat penghuni, pembayaran, maupun pelaporan tidak dapat dihapus.

Jalankan situs melalui Laragon, misalnya `http://localhost/pemweb-obe/`; jangan buka `index.html` langsung dengan skema `file://`, karena browser tidak dapat memanggil endpoint API relatif dari file lokal.

## Entitas yang disarankan

- **Pengelola/User**: akun admin, nama, email, hash kata sandi, dan peran. Kata sandi disimpan sebagai hash, bukan teks biasa.
- **ProfilKost**: nama, alamat, kontak/WhatsApp, deskripsi, dan informasi publik kost.
- **Kamar**: nomor unik, tipe, jenis penghuni, lantai, ukuran, harga bulanan, status, dan status publik.
- **Fasilitas**: nama, kategori (kamar/bersama), ikon, dan deskripsi. Hubungkan kamar dan fasilitas melalui **KamarFasilitas** agar tiap kamar memiliki fasilitas berbeda.
- **Penghuni**: identitas dasar dan kontak penghuni. Simpan hanya data pribadi yang memang diperlukan dan batasi aksesnya.
- **Sewa**: relasi penghuni-kamar, tanggal mulai/akhir, harga saat kontrak, status sewa. Gunakan tabel ini untuk riwayat sewa, bukan hanya status kamar.
- **Transaksi/Pembayaran**: tanggal, nominal, jenis masuk/keluar, kategori, keterangan, serta relasi opsional ke sewa/penghuni.
- **FotoKamar/Galeri** (opsional): path/URL, teks alternatif, urutan, dan relasi ke kamar atau profil kost.
- **Pengaduan** (opsional): penghuni, kamar, kategori, uraian, status, dan waktu pelaporan.

Relasi inti: `Kamar` ↔ `Fasilitas` adalah many-to-many; `Penghuni` memiliki banyak `Sewa`; `Kamar` memiliki riwayat banyak `Sewa`; `Sewa` dapat memiliki banyak `Transaksi/Pembayaran`; `Penghuni` dapat membuat banyak `Pengaduan`.
