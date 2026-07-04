<?php
require_once __DIR__ . '/config.php';

// ============================================================
// --- HELPER UMUM (setara helper di code.gs) ---
// ============================================================

function normLembaga($s): string {
    $s = strtolower(trim((string)$s));
    return preg_replace('/\s+/', ' ', $s);
}

function fuzzyLembaga($s): string {
    return preg_replace('/[^a-z0-9]/', '', strtolower((string)$s));
}

function uuidv4(): string {
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

/** Menulis log aktivitas ke tabel ActivityLog (setara logActivity()). */
function logActivity(string $username, string $role, string $action, string $details = ''): void {
    try {
        $stmt = db()->prepare("INSERT INTO `activitylog` (`Timestamp`, `Username`, `Role`, `Action`, `Details`) VALUES (NOW(), :u, :r, :a, :d)");
        $stmt->execute(['u' => $username, 'r' => $role, 'a' => $action, 'd' => $details]);
    } catch (Exception $e) {
        error_log('Gagal menulis log aktivitas: ' . $e->getMessage());
    }
}

/**
 * Mengambil semua baris tabel sebagai array of objects, dengan `_rowIndex`
 * = primary key baris tsb (setara getSheetData() yang berbasis header sheet).
 * $pk adalah nama kolom primary key tabel (default 'id').
 */
function getSheetData(string $table, string $pk = 'id'): array {
    $stmt = db()->query("SELECT * FROM `$table` ORDER BY `$pk` ASC");
    $rows = $stmt->fetchAll();
    $result = [];
    foreach ($rows as $row) {
        $obj = ['_rowIndex' => $row[$pk]];
        foreach ($row as $k => $v) {
            if ($k === $pk) continue;
            // Kolom yang menyimpan URL foto/dokumen (mis. "Bukti Dukung", "File URL")
            // diperbaiki otomatis kalau host-nya beda dari APP_BASE_URL saat ini.
            if (is_string($v) && (str_contains($k, 'Bukti') || str_contains($k, 'URL') || str_contains($k, 'File'))) {
                $v = fixLegacyUrl($v);
            }
            $obj[$k] = $v;
        }
        $result[] = $obj;
    }
    return $result;
}

/** Mengambil daftar nama kolom sebuah tabel (setara getSheetHeaders()). */
function getSheetHeaders(string $table): array {
    $stmt = db()->query("SHOW COLUMNS FROM `$table`");
    $cols = [];
    foreach ($stmt->fetchAll() as $c) {
        if ($c['Field'] === 'id' || $c['Field'] === 'created_at') continue;
        $cols[] = $c['Field'];
    }
    return $cols;
}

/** Simpan file base64 (dengan/ tanpa prefix data URI) ke folder lokal. Setara uploadBuktiFoto()/dsb. */
function saveBase64File(string $base64Data, string $fileName, string $dir, string $urlBase): array {
    $parts = explode(',', $base64Data);
    $raw = count($parts) > 1 ? $parts[1] : $parts[0];
    $mime = 'image/jpeg';
    if (count($parts) > 1 && preg_match('/data:([^;]+);base64/', $parts[0], $m)) {
        $mime = $m[1];
    }
    $binary = base64_decode($raw);
    if ($binary === false) throw new Exception('Data base64 tidak valid.');

    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $ext = 'jpg';
    if (str_contains($mime, 'png')) $ext = 'png';
    elseif (str_contains($mime, 'pdf')) $ext = 'pdf';
    $safeName = pathinfo($fileName, PATHINFO_FILENAME) . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
    $fullPath = $dir . $safeName;
    file_put_contents($fullPath, $binary);

    return ['url' => $urlBase . $safeName, 'fileName' => $safeName, 'mime' => $mime];
}