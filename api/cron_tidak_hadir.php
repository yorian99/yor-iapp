<?php
// ============================================================
// cron_tidak_hadir.php
// Script mandiri untuk dijalankan terjadwal (Windows Task Scheduler
// di XAMPP, atau crontab kalau di Linux/Mac) — TIDAK lewat HTTP,
// langsung include admin_functions.php dan panggil fungsinya.
//
// Taruh file ini SEJAJAR dengan admin.php & admin_functions.php,
// misal: C:\xampp\htdocs\e-presensi\api\cron_tidak_hadir.php
//
// Jadwalkan jam 20:00 (8 malam) setiap hari lewat Windows Task
// Scheduler, action: menjalankan php.exe dengan argumen file ini.
// Lihat instruksi lengkap di chat.
// ============================================================

require_once __DIR__ . '/admin_functions.php';

date_default_timezone_set('Asia/Jakarta');

$tanggal = date('Y-m-d');
$logFile = __DIR__ . '/log_tidak_hadir.txt';

try {
    $result = admProsesTidakHadirHarian(''); // '' = dijalankan sistem/cron, tanpa cek role
    $line = '[' . date('Y-m-d H:i:s') . "] " . ($result['success'] ? 'SUKSES' : 'GAGAL') . ": " . ($result['message'] ?? '') . PHP_EOL;
} catch (Throwable $e) {
    $line = '[' . date('Y-m-d H:i:s') . "] GAGAL (exception): " . $e->getMessage() . PHP_EOL;
}

file_put_contents($logFile, $line, FILE_APPEND);
echo $line;
