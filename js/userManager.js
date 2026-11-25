// Manajemen Akun Pengguna, Autentikasi, Pelaporan, dan Pembayaran Portal Penghuni
import { dataKamar, dataPenghuni, getAvailableRooms, getRoomById, saveData } from "./dataManager.js";

export const USERS_STORAGE_KEY = "alfianKost.registeredUsers.v1";
export const CURRENT_USER_KEY = "alfianKost.currentUser.v1";
export const COMPLAINTS_STORAGE_KEY = "alfianKost.complaints.v1";
export const USER_PAYMENTS_STORAGE_KEY = "alfianKost.userPayments.v1";

const initialUsers = [
    {
        id: 1,
        nama: "Budi Santoso",
        email: "budi@kost.com",
        telepon: "0812-3456-7890",
        password: "password123",
        kamarId: 101,
        tglDaftar: "2026-11-01",
        status: "Aktif",
        role: "penghuni",
        alamat: "Jl. Merdeka No. 45, Bandung",
        kontakDarurat: "Siti Rahma (Ibu) - 0813-2233-4455"
    },
    {
        id: 2,
        nama: "Rizky Pratama",
        email: "rizky@kost.com",
        telepon: "0812-9876-5432",
        password: "password123",
        kamarId: 101,
        tglDaftar: "2026-11-01",
        status: "Aktif",
        role: "penghuni",
        alamat: "Jl. Dago No. 12, Bandung",
        kontakDarurat: "Ahmad Pratama (Ayah) - 0812-1122-3344"
    },
    {
        id: 3,
        nama: "Dewi Sartika",
        email: "dewi@kost.com",
        telepon: "0857-1122-3344",
        password: "password123",
        kamarId: 102,
        tglDaftar: "2026-11-15",
        status: "Menunggu ACC",
        role: "penghuni",
        alamat: "Jl. Cihampelas No. 80, Bandung",
        kontakDarurat: "Bambang (Kakak) - 0857-9988-7766"
    }
];

const initialComplaints = [
    {
        id: 1,
        userId: 1,
        namaPenghuni: "Budi Santoso",
        kamarId: 101,
        kategori: "Kerusakan Air / Pipa",
        judul: "Keran wastafel bocor dan menetes",
        urgensi: "Normal",
        deskripsi: "Keran wastafel kamar mandi tidak bisa ditutup rapat sehingga air terus menetes.",
        tanggal: "2026-11-10",
        status: "Sedang Dikerjakan",
        tanggapanAdmin: "Teknisi dijadwalkan datang memperbaiki hari Rabu pukul 10:00 WIB."
    },
    {
        id: 2,
        userId: 1,
        namaPenghuni: "Budi Santoso",
        kamarId: 101,
        kategori: "Kelistrikan & Lampu",
        judul: "Lampu balkon lantai 1 redup",
        urgensi: "Normal",
        deskripsi: "Lampu penerangan di lorong depan kamar 101 kadang berkedip.",
        tanggal: "2026-11-05",
        status: "Selesai",
        tanggapanAdmin: "Bohlam lampu LED lorong sudah diganti baru."
    }
];

const initialUserPayments = [
    {
        id: 1,
        userId: 1,
        kamarId: 101,
        invoiceNo: "INV-202611-101",
        periode: "November 2026",
        nominal: 1500000,
        metode: "QRIS",
        tanggal: "2026-11-01",
        status: "Lunas",
        catatan: "Pembayaran sewa bulan November 2026 via QRIS BCA",
        bukti: "qris_bca_success.png"
    }
];

function readStorage(key, defaultValue) {
    try {
        const stored = localStorage.getItem(key);
        if (stored) {
            return JSON.parse(stored);
        }
    } catch (e) {
        console.warn(`Gagal membaca ${key} dari storage:`, e);
    }
    return JSON.parse(JSON.stringify(defaultValue));
}

function writeStorage(key, value) {
    try {
        localStorage.setItem(key, JSON.stringify(value));
    } catch (e) {
        console.error(`Gagal menyimpan ${key} ke storage:`, e);
    }
}

// 1. DATA PENGGUNA
export function getUsers() {
    return readStorage(USERS_STORAGE_KEY, initialUsers);
}

