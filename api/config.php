<?php
// ============================================================
// Konfigurasi Backend Presensi YoriAPP (versi XAMPP / PHP+MySQL)
// Migrasi penuh dari Google Apps Script (Sheets + Drive + CacheService)
// ============================================================

// --- Koneksi Database ---
// Kalau dipakai di XAMPP lokal, ganti kembali ke:
//   DB_NAME = 'presensi_db', DB_USER = 'root', DB_PASS = 'k[01k[-yT0Z]rVgf'
// Versi di bawah ini sudah disesuaikan untuk HOSTING (rumahweb/cPanel).
define('DB_HOST', 'localhost');
define('DB_NAME', 'korh3495_presensi_db');
define('DB_USER', 'korh3495_yori');
define('DB_PASS', '@YorianJP94');

// --- Konfigurasi setara dengan konstanta di Apps Script ---
define('ADMIN_EMAIL', 'yoshiprakoso@gmail.com');
define('SESSION_EXPIRATION_MINUTES', 30);
define('MIN_FILE_SIZE', 20000); // bytes
define('ALLOWED_FILE_TYPES', ['image/jpeg', 'image/png', 'image/jpg']);
define('TIME_TOLERANCE_MINUTES', 2);
define('TIME_MANIPULATION_THRESHOLD', 5);
define('TIMEZONE', 'Asia/Jakarta');

// --- Alamat dasar aplikasi ---
// PENTING: sebelumnya APP_BASE_URL di-hardcode (mis. 'http://192.168.1.15/xampp_presensi'),
// sehingga kalau app dibuka lewat host lain (mis. 'http://localhost/xampp_presensi'),
// URL foto/dokumen yang tersimpan di database tetap memakai host lama yang hardcode
// -> browser gagal memuatnya ("Gambar tidak dapat ditampilkan").
// Sekarang base URL dideteksi otomatis dari request yang sedang berjalan (host + skema
// persis seperti yang dipakai browser/HP untuk mengakses aplikasi ini), jadi selalu cocok.
// Jika suatu saat butuh nilai tetap (mis. dipanggil dari CLI/cron tanpa request HTTP),
// override lewat konstanta FORCE_APP_BASE_URL di bawah ini.
// define('FORCE_APP_BASE_URL', 'http://192.168.1.15/xampp_presensi');

function appBaseUrl(): string {
    if (defined('FORCE_APP_BASE_URL')) return rtrim(FORCE_APP_BASE_URL, '/');
    if (!isset($_SERVER['HTTP_HOST'])) return 'http://localhost/xampp_presensi'; // fallback CLI

    $isHttps = (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
        (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') ||
        ($_SERVER['SERVER_PORT'] ?? '') == 443
    );
    $scheme = $isHttps ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];

    // Ambil folder aplikasi dari path script saat ini (mis. '/xampp_presensi'),
    // supaya tetap benar walau folder instalasi berbeda-beda.
    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    // Router API ada di dalam subfolder /api, jadi naik satu level ke root aplikasi
    if (basename($scriptDir) === 'api') {
        $scriptDir = dirname($scriptDir);
    }
    $scriptDir = rtrim($scriptDir, '/');

    return $scheme . '://' . $host . $scriptDir;
}
define('APP_BASE_URL', appBaseUrl());

// Folder penyimpanan foto presensi (menggantikan Google Drive folder)
define('UPLOAD_DIR', __DIR__ . '/../uploads/');
define('UPLOAD_URL_BASE', APP_BASE_URL . '/uploads/');

// Folder & URL bukti dukung presensi (upload dari panel admin)
define('BUKTI_DIR', __DIR__ . '/../uploads/');
define('BUKTI_URL_BASE', APP_BASE_URL . '/uploads/');

// Folder & URL dokumen PSW / ijin
define('PSW_DIR', __DIR__ . '/../psw/');
define('PSW_URL_BASE', APP_BASE_URL . '/psw/');

// Folder & URL arsip PDF
define('ARSIP_PDF_DIR', __DIR__ . '/../arsip_pdf/');
define('ARSIP_PDF_URL_BASE', APP_BASE_URL . '/arsip_pdf/');

// Folder backup CSV (menggantikan file backup di Drive)
define('BACKUP_DIR', __DIR__ . '/../backup/');

date_default_timezone_set(TIMEZONE);

/**
 * Perbaiki URL lama yang tersimpan di database dengan host hardcode lama
 * (mis. https://192.168.0.7/ypresensi/... atau http://192.168.1.15/...)
 * agar cocok dengan host yang sedang dipakai browser saat ini (APP_BASE_URL).
 * Tanpa ini, foto/dokumen yang tersimpan pakai IP/host device lain tetap gagal
 * ditampilkan kalau dibuka lewat host berbeda (mis. localhost).
 * Aman dipanggil berulang; kalau bukan URL yang dikenali, dikembalikan apa adanya.
 */
function fixLegacyUrl(?string $url): ?string {
    if (!$url || $url === '-' || $url === 'Tidak ada file') return $url;
    if (preg_match('~/(uploads|psw|arsip_pdf)/([^/?#]+)~', $url, $m)) {
        return APP_BASE_URL . '/' . $m[1] . '/' . $m[2];
    }
    return $url;
}

// --- Polyfill untuk hosting yang masih pakai PHP < 8.0 ---
// str_contains() baru tersedia bawaan mulai PHP 8.0. Kalau hosting masih
// PHP 7.x, tanpa ini akan muncul "Call to undefined function str_contains()".
if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}
if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool {
        return substr($haystack, 0, strlen($needle)) === $needle;
    }
}
if (!function_exists('str_ends_with')) {
    function str_ends_with(string $haystack, string $needle): bool {
        return $needle === '' || substr($haystack, -strlen($needle)) === $needle;
    }
}

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $pdo;
}