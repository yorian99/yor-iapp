<?php
require_once __DIR__ . '/config.php';

// ============================================================
// --- SESSION (menggantikan CacheService) ---
// ============================================================

function createSession(array $sessionData): string {
    $sessionId = bin2hex(random_bytes(16)) . '-' . bin2hex(random_bytes(2)) . '-' . bin2hex(random_bytes(2))
        . '-' . bin2hex(random_bytes(2)) . '-' . bin2hex(random_bytes(6));
    $sessionData['timestamp'] = round(microtime(true) * 1000);
    $stmt = db()->prepare("INSERT INTO sessions (session_id, data) VALUES (:id, :data)");
    $stmt->execute(['id' => $sessionId, 'data' => json_encode($sessionData)]);
    return $sessionId;
}

function getSessionData(?string $sessionId) {
    if (!$sessionId) return null;
    $stmt = db()->prepare("SELECT data, UNIX_TIMESTAMP(updated_at) AS updated_at FROM sessions WHERE session_id = :id");
    $stmt->execute(['id' => $sessionId]);
    $row = $stmt->fetch();
    if (!$row) return null;

    $ageMinutes = (time() - $row['updated_at']) / 60;
    if ($ageMinutes > SESSION_EXPIRATION_MINUTES) {
        $del = db()->prepare("DELETE FROM sessions WHERE session_id = :id");
        $del->execute(['id' => $sessionId]);
        return null;
    }

    $touch = db()->prepare("UPDATE sessions SET updated_at = NOW() WHERE session_id = :id");
    $touch->execute(['id' => $sessionId]);

    return json_decode($row['data'], true);
}

function validateSession(?string $sessionId): array {
    $data = getSessionData($sessionId);
    if (!$data) {
        throw new Exception("Sesi login tidak valid. Mohon login kembali.");
    }
    return $data;
}

function logoutSession(?string $sessionId): bool {
    try {
        if ($sessionId) {
            $stmt = db()->prepare("DELETE FROM sessions WHERE session_id = :id");
            $stmt->execute(['id' => $sessionId]);
        }
        return true;
    } catch (Exception $e) {
        error_log('Error in logoutSession: ' . $e->getMessage());
        return false;
    }
}

// ============================================================
// --- LOGIN & RESET PASSWORD ---
// Tabel `db` kolom: `Username (MP/ID)`, `Password`, `Name Lembaga`,
// `Name Pegawai`, `Latitude`, `Longitude`, `Radius (m)`, `Kode Verifikasi`
// ============================================================

function verifyLogin(?string $username, ?string $password): array {
    try {
        $stmt = db()->prepare("SELECT * FROM `db` WHERE `Username (MP/ID)` = :u LIMIT 1");
        $stmt->execute(['u' => $username]);
        $row = $stmt->fetch();

        if ($row && $password === $row['Password']) {
            $sessionData = [
                'username' => $username,
                'namaLembaga' => $row['Name Lembaga'],
                'namaPegawai' => $row['Name Pegawai'],
                'lembagaLatitude' => $row['Latitude'] !== null ? (float)$row['Latitude'] : -7.4567,
                'lembagaLongitude' => $row['Longitude'] !== null ? (float)$row['Longitude'] : 111.1234,
                'lembagaRadius' => $row['Radius (m)'] !== null ? (int)$row['Radius (m)'] : 100,
            ];
            $sessionId = createSession($sessionData);

            $scriptUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://')
                . $_SERVER['HTTP_HOST'] . dirname(dirname($_SERVER['SCRIPT_NAME']));

            return [
                'success' => true,
                'message' => 'Login berhasil!',
                'sessionId' => $sessionId,
                'redirectUrl' => $scriptUrl . '/form.html?session=' . $sessionId,
                'namaLembaga' => $sessionData['namaLembaga'],
                'namaPegawai' => $sessionData['namaPegawai'],
                'lembagaLatitude' => $sessionData['lembagaLatitude'],
                'lembagaLongitude' => $sessionData['lembagaLongitude'],
                'lembagaRadius' => $sessionData['lembagaRadius'],
            ];
        }
        return ['success' => false, 'message' => 'Username/Password Salah!!! Jika Merasa sudah betul tapi tetap tidak bisa login Hub Admin'];
    } catch (Exception $e) {
        error_log('Error in verifyLogin: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Terjadi kesalahan saat verifikasi login: ' . $e->getMessage()];
    }
}