export function saveUsers(users) {
    writeStorage(USERS_STORAGE_KEY, users);
    window.dispatchEvent(new CustomEvent("kost:users-changed", { detail: users }));
}

export function getUserById(id) {
    return getUsers().find(u => Number(u.id) === Number(id)) || null;
}

export function getUserByEmail(email) {
    const cleanEmail = String(email || "").trim().toLowerCase();
    return getUsers().find(u => u.email.trim().toLowerCase() === cleanEmail) || null;
}

// 2. REGISTRASI PENGHUNI BARU (STATUS: Menunggu ACC)
export function registerResidentUser({ nama, telepon, email, password, kamarId, alamat = "", kontakDarurat = "", catatan = "" }) {
    const cleanNama = String(nama || "").trim();
    const cleanTelepon = String(telepon || "").trim();
    const cleanEmail = String(email || "").trim().toLowerCase();
    const cleanPassword = String(password || "").trim();
    const cleanKamarId = Number(kamarId);

    if (!cleanNama) throw new Error("Nama lengkap wajib diisi.");
    if (!cleanTelepon) throw new Error("Nomor HP / WhatsApp yang dapat dihubungi wajib diisi.");
    if (!cleanEmail || !cleanEmail.includes("@")) throw new Error("Alamat email tidak valid.");
    if (cleanPassword.length < 6) throw new Error("Kata sandi minimal 6 karakter.");
    if (!cleanKamarId) throw new Error("Pilih kamar yang ingin ditempati.");

    const users = getUsers();
    if (users.some(u => u.email.trim().toLowerCase() === cleanEmail)) {
        throw new Error("Email ini sudah terdaftar. Silakan login atau gunakan email lain.");
    }

    const newId = Math.max(0, ...users.map(u => Number(u.id) || 0)) + 1;
    const nowStr = new Date().toISOString().split("T")[0];

    const newUser = {
        id: newId,
        nama: cleanNama,
        email: cleanEmail,
        telepon: cleanTelepon,
        password: cleanPassword,
        kamarId: cleanKamarId,
        tglDaftar: nowStr,
        status: "Menunggu ACC",
        role: "penghuni",
        alamat: alamat.trim(),
        kontakDarurat: kontakDarurat.trim(),
        catatan: catatan.trim()
    };

    users.push(newUser);
    saveUsers(users);

    // Sinkronkan juga sebagai calon penghuni berstatus "Menunggu ACC" di dataPenghuni
    const existingResident = dataPenghuni.find(p => p.email && p.email.toLowerCase() === cleanEmail);
    if (!existingResident) {
        dataPenghuni.push({
            id: Math.max(0, ...dataPenghuni.map(p => Number(p.id) || 0)) + 1,
            nama: cleanNama,
            email: cleanEmail,
            kontak: cleanTelepon,
            kamarId: cleanKamarId,
            tglBayar: "",
            tglMasuk: nowStr,
            tglHabis: "",
            durasiSewa: 1,
            status: "Menunggu ACC",
            userId: newId
        });
        saveData();
    }

    return newUser;
}

// 3. AUTENTIKASI (LOGIN & LOGOUT)
export function login({ email, password, role = "penghuni" }) {
    const cleanEmail = String(email || "").trim().toLowerCase();
    const cleanPassword = String(password || "").trim();

    if (!cleanEmail || !cleanPassword) {
        throw new Error("Email dan kata sandi wajib diisi.");
    }

    // Role Admin
    if (role === "admin") {
        if ((cleanEmail === "admin@kost.com" || cleanEmail === "admin") && cleanPassword === "admin123") {
            const adminUser = {
                id: 999,
                nama: "Alfian Admin",
                email: "admin@kost.com",
                role: "admin",
                telepon: "0812-0000-9999"
            };
            writeStorage(CURRENT_USER_KEY, adminUser);
            return { status: "sukses", role: "admin", user: adminUser };
        }
        throw new Error("Email atau kata sandi admin tidak sesuai.");
    }

    // Role Penghuni
    const user = getUserByEmail(cleanEmail);
    if (!user || user.password !== cleanPassword) {
        throw new Error("Email atau kata sandi penghuni tidak cocok.");
    }

    if (user.status === "Menunggu ACC") {
        // User belum di-ACC admin
        writeStorage(CURRENT_USER_KEY, user);
        return {
            status: "pending",
            message: "Akun Anda sedang menunggu persetujuan (ACC) dari admin.",
            user
        };
    }

    if (user.status === "Ditolak") {
        throw new Error("Permohonan pendaftaran akun Anda ditolak oleh admin. Silakan hubungi pengelola.");
    }

    // Status Aktif
    writeStorage(CURRENT_USER_KEY, user);
    return {
        status: "sukses",
        role: "penghuni",
        user
    };
}