function resetPassword(?string $username, ?string $newPassword, ?string $verificationCode): array {
    try {
        if (!$username || !$newPassword || strlen(trim($newPassword)) < 4) {
            return ['success' => false, 'message' => 'Username dan kata sandi baru (minimal 4 karakter) wajib diisi.'];
        }
        if (!$verificationCode || trim($verificationCode) === '') {
            return ['success' => false, 'message' => 'Kode verifikasi wajib diisi.'];
        }

        $stmt = db()->prepare("SELECT * FROM `db` WHERE `Username (MP/ID)` = :u LIMIT 1");
        $stmt->execute(['u' => $username]);
        $row = $stmt->fetch();

        if (!$row) {
            return ['success' => false, 'message' => 'Username tidak ditemukan.'];
        }

        $rowVerificationCode = $row['Kode Verifikasi'];
        if ($rowVerificationCode === null || trim((string)$rowVerificationCode) === '') {
            return ['success' => false, 'message' => 'Kode verifikasi untuk akun ini belum diatur. Hubungi Admin.'];
        }
        if (trim($verificationCode) !== trim((string)$rowVerificationCode)) {
            return ['success' => false, 'message' => '❌ Kode verifikasi salah!'];
        }

        $upd = db()->prepare("UPDATE `db` SET `Password` = :p WHERE `Username (MP/ID)` = :u");
        $upd->execute(['p' => $newPassword, 'u' => $username]);

        return ['success' => true, 'message' => 'Kata sandi berhasil direset.'];
    } catch (Exception $e) {
        error_log('Error in resetPassword: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Terjadi kesalahan saat mereset kata sandi: ' . $e->getMessage()];
    }
}

// ============================================================
// --- CEK PRESENSI (halaman cekpresensi.html) ---
// ============================================================

function cekPresensi(?string $username, ?string $password): array {
    try {
        if (!$username || !$password) {
            return ['success' => false, 'code' => 'AUTH_FAILED', 'message' => 'Username dan password wajib diisi.'];
        }

        $stmt = db()->prepare("SELECT * FROM `db` WHERE `Username (MP/ID)` = :u LIMIT 1");
        $stmt->execute(['u' => $username]);
        $row = $stmt->fetch();

        if (!$row || $password !== $row['Password']) {
            return ['success' => false, 'code' => 'AUTH_FAILED', 'message' => 'Username/Password Salah!!! Jika Merasa sudah betul tapi tetap tidak bisa login Hub Admin'];
        }

        $today = date('Y-m-d');

        $stmt = db()->prepare(
            "SELECT * FROM `rekappresensi` WHERE `Nip` = :u AND `Jenis Presensi` = :p
             AND DATE(created_at) = :d ORDER BY id DESC LIMIT 1"
        );

        $stmt->execute(['u' => $username, 'p' => 'Datang', 'd' => $today]);
        $datangRow = $stmt->fetch();

        $stmt->execute(['u' => $username, 'p' => 'Pulang', 'd' => $today]);
        $pulangRow = $stmt->fetch();

        // Cek pengajuan ijin/PSW yang disetujui dan mencakup tanggal hari ini
        $ijin = null;
        try {
            $stmtIjin = db()->prepare(
                "SELECT * FROM `dokumenijin` WHERE `Nip` = :u AND `Status` = 'Disetujui'
                 AND STR_TO_DATE(`Tanggal Awal`, '%Y-%m-%d') <= :d AND STR_TO_DATE(`Tanggal Akhir`, '%Y-%m-%d') >= :d2
                 ORDER BY id DESC LIMIT 1"
            );
            $stmtIjin->execute(['u' => $username, 'd' => $today, 'd2' => $today]);
            $ijinRow = $stmtIjin->fetch();
            if ($ijinRow) {
                $ijin = ['jenis' => $ijinRow['Jenis Ijin'], 'ket' => $ijinRow['Keterangan'] ?? ''];
            }
        } catch (Exception $e) {
            // Tabel DokumenIjin mungkin belum ada / format tanggal berbeda — abaikan, badge ijin cukup tidak tampil
            $ijin = null;
        }

        return [
            'success' => true,
            'pegawai' => [
                'nama' => $row['Name Pegawai'] ?? '',
                'lembaga' => $row['Name Lembaga'] ?? '',
                'nip' => $row['Username (MP/ID)'] ?? '',
            ],
            'datang' => formatPresensiRecord($datangRow),
            'pulang' => formatPresensiRecord($pulangRow),
            'ijin' => $ijin,
        ];
    } catch (Exception $e) {
        error_log('Error in cekPresensi: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Terjadi kesalahan saat memeriksa presensi: ' . $e->getMessage()];
    }
}