export function getCurrentUser() {
    return readStorage(CURRENT_USER_KEY, null);
}

export function setCurrentUser(user) {
    writeStorage(CURRENT_USER_KEY, user);
}

export function logout() {
    localStorage.removeItem(CURRENT_USER_KEY);
    // Opsional panggil backend logout
    fetch("logout.php", { method: "POST" }).catch(() => {});
}

// 4. ACC PENDAFTARAN OLEH ADMIN
export function approveUser(userId) {
    const users = getUsers();
    const user = users.find(u => Number(u.id) === Number(userId));
    if (!user) throw new Error("Pengguna tidak ditemukan.");

    user.status = "Aktif";
    saveUsers(users);

    // Update status di dataPenghuni juga
    let resident = dataPenghuni.find(p => (p.userId && Number(p.userId) === Number(userId)) || (p.email && p.email.toLowerCase() === user.email.toLowerCase()));
    const now = new Date();
    const nextMonth = new Date(now);
    nextMonth.setMonth(nextMonth.getMonth() + 1);

    const pad = n => String(n).padStart(2, "0");
    const tglBayar = `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
    const tglHabis = `${nextMonth.getFullYear()}-${pad(nextMonth.getMonth() + 1)}-${pad(nextMonth.getDate())}`;

    if (resident) {
        resident.status = "Aktif";
        resident.kamarId = Number(user.kamarId);
        resident.tglBayar = resident.tglBayar || tglBayar;
        resident.tglHabis = resident.tglHabis || tglHabis;
    } else {
        resident = {
            id: Math.max(0, ...dataPenghuni.map(p => Number(p.id) || 0)) + 1,
            nama: user.nama,
            email: user.email,
            kontak: user.telepon,
            kamarId: Number(user.kamarId),
            tglBayar,
            tglMasuk: user.tglDaftar || tglBayar,
            tglHabis,
            durasiSewa: 1,
            status: "Aktif",
            userId: user.id
        };
        dataPenghuni.push(resident);
    }

    saveData();

    // Jika pengguna yang di-ACC sedang membuka halaman status di session yang sama
    const cur = getCurrentUser();
    if (cur && Number(cur.id) === Number(userId)) {
        cur.status = "Aktif";
        setCurrentUser(cur);
    }

    return user;
}

export function rejectUser(userId) {
    const users = getUsers();
    const user = users.find(u => Number(u.id) === Number(userId));
    if (!user) throw new Error("Pengguna tidak ditemukan.");

    user.status = "Ditolak";
    saveUsers(users);

    const resident = dataPenghuni.find(p => (p.userId && Number(p.userId) === Number(userId)) || (p.email && p.email.toLowerCase() === user.email.toLowerCase()));
    if (resident) {
        resident.status = "Ditolak";
        saveData();
    }

    return user;
}

export function getPendingUsers() {
    return getUsers().filter(u => u.status === "Menunggu ACC");
}

// 5. UPDATE PROFIL PENGHUNI
export function updateUserProfile(userId, { nama, telepon, email, alamat, kontakDarurat }) {
    const users = getUsers();
    const user = users.find(u => Number(u.id) === Number(userId));
    if (!user) throw new Error("Pengguna tidak ditemukan.");

    if (nama) user.nama = String(nama).trim();
    if (telepon) user.telepon = String(telepon).trim();
    if (email) user.email = String(email).trim().toLowerCase();
    if (alamat !== undefined) user.alamat = String(alamat).trim();
    if (kontakDarurat !== undefined) user.kontakDarurat = String(kontakDarurat).trim();

    saveUsers(users);

    // Update currentUser jika id sama
    const cur = getCurrentUser();
    if (cur && Number(cur.id) === Number(userId)) {
        Object.assign(cur, user);
        setCurrentUser(cur);
    }

    // Update di dataPenghuni
    const resident = dataPenghuni.find(p => (p.userId && Number(p.userId) === Number(userId)) || (p.email && p.email.toLowerCase() === user.email.toLowerCase()));
    if (resident) {
        if (nama) resident.nama = user.nama;
        if (telepon) resident.kontak = user.telepon;
        if (email) resident.email = user.email;
        saveData();
    }

    return user;
}

export function updateUserPassword(userId, oldPassword, newPassword) {
    const users = getUsers();
    const user = users.find(u => Number(u.id) === Number(userId));
    if (!user) throw new Error("Pengguna tidak ditemukan.");

    if (user.password !== oldPassword) {
        throw new Error("Kata sandi lama tidak cocok.");
    }
    if (!newPassword || newPassword.length < 6) {
        throw new Error("Kata sandi baru minimal 6 karakter.");
    }

    user.password = newPassword;
    saveUsers(users);

    const cur = getCurrentUser();
    if (cur && Number(cur.id) === Number(userId)) {
        cur.password = newPassword;
        setCurrentUser(cur);
    }

    return true;
}

// 6. PELAPORAN MASALAH / KELUHAN
export function getComplaints(userId = null) {
    const all = readStorage(COMPLAINTS_STORAGE_KEY, initialComplaints);
    if (userId) {
        return all.filter(c => Number(c.userId) === Number(userId));
    }
    return all;
}

export function saveComplaints(complaints) {
    writeStorage(COMPLAINTS_STORAGE_KEY, complaints);
    window.dispatchEvent(new CustomEvent("kost:complaints-changed", { detail: complaints }));
}

export function addComplaint({ userId, namaPenghuni, kamarId, kategori, judul, urgensi = "Normal", deskripsi, foto = "" }) {
    if (!judul || !judul.trim()) throw new Error("Judul keluhan wajib diisi.");
    if (!deskripsi || !deskripsi.trim()) throw new Error("Uraian masalah wajib diisi.");

    const complaints = getComplaints();
    const newId = Math.max(0, ...complaints.map(c => Number(c.id) || 0)) + 1;
    const nowStr = new Date().toISOString().split("T")[0];

    const complaint = {
        id: newId,
        userId: Number(userId),
        namaPenghuni: String(namaPenghuni).trim(),
        kamarId: Number(kamarId),
        kategori: kategori || "Umum",
        judul: judul.trim(),
        urgensi: urgensi === "Mendesak" ? "Mendesak" : "Normal",
        deskripsi: deskripsi.trim(),
        foto: foto || "",
        tanggal: nowStr,
        status: "Menunggu",
        tanggapanAdmin: "Laporan telah diterima dan sedang dalam antrean pemeriksaan."
    };

    complaints.unshift(complaint);
    saveComplaints(complaints);
    return complaint;
}

// 7. PEMBAYARAN SEWA (QRIS)
export function getUserPayments(userId) {
    const all = readStorage(USER_PAYMENTS_STORAGE_KEY, initialUserPayments);
    if (userId) {
        return all.filter(p => Number(p.userId) === Number(userId));
    }
    return all;
}

export function saveUserPayments(payments) {
    writeStorage(USER_PAYMENTS_STORAGE_KEY, payments);
    window.dispatchEvent(new CustomEvent("kost:payments-changed", { detail: payments }));
}

export function addPayment({ userId, kamarId, invoiceNo, periode, nominal, metode = "QRIS", tanggal, catatan = "", bukti = "" }) {
    const payments = getUserPayments();
    const newId = Math.max(0, ...payments.map(p => Number(p.id) || 0)) + 1;
    const nowStr = tanggal || new Date().toISOString().split("T")[0];

    const payment = {
        id: newId,
        userId: Number(userId),
        kamarId: Number(kamarId),
        invoiceNo: invoiceNo || `INV-${nowStr.replace(/-/g, "").slice(0, 6)}-${kamarId}`,
        periode: periode || "Bulan Berjalan",
        nominal: Number(nominal) || 1500000,
        metode: metode || "QRIS",
        tanggal: nowStr,
        status: "Lunas",
        catatan: catatan.trim() || `Pembayaran sewa via ${metode}`,
        bukti: bukti || "qris_payment.png"
    };

    payments.unshift(payment);
    saveUserPayments(payments);
    return payment;
}