function formatPresensiRecord($row): ?array {
    if (!$row) return null;

    // Ambil jam dari teks "Waktu" (mis. "Rabu, 2 Juli 2026 pukul 07.15.32"),
    // sudah dalam zona waktu Asia/Jakarta — JANGAN dari kolom created_at,
    // karena timestamp MySQL bisa memakai timezone server yang berbeda.
    $waktu = null;
    if (!empty($row['Waktu']) && preg_match('/pukul\s+(\d{1,2})\.(\d{2})\.(\d{2})/u', $row['Waktu'], $m)) {
        $waktu = sprintf('%02d:%02d:%02d', (int)$m[1], (int)$m[2], (int)$m[3]);
    } else {
        $waktu = date('H:i:s', strtotime($row['created_at']));
    }

    $bukti = $row['Bukti Dukung'] ?? null;
    if ($bukti === '-' || $bukti === 'Tidak ada file' || $bukti === '') $bukti = null;

    return [
        'waktu' => $waktu,
        'lat' => isset($row['Latitude']) && $row['Latitude'] !== '' && $row['Latitude'] !== '-' ? (float)$row['Latitude'] : null,
        'lon' => isset($row['Longitude']) && $row['Longitude'] !== '' && $row['Longitude'] !== '-' ? (float)$row['Longitude'] : null,
        'jarak' => isset($row['Jarak Absen']) && $row['Jarak Absen'] !== '' && $row['Jarak Absen'] !== '-' ? (float)str_replace(' m', '', $row['Jarak Absen']) : null,
        'ketRadius' => $row['Keterangan Radius'] ?? null,
        'bukti' => $bukti,
    ];
}

// ============================================================
// --- PRESENSI ---
// ============================================================

function uploadFile(array $form): array {
    try {
        $sessionData = validateSession($form['sessionId'] ?? null);

        $timeValidation = validateDeviceTime(
            $form['clientTime'] ?? null,
            $form['timeDrift'] ?? null,
            $form['lastPresensiTime'] ?? null,
            $sessionData
        );
        if (!$timeValidation['isValid']) {
            logBlockedAttempt($form, $sessionData, $timeValidation['message']);
        }

        $locationValid = validateLocation($form, $sessionData);
        $isOutsideRadius = $locationValid['response']['isOutsideRadius'];
        $distance = $locationValid['response']['distance'];
        $radius = $locationValid['response']['radius'];

        $isDuplicate = checkDuplicatePresensi($sessionData['username'], $form['presensi'] ?? '');
        if ($isDuplicate) {
            return ['success' => false, 'message' => '❌ Anda sudah melakukan presensi ' . ($form['presensi'] ?? '') . ' hari ini!'];
        }

        $fileData = processUploadedFile($form['file'] ?? null);
        return saveAttendanceData($sessionData, $form, $fileData, $isOutsideRadius, $distance, $radius);
    } catch (Exception $e) {
        error_log('Error in uploadFile: ' . $e->getMessage());
        return [
            'success' => false,
            'message' => '❌ Gagal merekam presensi: ' . $e->getMessage(),
            'errorDetails' => $e->getMessage(),
        ];
    }
}

function checkDuplicatePresensi(string $username, string $presensiType): bool {
    try {
        $todayStandard = date('Y-m-d');
        $stmt = db()->prepare(
            "SELECT created_at FROM `rekappresensi` WHERE `Nip` = :u AND `Jenis Presensi` = :p
             AND DATE(created_at) = :d ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute(['u' => $username, 'p' => $presensiType, 'd' => $todayStandard]);
        return (bool)$stmt->fetch();
    } catch (Exception $e) {
        error_log('Error in checkDuplicatePresensi: ' . $e->getMessage());
        return false;
    }
}

function validateDeviceTime($clientTime, $timeDrift, $lastPresensiTime, array $sessionData): array {
    $serverTime = time();
    $clientTs = is_numeric($clientTime) ? ((float)$clientTime) / 1000 : strtotime((string)$clientTime);

    if ($clientTs === false || $clientTs === null) {
        return ['isValid' => false, 'message' => 'Terindikasi Memanipulasi Jam Pada Perangkat, Waktu Pada Client Tidak Syncron Dengan Waktu Server'];
    }

    $timeDiff = abs($serverTime - $clientTs) / 60;
    if ($timeDiff > TIME_TOLERANCE_MINUTES) {
        return ['isValid' => false, 'message' => "Terdeteksi Memanipulasi Waktu Perangkat (Selisih Waktu Dengan Server: " . number_format($timeDiff, 2) . " menit)"];
    }

    if ($lastPresensiTime && $lastPresensiTime !== 'null') {
        $lastTs = is_numeric($lastPresensiTime) ? ((float)$lastPresensiTime) / 1000 : strtotime((string)$lastPresensiTime);
        if ($lastTs !== false && $lastTs !== null) {
            $timeSinceLast = abs($clientTs - $lastTs) / 60;
            $timeDriftNum = (float)($timeDrift ?? 0);
            if (abs($timeSinceLast - $timeDriftNum) > TIME_MANIPULATION_THRESHOLD) {
                return ['isValid' => false, 'message' => 'Deteksi perubahan waktu perangkat!'];
            }
        }
    }
    return ['isValid' => true];
}

function logBlockedAttempt(array $form, array $sessionData, string $reason): void {
    try {
        $now = date('Y-m-d H:i:s');
        $clientTs = is_numeric($form['clientTime'] ?? null) ? ((float)$form['clientTime']) / 1000 : strtotime((string)($form['clientTime'] ?? ''));
        $timeDiff = ($clientTs !== false && $clientTs !== null) ? abs(time() - $clientTs) / 60 : 0;
        $location = !empty($form['latitude']) ? ($form['latitude'] . ', ' . ($form['longitude'] ?? '')) : 'Tidak ada';

        $stmt = db()->prepare(
            "INSERT INTO blokir_presensi (timestamp, username, namaPegawai, waktuServer, waktuDevice, selisihMenit, alasanBlokir, lokasi)
             VALUES (:ts, :u, :np, :ws, :wd, :sm, :ab, :lok)"
        );
        $stmt->execute([
            'ts' => $now,
            'u' => $sessionData['username'] ?? '',
            'np' => $sessionData['namaPegawai'] ?? '',
            'ws' => $now,
            'wd' => (string)($form['clientTime'] ?? ''),
            'sm' => number_format($timeDiff, 2),
            'ab' => $reason,
            'lok' => $location,
        ]);
    } catch (Exception $e) {
        error_log('Error logging blocked attempt: ' . $e->getMessage());
    }
}

function validateLocation(array $form, array $sessionData): array {
    $userLat = (float)($form['latitude'] ?? 0);
    $userLon = (float)($form['longitude'] ?? 0);
    $lembagaLat = (float)($sessionData['lembagaLatitude'] ?? 0);
    $lembagaLon = (float)($sessionData['lembagaLongitude'] ?? 0);
    $radius = (float)($sessionData['lembagaRadius'] ?? 100);

    $distance = calculateDistance($userLat, $userLon, $lembagaLat, $lembagaLon);
    $isOutsideRadius = $distance > $radius;

    return [
        'valid' => !$isOutsideRadius,
        'response' => ['isOutsideRadius' => $isOutsideRadius, 'distance' => $distance, 'radius' => $radius],
    ];
}

function calculateDistance(float $lat1, float $lon1, float $lat2, float $lon2): float {
    $R = 6371e3;
    $phi1 = $lat1 * M_PI / 180;
    $phi2 = $lat2 * M_PI / 180;
    $deltaPhi = ($lat2 - $lat1) * M_PI / 180;
    $deltaLambda = ($lon2 - $lon1) * M_PI / 180;
    $a = sin($deltaPhi / 2) * sin($deltaPhi / 2) +
        cos($phi1) * cos($phi2) * sin($deltaLambda / 2) * sin($deltaLambda / 2);
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return $R * $c;
}

function processUploadedFile($fileInfo): array {
    if (!$fileInfo || empty($fileInfo['data'])) {
        throw new Exception("File tidak ditemukan");
    }

    $binary = base64_decode($fileInfo['data']);
    if ($binary === false) {
        throw new Exception("Gagal memproses file: base64 tidak valid");
    }

    $size = strlen($binary);
    if ($size < MIN_FILE_SIZE) {
        throw new Exception("File terlalu kecil (minimal 20KB) - mungkin bukan foto asli");
    }

    $type = strtolower($fileInfo['type'] ?? '');
    if (!in_array($type, ALLOWED_FILE_TYPES)) {
        throw new Exception("Hanya file JPEG/PNG yang diperbolehkan");
    }

    if (!is_dir(UPLOAD_DIR)) {
        mkdir(UPLOAD_DIR, 0755, true);
    }

    $ext = ($type === 'image/png') ? 'png' : 'jpg';
    $safeName = 'presensi_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $fullPath = UPLOAD_DIR . $safeName;

    if (file_put_contents($fullPath, $binary) === false) {
        throw new Exception('Gagal menyimpan file ke server: tidak dapat menulis file.');
    }

    $url = UPLOAD_URL_BASE . $safeName;
    return ['url' => $url, 'size' => $size, 'type' => $type];
}

function saveAttendanceData(array $sessionData, array $form, array $fileData, bool $isOutsideRadius, float $distance, float $radius): array {
    $now = new DateTime('now', new DateTimeZone(TIMEZONE));
    $hariIndo = ['Sunday' => 'Minggu', 'Monday' => 'Senin', 'Tuesday' => 'Selasa', 'Wednesday' => 'Rabu', 'Thursday' => 'Kamis', 'Friday' => 'Jumat', 'Saturday' => 'Sabtu'];
    $bulanIndo = [1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'];

    $hari = $hariIndo[$now->format('l')];
    $hariTanggal = $hari . ', ' . $now->format('d/m/Y');
    $formattedDate = $hari . ', ' . (int)$now->format('j') . ' ' . $bulanIndo[(int)$now->format('n')] . ' ' . $now->format('Y') . ' pukul ' . $now->format('H.i.s');

    $userLat = (float)($form['latitude'] ?? 0);
    $userLon = (float)($form['longitude'] ?? 0);

    $stmt = db()->prepare(
        "INSERT INTO `rekappresensi`
            (`Hari Tanggal`, `Waktu`, `Jenis Presensi`, `Nama Lembaga`, `Nama Pegawai`, `Nip`, `Bukti Dukung`, `Latitude`, `Longitude`, `Jarak Absen`, `Keterangan Radius`, `Keterangan Bukti`)
         VALUES (:ht, :wk, :jp, :nl, :np, :nip, :bd, :lat, :lon, :jarak, :kr, :kb)"
    );
    $stmt->execute([
        'ht' => $hariTanggal,
        'wk' => $formattedDate,
        'jp' => $form['presensi'] ?? '',
        'nl' => $sessionData['namaLembaga'] ?? '',
        'np' => $sessionData['namaPegawai'] ?? '',
        'nip' => $sessionData['username'] ?? '',
        'bd' => $fileData['url'] ?? 'Tidak ada file',
        'lat' => $userLat,
        'lon' => $userLon,
        'jarak' => number_format($distance, 2) . ' m',
        'kr' => $isOutsideRadius ? 'DI LUAR RADIUS' : 'DI DALAM RADIUS',
        'kb' => '-',
    ]);

    return [
        'success' => true,
        'message' => '✅ Absensi berhasil direkam.',
        'isOutsideRadius' => $isOutsideRadius,
        'distance' => number_format($distance, 2),
        'radius' => $radius,
        'namaLembaga' => $sessionData['namaLembaga'] ?? '',
        'lembagaLatitude' => $sessionData['lembagaLatitude'] ?? '',
        'lembagaLongitude' => $sessionData['lembagaLongitude'] ?? '',
        'userLatitude' => $userLat,
        'userLongitude' => $userLon,
        'timestamp' => $formattedDate,
        'fileUrl' => $fileData['url'] ?? null,
    ];
}

// ============================================================
// --- SETING WAKTU PRESENSI (dipakai form.html untuk validasi jam) ---
// ============================================================

function getWaktuPresensi(): array {
    $default = [
        'datang_mulai' => '06:00', 'datang_selesai' => '07:00',
        'pulang_mulai' => '15:00', 'pulang_selesai' => '16:30',
        'pulang_mulai_jumat' => '14:30', 'jam_terlambat' => '07:00',
    ];
    try {
        $stmt = db()->query(
            "SELECT `datang_mulai`,`datang_selesai`,`pulang_mulai`,`pulang_selesai`,`pulang_mulai_jumat`,`jam_terlambat`
             FROM `seting_waktu_presensi` WHERE id = 1 LIMIT 1"
        );
        $row = $stmt->fetch();
        if (!$row) return $default;
        foreach ($row as $k => $v) {
            $row[$k] = $v !== null ? substr((string)$v, 0, 5) : $default[$k];
        }
        return $row;
    } catch (Exception $e) {
        error_log('Error in getWaktuPresensi: ' . $e->getMessage());
        return $default;
    }
}

// ============================================================
// --- ADMIN & LAPORAN (opsional, tidak dipakai frontend utama) ---
// ============================================================

function getAttendanceReport(string $startDate, string $endDate): array {
    try {
        $stmt = db()->prepare("SELECT * FROM `rekappresensi` WHERE created_at BETWEEN :s AND :e ORDER BY id ASC");
        $stmt->execute(['s' => $startDate . ' 00:00:00', 'e' => $endDate . ' 23:59:59']);
        $rows = $stmt->fetchAll();
        $headers = ['Hari Tanggal', 'Waktu', 'Jenis Presensi', 'Nama Lembaga', 'Nama Pegawai', 'Nip', 'Bukti Dukung', 'Latitude', 'Longitude', 'Jarak Absen', 'Keterangan Radius', 'Keterangan Bukti'];
        $data = array_map(fn($r) => [
            $r['Hari Tanggal'], $r['Waktu'], $r['Jenis Presensi'], $r['Nama Lembaga'], $r['Nama Pegawai'], $r['Nip'],
            $r['Bukti Dukung'], $r['Latitude'], $r['Longitude'], $r['Jarak Absen'], $r['Keterangan Radius'], $r['Keterangan Bukti']
        ], $rows);
        return ['headers' => $headers, 'data' => $data];
    } catch (Exception $e) {
        error_log('Error in getAttendanceReport: ' . $e->getMessage());
        return ['error' => true, 'message' => 'Gagal mengambil laporan: ' . $e->getMessage()];
    }
}

function sendAttendanceReport(string $email, string $startDate, string $endDate): array {
    try {
        $report = getAttendanceReport($startDate, $endDate);
        if (!empty($report['error'])) {
            return ['success' => false, 'message' => $report['message']];
        }

        $csv = implode(',', $report['headers']) . "\n";
        foreach ($report['data'] as $row) {
            $csv .= implode(',', $row) . "\n";
        }

        if (!is_dir(BACKUP_DIR)) mkdir(BACKUP_DIR, 0755, true);
        $fileName = 'Laporan_Presensi_' . str_replace('-', '', $startDate) . '_' . str_replace('-', '', $endDate) . '.csv';
        file_put_contents(BACKUP_DIR . $fileName, $csv);

        $headers = "Content-Type: text/plain; charset=UTF-8\r\n";
        $sent = @mail($email, 'Laporan Presensi ' . $startDate . ' hingga ' . $endDate, 'Berikut adalah laporan presensi untuk periode yang diminta. File terlampir dapat diunduh di server: ' . BACKUP_DIR . $fileName, $headers);

        return ['success' => true, 'message' => 'Laporan berhasil dibuat' . ($sent ? ' dan email terkirim ke ' . $email : ' (file CSV tersimpan di server, pengiriman email mungkin perlu konfigurasi SMTP tambahan)')];
    } catch (Exception $e) {
        error_log('Error in sendAttendanceReport: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Gagal mengirim laporan: ' . $e->getMessage()];
    }
}

function backupData(): array {
    try {
        $stmt = db()->query("SELECT * FROM `rekappresensi` ORDER BY id ASC");
        $rows = $stmt->fetchAll();
        $dateString = date('Ymd_His');

        $csv = "id,Hari Tanggal,Waktu,Jenis Presensi,Nama Lembaga,Nama Pegawai,Nip,Bukti Dukung,Latitude,Longitude,Jarak Absen,Keterangan Radius,Keterangan Bukti,created_at\n";
        foreach ($rows as $row) {
            $csv .= implode(',', array_map(fn($v) => str_replace(',', ' ', (string)$v), $row)) . "\n";
        }

        if (!is_dir(BACKUP_DIR)) mkdir(BACKUP_DIR, 0755, true);
        $fileName = 'Backup_Presensi_' . $dateString . '.csv';
        file_put_contents(BACKUP_DIR . $fileName, $csv);

        return ['success' => true, 'message' => 'Backup data berhasil: ' . $fileName];
    } catch (Exception $e) {
        error_log('Error in backupData: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Gagal membuat backup: ' . $e->getMessage()];
    }
}
