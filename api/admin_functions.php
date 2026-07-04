<?php
require_once __DIR__ . '/admin_helpers.php';

// ======================= LOGIN (DENGAN LEMBAGA) =======================
// Login dashboard Admin/Monitoring sekarang mengikuti kolom `Role` pada tabel
// `db` (menggantikan tabel `idakun` yang sudah dihapus). Nilai `Role` yang
// dikenali:
//   'superuser'    -> akses penuh ke seluruh fitur & seluruh lembaga
//   'admin'        -> akses hampir penuh ke seluruh lembaga, TAPI tidak boleh
//                      mereset database presensi & tidak boleh menambah data
//                      presensi manual (lihat requireSuperuser())
//   <Nama Lembaga> -> "Admin Lembaga": Role diisi persis nama lembaga sendiri,
//                      akses dibatasi hanya ke data lembaga tsb
//   '' (kosong)    -> tidak punya akses login ke dashboard
function admLogin(string $username, string $password): array {
    $stmt = db()->prepare("SELECT * FROM `db` WHERE `Username (MP/ID)` = :u LIMIT 1");
    $stmt->execute(['u' => $username]);
    $userRow = $stmt->fetch();

    if (!$userRow) {
        logActivity($username, 'unknown', 'login_failed', "Username tidak terdaftar: $username");
        return ['success' => false, 'errorCode' => 'USER_NOT_FOUND', 'message' => 'Akun Anda tidak terdaftar dalam sistem.'];
    }
    if ((string)$userRow['Password'] !== $password) {
        logActivity($username, 'unknown', 'login_failed', "Password salah untuk username: $username");
        return ['success' => false, 'errorCode' => 'WRONG_PASSWORD', 'message' => 'Password yang Anda masukkan salah.'];
    }
    $role = trim((string)($userRow['Role'] ?? ''));
    if (!$role) {
        logActivity($username, 'no_role', 'login_failed', "Akun tidak memiliki role/hak akses dashboard: $username");
        return ['success' => false, 'errorCode' => 'NO_ACCESS', 'message' => 'Akun Anda tidak memiliki hak akses ke sistem ini.'];
    }

    $roleGlobal = ['admin', 'superuser'];
    // Akun Admin Lembaga: Role diisi nama lembaga sendiri (bukan kata kunci global).
    $lembaga = in_array(strtolower($role), $roleGlobal) ? '' : $role;

    logActivity($username, $role, 'login', "Login berhasil oleh {$userRow['Name Pegawai']} ($role)" . ($lembaga ? " - Lembaga: $lembaga" : ''));
    return [
        'success' => true,
        'user' => ['nama' => $userRow['Name Pegawai'], 'role' => $role, 'username' => $userRow['Username (MP/ID)'], 'lembaga' => $lembaga],
    ];
}

// ======================= OTORISASI PERAN =======================
/** Ambil Role dashboard akun (dari tabel `db`), '' jika tidak ada/tidak ditemukan. */
function getDashboardRole(string $username): string {
    if ($username === '') return '';
    $stmt = db()->prepare("SELECT `Role` FROM `db` WHERE `Username (MP/ID)` = :u LIMIT 1");
    $stmt->execute(['u' => $username]);
    $row = $stmt->fetch();
    return $row ? trim((string)$row['Role']) : '';
}

/**
 * Batasi aksi tertentu (reset & tambah data presensi manual) hanya untuk
 * role 'superuser'. Role 'admin' (akses hampir penuh) maupun Admin Lembaga
 * (Role = nama lembaga) akan ditolak.
 */
function requireSuperuser(string $username, string $aksi): void {
    $role = strtolower(getDashboardRole($username));
    if ($role !== 'superuser') {
        logActivity($username ?: 'unknown', $role ?: 'unknown', 'akses_ditolak', "Percobaan $aksi ditolak (bukan Superuser).");
        throw new Exception('Akses ditolak: hanya Superuser yang boleh ' . $aksi . '.');
    }
}

// ======================= PRESENSI =======================
function admGetPresensi(): array {
    return getSheetData('rekappresensi');
}

function admUpdateKeteranganBukti(int $rowIndex, string $keterangan): array {
    $stmt = db()->prepare("UPDATE `rekappresensi` SET `Keterangan Bukti` = :k WHERE id = :id");
    $stmt->execute(['k' => $keterangan, 'id' => $rowIndex]);
    return ['success' => true];
}

function admAddPresensi(array $data, string $username = ''): array {
    requireSuperuser($username, 'menambah data presensi manual');
    $headers = getSheetHeaders('rekappresensi');
    $cols = []; $vals = []; $params = [];
    foreach ($headers as $h) {
        $cols[] = "`$h`";
        $key = ':p' . preg_replace('/[^a-zA-Z0-9]/', '', $h);
        $vals[] = $key;
        $params[$key] = $data[$h] ?? '';
    }
    $sql = "INSERT INTO `rekappresensi` (" . implode(',', $cols) . ") VALUES (" . implode(',', $vals) . ")";
    db()->prepare($sql)->execute($params);
    logActivity('system', 'Admin', 'add_presensi', 'Menambah presensi: ' . ($data['Nama Pegawai'] ?? '') . ' - ' . ($data['Waktu'] ?? ''));
    return ['success' => true, 'message' => 'Data presensi berhasil ditambahkan.'];
}

function admUpdatePresensi(int $rowIndex, array $data): array {
    $headers = getSheetHeaders('rekappresensi');
    $sets = []; $params = ['id' => $rowIndex];
    foreach ($headers as $h) {
        if (array_key_exists($h, $data)) {
            $key = 'p' . preg_replace('/[^a-zA-Z0-9]/', '', $h);
            $sets[] = "`$h` = :$key";
            $params[$key] = $data[$h];
        }
    }
    if ($sets) {
        $sql = "UPDATE `rekappresensi` SET " . implode(',', $sets) . " WHERE id = :id";
        db()->prepare($sql)->execute($params);
    }
    logActivity('system', 'Admin', 'update_presensi', "Update presensi baris $rowIndex: " . ($data['Nama Pegawai'] ?? ''));
    return ['success' => true, 'message' => 'Data presensi berhasil diperbarui.'];
}

function admDeletePresensi(int $rowIndex): array {
    db()->prepare("DELETE FROM `rekappresensi` WHERE id = :id")->execute(['id' => $rowIndex]);
    logActivity('system', 'Admin', 'delete_presensi', "Menghapus presensi baris $rowIndex");
    return ['success' => true, 'message' => 'Data presensi berhasil dihapus.'];
}

// ======================= DOKUMEN IJIN =======================
function admGetIjin(): array {
    return getSheetData('dokumenijin');
}

function admUpdateDokumenIjin(int $rowIndex, string $status, string $keterangan): array {
    db()->prepare("UPDATE `dokumenijin` SET `Status` = :s, `Keterangan` = :k WHERE id = :id")
        ->execute(['s' => $status, 'k' => $keterangan, 'id' => $rowIndex]);
    return ['success' => true];
}

function admDeleteDokumenIjin(int $rowIndex): array {
    db()->prepare("DELETE FROM `dokumenijin` WHERE id = :id")->execute(['id' => $rowIndex]);
    return ['success' => true];
}

// ======================= RESET DATA PRESENSI =======================
function admResetDataPresensi(string $username = ''): array {
    requireSuperuser($username, 'mereset data presensi');
    db()->exec("TRUNCATE TABLE `rekappresensi`");
    logActivity($username ?: 'system', 'superuser', 'reset_presensi', 'Mereset seluruh data presensi.');
    return ['success' => true, 'message' => 'Data presensi berhasil direset.'];
}

// Catatan: manajemen role pengguna dashboard TIDAK lagi lewat tabel/halaman
// terpisah (`idakun` / "Manajemen Role"). Sekarang cukup atur kolom `Role`
// pada baris pegawai yang bersangkutan lewat menu "Database Pegawai"
// (lihat admAddPegawai / admUpdatePegawai di bawah).

// ======================= CRUD DATABASE PEGAWAI =======================
function admGetPegawai(string $viewerRole = ''): array {
    prosesJadwalAcaraOtomatis();
    $rows = getSheetData('db');
    // Akun dengan Role superuser hanya boleh dilihat oleh sesama superuser.
    // Difilter di sini (bukan di frontend saja) supaya datanya memang tidak
    // pernah dikirim ke browser akun admin/pegawai biasa.
    if (strtolower(trim($viewerRole)) !== 'superuser') {
        $rows = array_values(array_filter($rows, fn($r) => strtolower(trim((string)($r['Role'] ?? ''))) !== 'superuser'));
    }
    return $rows;
}

function admAddPegawai(array $data): array {
    // Akun Superuser tidak boleh dibuat lewat panel ini sama sekali (siapapun
    // yang login) — harus dibuat langsung di database.
    if (($data['Role'] ?? '') === 'superuser') {
        return ['success' => false, 'message' => 'Akun Superuser tidak bisa dibuat lewat panel ini.'];
    }

    $headers = getSheetHeaders('db');
    // Hanya kolom angka yang aman diisi NULL kalau kosong (supaya tidak error
    // saat MySQL strict mode menolak string kosong '' masuk ke kolom numerik).
    // Kolom teks seperti Role/Name dibiarkan '' saja kalau kosong, supaya tetap
    // tersimpan walau kolomnya NOT NULL di database.
    $numericCols = ['Latitude', 'Longitude', 'Radius (m)'];
    $cols = []; $vals = []; $params = [];
    foreach ($headers as $h) {
        $cols[] = "`$h`";
        $key = ':p' . preg_replace('/[^a-zA-Z0-9]/', '', $h);
        $vals[] = $key;
        $val = $data[$h] ?? '';
        $params[$key] = (in_array($h, $numericCols) && $val === '') ? null : $val;
    }
    $sql = "INSERT INTO `db` (" . implode(',', $cols) . ") VALUES (" . implode(',', $vals) . ")";
    db()->prepare($sql)->execute($params);
    return ['success' => true];
}

function admUpdatePegawai(int $rowIndex, array $data): array {
    // Akun Superuser tidak boleh diubah lewat panel ini, siapapun yang login —
    // harus diatur langsung di database, supaya tidak bisa diturunkan/diambil alih lewat UI.
    $cur = db()->prepare("SELECT `Role` FROM `db` WHERE id = :id");
    $cur->execute(['id' => $rowIndex]);
    $curRole = $cur->fetchColumn();
    if ($curRole === 'superuser') {
        return ['success' => false, 'message' => 'Akun Superuser tidak bisa diubah lewat panel ini.'];
    }

    $headers = getSheetHeaders('db');
    $numericCols = ['Latitude', 'Longitude', 'Radius (m)'];
    $sets = []; $params = ['id' => $rowIndex];
    foreach ($headers as $h) {
        if (array_key_exists($h, $data)) {
            $key = 'p' . preg_replace('/[^a-zA-Z0-9]/', '', $h);
            $sets[] = "`$h` = :$key";
            $val = $data[$h];
            $params[$key] = (in_array($h, $numericCols) && $val === '') ? null : $val;
        }
    }
    if ($sets) {
        db()->prepare("UPDATE `db` SET " . implode(',', $sets) . " WHERE id = :id")->execute($params);
    }
    return ['success' => true];
}

function admDeletePegawai(int $rowIndex): array {
    // Sama seperti update: akun Superuser tidak boleh dihapus lewat panel ini.
    $cur = db()->prepare("SELECT `Role` FROM `db` WHERE id = :id");
    $cur->execute(['id' => $rowIndex]);
    $curRole = $cur->fetchColumn();
    if ($curRole === 'superuser') {
        return ['success' => false, 'message' => 'Akun Superuser tidak bisa dihapus lewat panel ini.'];
    }

    db()->prepare("DELETE FROM `db` WHERE id = :id")->execute(['id' => $rowIndex]);
    return ['success' => true];
}

// ======================= SETING WAKTU PRESENSI =======================
function admGetWaktuPresensi(): array {
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
        error_log('Error in admGetWaktuPresensi: ' . $e->getMessage());
        return $default;
    }
}

function admSetWaktuPresensi(array $data, string $username = ''): array {
    $role = strtolower(getDashboardRole($username));
    if (!in_array($role, ['admin', 'superuser'])) {
        logActivity($username ?: 'unknown', $role ?: 'unknown', 'akses_ditolak', 'Percobaan mengubah Seting Waktu Presensi ditolak (bukan Admin/Superuser).');
        return ['success' => false, 'message' => 'Akses ditolak: hanya Admin/Superuser yang boleh mengubah seting waktu presensi.'];
    }

    $fields = ['datang_mulai', 'datang_selesai', 'pulang_mulai', 'pulang_selesai', 'pulang_mulai_jumat', 'jam_terlambat'];
    $vals = [];
    foreach ($fields as $f) {
        $v = trim((string)($data[$f] ?? ''));
        if (!preg_match('/^([01]?\d|2[0-3]):[0-5]\d$/', $v)) {
            return ['success' => false, 'message' => "Format jam tidak valid untuk \"$f\" (harus HH:MM)."];
        }
        $vals[$f] = $v;
    }
    $toMinutes = fn($t) => ((int)explode(':', $t)[0]) * 60 + (int)explode(':', $t)[1];
    if ($toMinutes($vals['datang_mulai']) >= $toMinutes($vals['datang_selesai'])) {
        return ['success' => false, 'message' => 'Jam mulai Datang harus lebih awal dari jam selesai Datang.'];
    }
    if ($toMinutes($vals['pulang_mulai']) >= $toMinutes($vals['pulang_selesai'])) {
        return ['success' => false, 'message' => 'Jam mulai Pulang harus lebih awal dari jam selesai Pulang.'];
    }
    if ($toMinutes($vals['pulang_mulai_jumat']) >= $toMinutes($vals['pulang_selesai'])) {
        return ['success' => false, 'message' => 'Jam mulai Pulang (Jumat) harus lebih awal dari jam selesai Pulang.'];
    }
    if ($toMinutes($vals['jam_terlambat']) < $toMinutes($vals['datang_mulai']) || $toMinutes($vals['jam_terlambat']) > $toMinutes($vals['pulang_selesai'])) {
        return ['success' => false, 'message' => 'Jam Keterlambatan sebaiknya berada di antara jam mulai Datang dan jam selesai Pulang.'];
    }

    $stmt = db()->prepare(
        "UPDATE `seting_waktu_presensi` SET
            `datang_mulai` = :dm, `datang_selesai` = :ds,
            `pulang_mulai` = :pm, `pulang_selesai` = :ps, `pulang_mulai_jumat` = :pmj,
            `jam_terlambat` = :jt, `updated_by` = :u
         WHERE id = 1"
    );
    $stmt->execute([
        'dm' => $vals['datang_mulai'], 'ds' => $vals['datang_selesai'],
        'pm' => $vals['pulang_mulai'], 'ps' => $vals['pulang_selesai'], 'pmj' => $vals['pulang_mulai_jumat'],
        'jt' => $vals['jam_terlambat'], 'u' => $username ?: 'system',
    ]);

    logActivity($username ?: 'system', $role, 'update_waktu_presensi',
        "Update seting waktu presensi: Datang {$vals['datang_mulai']}-{$vals['datang_selesai']}, "
        . "Pulang {$vals['pulang_mulai']}-{$vals['pulang_selesai']}, Pulang Jumat {$vals['pulang_mulai_jumat']}-{$vals['pulang_selesai']}, "
        . "Batas Terlambat {$vals['jam_terlambat']}");

    return ['success' => true, 'message' => 'Seting waktu presensi berhasil diperbarui.'];
}

// ======================= CRUD MANAJEMEN LEMBAGA =======================
function admGetLembaga(): array {
    return getSheetData('db_lembaga');
}

function admAddLembaga(array $data): array {
    if (empty($data['Name Lembaga'])) {
        return ['success' => false, 'message' => 'Nama Lembaga wajib diisi.'];
    }
    $stmt = db()->prepare("INSERT INTO `db_lembaga` (`Name Lembaga`, `Latitude`, `Longitude`) VALUES (:n, :lat, :lon)");
    $stmt->execute(['n' => $data['Name Lembaga'], 'lat' => $data['Latitude'] ?? '', 'lon' => $data['Longitude'] ?? '']);
    logActivity('system', 'Admin', 'add_lembaga', 'Menambah lembaga: ' . $data['Name Lembaga']);
    return ['success' => true, 'message' => 'Lembaga berhasil ditambahkan.'];
}

function admUpdateLembaga(int $rowIndex, array $data): array {
    $sets = []; $params = ['id' => $rowIndex];
    foreach (['Name Lembaga', 'Latitude', 'Longitude'] as $h) {
        if (array_key_exists($h, $data)) {
            $key = 'p' . preg_replace('/[^a-zA-Z0-9]/', '', $h);
            $sets[] = "`$h` = :$key";
            $params[$key] = $data[$h];
        }
    }
    if ($sets) {
        db()->prepare("UPDATE `db_lembaga` SET " . implode(',', $sets) . " WHERE id = :id")->execute($params);
    }
    logActivity('system', 'Admin', 'update_lembaga', "Update lembaga baris $rowIndex: " . ($data['Name Lembaga'] ?? ''));
    return ['success' => true, 'message' => 'Data lembaga berhasil diperbarui.'];
}

function admDeleteLembaga(int $rowIndex): array {
    db()->prepare("DELETE FROM `db_lembaga` WHERE id = :id")->execute(['id' => $rowIndex]);
    logActivity('system', 'Admin', 'delete_lembaga', "Menghapus lembaga baris $rowIndex");
    return ['success' => true, 'message' => 'Data lembaga berhasil dihapus.'];
}

// ======================= UPLOAD BUKTI FOTO PRESENSI =======================
function admUploadBuktiFoto(string $base64Data, string $fileName): array {
    try {
        $saved = saveBase64File($base64Data, $fileName, BUKTI_DIR, BUKTI_URL_BASE);
        return ['success' => true, 'url' => $saved['url'], 'fileId' => $saved['fileName']];
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

// ======================= ATUR LIBUR MANUAL (setStatusRentang) =======================
function admSetStatusRentang(string $startDate, string $endDate, string $status, string $keterangan): array {
    $monthMap = ['Januari'=>1,'Februari'=>2,'Maret'=>3,'April'=>4,'Mei'=>5,'Juni'=>6,'Juli'=>7,'Agustus'=>8,'September'=>9,'Oktober'=>10,'November'=>11,'Desember'=>12];
    $start = new DateTime($startDate); $start->setTime(0, 0, 0);
    $end = new DateTime($endDate); $end->setTime(23, 59, 59);

    $rows = db()->query("SELECT id, `Waktu` FROM `rekappresensi`")->fetchAll();
    $updated = 0;
    foreach ($rows as $row) {
        if (!preg_match('/(\d{1,2})\s+(Januari|Februari|Maret|April|Mei|Juni|Juli|Agustus|September|Oktober|November|Desember)\s+(\d{4})/u', $row['Waktu'], $m)) continue;
        $tgl = new DateTime("{$m[3]}-{$monthMap[$m[2]]}-{$m[1]}");
        if ($tgl >= $start && $tgl <= $end) {
            db()->prepare("UPDATE `rekappresensi` SET `Keterangan Radius` = :r, `Keterangan Bukti` = :b WHERE id = :id")
                ->execute(['r' => $status, 'b' => $keterangan ?: 'Diubah manual oleh Admin', 'id' => $row['id']]);
            $updated++;
        }
    }
    return ['success' => true, 'updated' => $updated];
}

// ======================= OTOMATIS TIDAK HADIR =======================
// Fungsi ini sudah lama ada di file ini tapi belum pernah dipanggil dari mana
// pun (tidak ada routing di admin.php, tidak ada cron) — makanya walau status
// PSW sudah "Disetujui" & baris presensi normal tidak muncul, sistem TIDAK
// PERNAH membuat baris "Tidak Hadir" otomatis untuk pegawai yang tidak absen.
// Sekarang dipanggil lewat:
//   1) Tombol "Proses Tidak Hadir" (khusus superuser) di aplikasi -> admProsesTidakHadirHarian()
//   2) Cron job harian jam 20:00 -> cron_tidak_hadir.php -> admProsesTidakHadirHarian()
function admAutoFillTidakHadir(): int {
    prosesJadwalAcaraOtomatis();
    $monthsArr = ["Januari","Februari","Maret","April","Mei","Juni","Juli","Agustus","September","Oktober","November","Desember"];
    $daysArr = ["Minggu","Senin","Selasa","Rabu","Kamis","Jumat","Sabtu"];

    $today = new DateTime('now', new DateTimeZone(TIMEZONE));
    $checkMonth = (int)$today->format('n');
    $checkYear = (int)$today->format('Y');
    $lastDayToCheck = (int)$today->format('j') - 1;
    if ($lastDayToCheck === 0) {
        $prevMonthDate = (clone $today)->modify('first day of last month');
        $checkMonth = (int)$prevMonthDate->format('n');
        $checkYear = (int)$prevMonthDate->format('Y');
        $lastDayToCheck = (int)$prevMonthDate->format('t');
    }

    $presensiMap = [];
    $rows = db()->query("SELECT `Waktu`, `Nama Pegawai`, `Jenis Presensi` FROM `rekappresensi`")->fetchAll();
    foreach ($rows as $row) {
        $rawWaktu = (string)$row['Waktu'];
        $nama = strtoupper(preg_replace('/\s+/', ' ', trim((string)$row['Nama Pegawai'])));
        $jenis = trim((string)$row['Jenis Presensi']);
        $d = $m = $y = null;
        if (preg_match('#(\d{1,2})/(\d{1,2})/(\d{4})#', $rawWaktu, $mm)) {
            $d = (int)$mm[1]; $m = (int)$mm[2]; $y = (int)$mm[3];
        } elseif (preg_match('/(\d{1,2})\s+(Januari|Februari|Maret|April|Mei|Juni|Juli|Agustus|September|Oktober|November|Desember)\s+(\d{4})/iu', $rawWaktu, $mm)) {
            $d = (int)$mm[1];
            $idx = array_search(strtolower($mm[2]), array_map('strtolower', $monthsArr));
            $m = $idx !== false ? $idx + 1 : null;
            $y = (int)$mm[3];
        }
        if ($d && $m && $y && $nama) {
            $key = "$d/$m/$y";
            $presensiMap[$key][$nama] = $presensiMap[$key][$nama] ?? [];
            if (strtolower($jenis) === 'datang') $presensiMap[$key][$nama]['datang'] = true;
            if (strtolower($jenis) === 'pulang') $presensiMap[$key][$nama]['pulang'] = true;
        }
    }

    // Akun dengan Role 'superuser'/'admin' adalah akun dashboard (Korwil/Admin
    // pusat), BUKAN pegawai lapangan yang presensi harian — jadi jangan ikut
    // ditandai "Tidak Hadir".
    $pegawaiRows = db()->query(
        "SELECT `Name Pegawai`, `Username (MP/ID)`, `Name Lembaga` FROM `db`
         WHERE LOWER(TRIM(`Role`)) NOT IN ('superuser','admin') OR `Role` IS NULL"
    )->fetchAll();
    $rowsToInsert = [];
    for ($d = 1; $d <= $lastDayToCheck; $d++) {
        $checkDate = new DateTime("$checkYear-$checkMonth-$d");
        $dayOfWeek = (int)$checkDate->format('w');
        if ($dayOfWeek === 0 || $dayOfWeek === 6) continue;
        $currentD = (int)$checkDate->format('j');
        $currentM = (int)$checkDate->format('n');
        $currentY = (int)$checkDate->format('Y');
        $dateKey = "$currentD/$currentM/$currentY";
        $hariNama = $daysArr[$dayOfWeek];
        $bulanNama = $monthsArr[$currentM - 1];
        $waktuDatang = "$hariNama, $currentD $bulanNama $currentY pukul 07.00.00";
        $waktuPulang = "$hariNama, $currentD $bulanNama $currentY pukul 19.00.00";
        $hariTanggal = "$hariNama, " . $checkDate->format('d/m/Y');

        foreach ($pegawaiRows as $p) {
            $namaAsli = trim((string)$p['Name Pegawai']);
            if (!$namaAsli) continue;
            $namaKey = strtoupper(preg_replace('/\s+/', ' ', $namaAsli));
            $nip = $p['Username (MP/ID)'];
            $lembaga = $p['Name Lembaga'];
            $rec = $presensiMap[$dateKey][$namaKey] ?? [];
            if (empty($rec['datang'])) {
                $rowsToInsert[] = [$hariTanggal, $waktuDatang, 'Datang', $lembaga, $namaAsli, $nip, '-', '-', '-', '-', 'Tidak Hadir', 'Otomatis Sistem'];
            }
            if (empty($rec['pulang'])) {
                $rowsToInsert[] = [$hariTanggal, $waktuPulang, 'Pulang', $lembaga, $namaAsli, $nip, '-', '-', '-', '-', 'Tidak Hadir', 'Otomatis Sistem'];
            }
        }
    }

    if ($rowsToInsert) {
        $stmt = db()->prepare(
            "INSERT INTO `rekappresensi` (`Hari Tanggal`,`Waktu`,`Jenis Presensi`,`Nama Lembaga`,`Nama Pegawai`,`Nip`,`Bukti Dukung`,`Latitude`,`Longitude`,`Jarak Absen`,`Keterangan Radius`,`Keterangan Bukti`)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)"
        );
        foreach ($rowsToInsert as $r) $stmt->execute($r);
    }
    return count($rowsToInsert);
}

/**
 * Wrapper publik untuk dipanggil dari router (admin.php) maupun dari
 * cron_tidak_hadir.php. Kalau $username diisi (dipanggil via tombol di
 * aplikasi), dibatasi hanya untuk Admin/Superuser. Kalau dipanggil dari
 * cron (tanpa username, dijalankan langsung di server), tidak perlu cek
 * hak akses karena bukan request dari browser.
 */
function admProsesTidakHadirHarian(string $username = ''): array {
    if ($username !== '') {
        $role = strtolower(getDashboardRole($username));
        if (!in_array($role, ['admin', 'superuser'])) {
            logActivity($username, $role ?: 'unknown', 'akses_ditolak', 'Percobaan memproses Tidak Hadir otomatis ditolak (bukan Admin/Superuser).');
            return ['success' => false, 'message' => 'Akses ditolak: hanya Admin/Superuser yang boleh menjalankan proses ini.'];
        }
    }
    try {
        $jumlah = admAutoFillTidakHadir();
        logActivity($username ?: 'system', $username ? 'Admin' : 'system', 'proses_tidak_hadir',
            "Memproses Tidak Hadir otomatis: $jumlah baris presensi ditambahkan.");
        return [
            'success' => true,
            'jumlah' => $jumlah,
            'message' => $jumlah > 0
                ? "$jumlah baris presensi \"Tidak Hadir\" berhasil ditambahkan."
                : 'Tidak ada pegawai yang perlu ditandai Tidak Hadir (semua sudah punya rekaman presensi).',
        ];
    } catch (Exception $e) {
        logActivity($username ?: 'system', 'system', 'proses_tidak_hadir_gagal', 'Gagal memproses Tidak Hadir otomatis: ' . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

// ======================= FUNGSI PSW =======================
function admGetPegawaiByLembaga(string $lembaga): array {
    $target = normLembaga($lembaga);
    $rows = db()->query("SELECT `Username (MP/ID)` AS nip, `Name Pegawai` AS nama, `Name Lembaga` AS lembaga FROM `db`")->fetchAll();

    $result = [];
    foreach ($rows as $r) {
        if (normLembaga($r['lembaga']) === $target) $result[] = ['nip' => $r['nip'], 'nama' => $r['nama']];
    }
    if ($result) return $result;

    $targetFuzzy = fuzzyLembaga($lembaga);
    foreach ($rows as $r) {
        if (fuzzyLembaga($r['lembaga']) === $targetFuzzy) $result[] = ['nip' => $r['nip'], 'nama' => $r['nama']];
    }
    return $result;
}

function admLoginAndGetPegawai(string $username, string $password): array {
    try {
        $stmt = db()->prepare("SELECT * FROM `db` WHERE `Username (MP/ID)` = :u AND `Password` = :p LIMIT 1");
        $stmt->execute(['u' => $username, 'p' => $password]);
        $user = $stmt->fetch();
        if (!$user) return ['success' => false, 'message' => 'Username atau Password salah!'];
        $role = trim((string)($user['Role'] ?? ''));
        if ($role === '') return ['success' => false, 'message' => 'Anda tidak memiliki hak akses ke fitur ini!'];

        $roleGlobal = ['admin', 'superuser'];
        if (in_array(strtolower($role), $roleGlobal)) {
            $lembaga = (string)($user['Name Lembaga'] ?? '');
            $pegawaiList = admGetPegawaiByLembaga($lembaga);
        } else {
            // Admin Lembaga: Role diisi nama lembaga sendiri.
            $lembaga = $role;
            $pegawaiList = admGetPegawaiByLembaga($lembaga);
        }
        return [
            'success' => true,
            'user' => ['username' => $user['Username (MP/ID)'], 'nama' => $user['Name Pegawai'], 'role' => $role, 'lembaga' => $lembaga],
            'pegawaiList' => $pegawaiList,
        ];
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Error sistem: ' . $e->getMessage()];
    }
}

function getPegawaiByNip(?string $nip) {
    if (!$nip) return null;
    $stmt = db()->prepare("SELECT * FROM `db` WHERE `Username (MP/ID)` = :n LIMIT 1");
    $stmt->execute(['n' => trim($nip)]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function admGetActivityLogs(string $viewerRole = ''): array {
    if (strtolower(trim($viewerRole)) !== 'superuser') {
        return [];
    }
    return getSheetData('activitylog');
}

function admSubmitPengajuan(array $data): array {
    try {
        $saved = saveBase64File($data['fileBase64'], $data['fileName'], PSW_DIR, PSW_URL_BASE);
        $keterangan = trim((string)($data['keterangan'] ?? ''));
        $stmt = db()->prepare(
            "INSERT INTO `dokumenijin` (`Timestamp`,`Jenis Ijin`,`Nama Lembaga`,`Nama Pegawai`,`Nip`,`File URL`,`Tanggal Awal`,`Tanggal Akhir`,`Status`,`Keterangan`)
             VALUES (NOW(),:jenis,:lembaga,:nama,:nip,:url,:awal,:akhir,'Diajukan',:ket)"
        );
        $stmt->execute([
            'jenis' => $data['jenis'] ?? '', 'lembaga' => $data['lembaga'] ?? '', 'nama' => $data['namaPegawai'] ?? '',
            'nip' => $data['nip'] ?? '', 'url' => $saved['url'], 'awal' => $data['tglAwal'] ?? '', 'akhir' => $data['tglAkhir'] ?? '',
            'ket' => $keterangan !== '' ? $keterangan : '-',
        ]);
        return ['success' => true, 'message' => 'Pengajuan berhasil dikirim!'];
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Gagal mengirim data: ' . $e->getMessage()];
    }
}

function admGetRiwayatPengajuan(string $lembaga, ?string $nip = null): array {
    try {
        if ($nip) {
            $stmt = db()->prepare("SELECT * FROM `dokumenijin` WHERE `Nip` = :n ORDER BY id DESC");
            $stmt->execute(['n' => trim($nip)]);
            return ['success' => true, 'data' => array_map('mapIjinRow', $stmt->fetchAll())];
        }
        $rowsAll = db()->query("SELECT * FROM `dokumenijin` ORDER BY id DESC")->fetchAll();
        $target = normLembaga($lembaga); $targetFuzzy = fuzzyLembaga($lembaga);
        $riwayat = array_values(array_filter($rowsAll, fn($r) => normLembaga($r['Nama Lembaga']) === $target || fuzzyLembaga($r['Nama Lembaga']) === $targetFuzzy));
        return ['success' => true, 'data' => array_map('mapIjinRow', $riwayat)];
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}
function mapIjinRow(array $r): array {
    return [
        'timestamp' => $r['Timestamp'], 'jenis' => $r['Jenis Ijin'], 'lembaga' => $r['Nama Lembaga'], 'namaPegawai' => $r['Nama Pegawai'],
        'nip' => $r['Nip'], 'fileUrl' => $r['File URL'], 'tglAwal' => $r['Tanggal Awal'], 'tglAkhir' => $r['Tanggal Akhir'],
        'status' => $r['Status'], 'keterangan' => $r['Keterangan'],
    ];
}

// ======================= ARSIP PRESENSI (PDF) =======================
function admSaveArsipPDFFromBlob(string $base64PDF, string $fileName, array $metadata): array {
    try {
        $saved = saveBase64File('data:application/pdf;base64,' . $base64PDF, $fileName, ARSIP_PDF_DIR, ARSIP_PDF_URL_BASE);
        $id = uuidv4();
        $stmt = db()->prepare(
            "INSERT INTO `arsippresensi` (`id`,`Timestamp`,`Nama File`,`Filter Lembaga`,`Filter Nama`,`Tanggal Mulai`,`Tanggal Akhir`,`File URL`,`Dibuat Oleh`,`Lembaga Akses`)
             VALUES (:id,NOW(),:nf,:fl,:fn,:ds,:de,:url,:by,:lu)"
        );
        $stmt->execute([
            'id' => $id, 'nf' => $fileName, 'fl' => $metadata['filterLembaga'] ?? '', 'fn' => $metadata['filterNama'] ?? '',
            'ds' => $metadata['dateStart'] ?? '', 'de' => $metadata['dateEnd'] ?? '', 'url' => $saved['url'],
            'by' => $metadata['username'] ?? '', 'lu' => $metadata['lembagaUser'] ?? '',
        ]);
        logActivity($metadata['username'] ?? '', 'Admin', 'buat_arsip', 'Membuat arsip PDF: ' . $fileName . ' - Lembaga: ' . ($metadata['filterLembaga'] ?: 'Semua'));
        return ['success' => true, 'message' => 'Arsip berhasil disimpan', 'fileUrl' => $saved['url'], 'id' => $id];
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function admGetArsipList(string $role, string $userLembaga): array {
    try {
        $rows = db()->query("SELECT * FROM `arsippresensi` ORDER BY `Timestamp` DESC")->fetchAll();
        $arsip = array_map(fn($r) => array_merge(['_rowIndex' => $r['id']], $r), $rows);
        if (!in_array(strtolower($role), ['admin', 'superuser'])) {
            $target = normLembaga($userLembaga); $targetFuzzy = fuzzyLembaga($userLembaga);
            $arsip = array_values(array_filter($arsip, fn($a) => normLembaga($a['Lembaga Akses']) === $target || fuzzyLembaga($a['Lembaga Akses']) === $targetFuzzy));
        }
        return $arsip;
    } catch (Exception $e) {
        return [];
    }
}

function admDeleteArsip(string $arsipId): array {
    try {
        $stmt = db()->prepare("SELECT `File URL` FROM `arsippresensi` WHERE id = :id");
        $stmt->execute(['id' => $arsipId]);
        $row = $stmt->fetch();
        if ($row && $row['File URL']) {
            $path = ARSIP_PDF_DIR . basename($row['File URL']);
            if (is_file($path)) @unlink($path);
        }
        db()->prepare("DELETE FROM `arsippresensi` WHERE id = :id")->execute(['id' => $arsipId]);
        logActivity('system', 'Admin', 'hapus_arsip', 'Menghapus arsip ID: ' . $arsipId);
        return ['success' => true];
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

// ======================= ARSIP DATA PRESENSI =======================
function formatTanggalIndo(DateTime $date): string {
    $monthsArr = ["Januari","Februari","Maret","April","Mei","Juni","Juli","Agustus","September","Oktober","November","Desember"];
    return $date->format('d') . ' ' . $monthsArr[(int)$date->format('n') - 1] . ' ' . $date->format('Y');
}

function parseHariTanggalIndo(string $hariTanggal): ?DateTime {
    // Format aktual di DB: "Senin, 1 Juni 2026" (nama bulan Indonesia, tanpa
    // leading zero) — BUKAN "dd/mm/yyyy". Nama bulan Indonesia tidak dikenali
    // MySQL/STR_TO_DATE, jadi parsing dilakukan manual di sini.
    $monthsArr = ["Januari","Februari","Maret","April","Mei","Juni","Juli","Agustus","September","Oktober","November","Desember"];
    if (!preg_match('/(\d{1,2})\s+(Januari|Februari|Maret|April|Mei|Juni|Juli|Agustus|September|Oktober|November|Desember)\s+(\d{4})/iu', $hariTanggal, $m)) {
        return null;
    }
    $d = (int)$m[1];
    $idx = array_search(strtolower($m[2]), array_map('strtolower', $monthsArr));
    if ($idx === false) return null;
    $mo = $idx + 1;
    $y = (int)$m[3];
    return DateTime::createFromFormat('Y-n-j', "$y-$mo-$d") ?: null;
}

function admArsipkanDataPresensi(string $startDateStr, string $endDateStr, string $username): array {
    try {
        $start = new DateTime($startDateStr); $start->setTime(0, 0, 0);
        $end = new DateTime($endDateStr); $end->setTime(23, 59, 59);
        if ($start > $end) return ['success' => false, 'message' => 'Rentang tanggal tidak valid.'];

        // PENTING: filter berdasarkan tanggal presensi SEBENARNYA yang tersimpan
        // di kolom `Hari Tanggal` (format "Hari, d Bulan yyyy" pakai nama bulan
        // Indonesia, mis. "Senin, 1 Juni 2026") — BUKAN `created_at` (waktu baris
        // ditulis ke database) dan bukan pula format dd/mm/yyyy. Karena MySQL
        // tidak paham nama bulan Indonesia, filter dilakukan manual di PHP.
        $allRows = db()->query("SELECT * FROM `rekappresensi`")->fetchAll();
        $rows = array_values(array_filter($allRows, function ($r) use ($start, $end) {
            $tgl = parseHariTanggalIndo((string)($r['Hari Tanggal'] ?? ''));
            return $tgl && $tgl >= $start && $tgl <= $end;
        }));
        if (!$rows) return ['success' => false, 'message' => 'Tidak ada data presensi pada rentang tanggal tersebut.'];

        $namaArsip = formatTanggalIndo($start) . ' - ' . formatTanggalIndo($end);
        $ins = db()->prepare(
            "INSERT INTO `arsippresensidata` (`nama_arsip`,`Hari Tanggal`,`Waktu`,`Jenis Presensi`,`Nama Lembaga`,`Nama Pegawai`,`Nip`,`Bukti Dukung`,`Latitude`,`Longitude`,`Jarak Absen`,`Keterangan Radius`,`Keterangan Bukti`)
             VALUES (:na,:ht,:wk,:jp,:nl,:np,:nip,:bd,:lat,:lon,:jr,:kr,:kb)"
        );
        foreach ($rows as $r) {
            $ins->execute([
                'na' => $namaArsip, 'ht' => $r['Hari Tanggal'], 'wk' => $r['Waktu'], 'jp' => $r['Jenis Presensi'],
                'nl' => $r['Nama Lembaga'], 'np' => $r['Nama Pegawai'], 'nip' => $r['Nip'], 'bd' => $r['Bukti Dukung'],
                'lat' => $r['Latitude'], 'lon' => $r['Longitude'], 'jr' => $r['Jarak Absen'], 'kr' => $r['Keterangan Radius'], 'kb' => $r['Keterangan Bukti'],
            ]);
        }
        $ids = array_column($rows, 'id');
        db()->query("DELETE FROM `rekappresensi` WHERE id IN (" . implode(',', $ids) . ")");

        catatRegistriArsipPresensi($namaArsip, $start, $end, count($rows), $username);
        logActivity($username ?: 'system', 'Admin', 'arsip_presensi', 'Mengarsipkan ' . count($rows) . ' baris presensi (' . formatTanggalIndo($start) . ' s/d ' . formatTanggalIndo($end) . ') ke "' . $namaArsip . '"');

        return ['success' => true, 'message' => 'Berhasil memindahkan ' . count($rows) . " data presensi ke arsip \"$namaArsip\".", 'sheetName' => $namaArsip, 'jumlah' => count($rows)];
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function catatRegistriArsipPresensi(string $namaArsip, DateTime $start, DateTime $end, int $jumlahBaru, string $username): void {
    $stmt = db()->prepare("SELECT * FROM `arsippresensilist` WHERE `Nama Sheet` = :n");
    $stmt->execute(['n' => $namaArsip]);
    $existing = $stmt->fetch();
    if ($existing) {
        db()->prepare("UPDATE `arsippresensilist` SET `Jumlah Data` = `Jumlah Data` + :j, `Timestamp Arsip` = NOW(), `Diarsipkan Oleh` = :u WHERE id = :id")
            ->execute(['j' => $jumlahBaru, 'u' => $username ?: 'system', 'id' => $existing['id']]);
    } else {
        db()->prepare("INSERT INTO `arsippresensilist` (id,`Nama Sheet`,`Tanggal Mulai`,`Tanggal Akhir`,`Jumlah Data`,`Timestamp Arsip`,`Diarsipkan Oleh`) VALUES (:id,:n,:s,:e,:j,NOW(),:u)")
            ->execute(['id' => uuidv4(), 'n' => $namaArsip, 's' => $start->format('Y-m-d'), 'e' => $end->format('Y-m-d'), 'j' => $jumlahBaru, 'u' => $username ?: 'system']);
    }
}

function admGetArsipPresensiList(): array {
    try {
        return getSheetData('arsippresensilist');
    } catch (Exception $e) {
        return [];
    }
}

function admGetPresensiArsip(string $namaSheet): array {
    try {
        $stmt = db()->prepare("SELECT 1 FROM `arsippresensilist` WHERE `Nama Sheet` = :n");
        $stmt->execute(['n' => $namaSheet]);
        if (!$stmt->fetch()) return ['success' => false, 'message' => 'Arsip tidak ditemukan atau tidak valid.'];

        $stmt2 = db()->prepare("SELECT * FROM `arsippresensidata` WHERE `nama_arsip` = :n ORDER BY id ASC");
        $stmt2->execute(['n' => $namaSheet]);
        $rows = $stmt2->fetchAll();
        $data = array_map(function ($r) {
            $obj = ['_rowIndex' => $r['id']];
            foreach ($r as $k => $v) { if ($k !== 'id' && $k !== 'nama_arsip') $obj[$k] = $v; }
            return $obj;
        }, $rows);
        return ['success' => true, 'data' => $data];
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function admHapusArsipPresensi(string $namaSheet): array {
    try {
        db()->prepare("DELETE FROM `arsippresensidata` WHERE `nama_arsip` = :n")->execute(['n' => $namaSheet]);
        db()->prepare("DELETE FROM `arsippresensilist` WHERE `Nama Sheet` = :n")->execute(['n' => $namaSheet]);
        logActivity('system', 'Admin', 'hapus_arsip_presensi', "Menghapus arsip presensi \"$namaSheet\"");
        return ['success' => true, 'message' => 'Arsip berhasil dihapus.'];
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

// ======================= JADWAL ACARA =======================
function terapkanRadiusPegawai(array $daftarUsername, $lat, $lng, $radius): array {
    $backup = [];
    if (!$daftarUsername) return $backup;
    $in = implode(',', array_fill(0, count($daftarUsername), '?'));
    $stmt = db()->prepare("SELECT * FROM `db` WHERE `Username (MP/ID)` IN ($in)");
    $stmt->execute(array_values($daftarUsername));
    $rows = $stmt->fetchAll();
    $upd = db()->prepare("UPDATE `db` SET `Latitude` = :lat, `Longitude` = :lng, `Radius (m)` = :rad WHERE `Username (MP/ID)` = :u");
    foreach ($rows as $r) {
        $uname = $r['Username (MP/ID)'];
        $backup[$uname] = ['Latitude' => $r['Latitude'], 'Longitude' => $r['Longitude'], 'Radius (m)' => $r['Radius (m)']];
        $upd->execute(['lat' => $lat, 'lng' => $lng, 'rad' => $radius, 'u' => $uname]);
    }
    return $backup;
}

function kembalikanRadiusPegawai(array $backupData): void {
    if (!$backupData) return;
    $upd = db()->prepare("UPDATE `db` SET `Latitude` = :lat, `Longitude` = :lng, `Radius (m)` = :rad WHERE `Username (MP/ID)` = :u");
    foreach ($backupData as $uname => $b) {
        $upd->execute(['lat' => $b['Latitude'], 'lng' => $b['Longitude'], 'rad' => $b['Radius (m)'], 'u' => $uname]);
    }
}

function prosesJadwalAcaraOtomatis(): void {
    try {
        $rows = db()->query("SELECT * FROM `jadwalacara` WHERE `Status` IN ('Terjadwal','Aktif')")->fetchAll();
        $today0 = new DateTime('today', new DateTimeZone(TIMEZONE));
        foreach ($rows as $row) {
            $status = trim($row['Status']);
            $tglMulai = new DateTime($row['Tanggal Mulai']); $tglMulai->setTime(0, 0, 0);
            $tglAkhir = new DateTime($row['Tanggal Akhir']); $tglAkhir->setTime(23, 59, 59);

            if ($status === 'Terjadwal' && $today0 > $tglAkhir) {
                db()->prepare("UPDATE `jadwalacara` SET `Status` = 'Selesai' WHERE id = :id")->execute(['id' => $row['id']]);
            } elseif ($status === 'Terjadwal' && $today0 >= $tglMulai && $today0 <= $tglAkhir) {
                $daftar = json_decode($row['Daftar Pegawai'] ?: '[]', true) ?: [];
                $backup = terapkanRadiusPegawai($daftar, $row['Latitude'], $row['Longitude'], $row['Radius (m)']);
                db()->prepare("UPDATE `jadwalacara` SET `Backup Data` = :b, `Status` = 'Aktif', `Timestamp Diterapkan` = NOW() WHERE id = :id")
                    ->execute(['b' => json_encode($backup), 'id' => $row['id']]);
                logActivity('system', 'System', 'jadwal_acara_aktif', 'Jadwal "' . $row['Nama Acara'] . '" diaktifkan otomatis untuk ' . count($daftar) . ' pegawai.');
            } elseif ($status === 'Aktif' && $today0 > $tglAkhir) {
                $backup = json_decode($row['Backup Data'] ?: '{}', true) ?: [];
                kembalikanRadiusPegawai($backup);
                db()->prepare("UPDATE `jadwalacara` SET `Status` = 'Selesai', `Timestamp Dikembalikan` = NOW() WHERE id = :id")->execute(['id' => $row['id']]);
                logActivity('system', 'System', 'jadwal_acara_selesai', 'Jadwal "' . $row['Nama Acara'] . '" selesai, setting lokasi/radius pegawai dikembalikan ke default.');
            }
        }
    } catch (Exception $e) {
        error_log('prosesJadwalAcaraOtomatis error: ' . $e->getMessage());
    }
}

function admGetJadwalAcara(): array {
    prosesJadwalAcaraOtomatis();
    return getSheetData('jadwalacara');
}

function admAddJadwalAcara(array $data): array {
    try {
        if (empty($data['namaAcara']) || empty($data['tanggalMulai']) || empty($data['tanggalAkhir'])) {
            return ['success' => false, 'message' => 'Nama Acara, Tanggal Mulai, dan Tanggal Akhir wajib diisi.'];
        }
        if (($data['latitude'] ?? '') === '' || ($data['longitude'] ?? '') === '' || ($data['radius'] ?? '') === '') {
            return ['success' => false, 'message' => 'Latitude, Longitude, dan Radius wajib diisi.'];
        }
        $daftarPegawai = is_array($data['daftarPegawai'] ?? null) ? $data['daftarPegawai'] : [];
        if (!$daftarPegawai) return ['success' => false, 'message' => 'Pilih minimal satu pegawai untuk jadwal ini.'];

        $start = new DateTime($data['tanggalMulai']); $start->setTime(0, 0, 0);
        $end = new DateTime($data['tanggalAkhir']); $end->setTime(23, 59, 59);
        if ($start > $end) return ['success' => false, 'message' => 'Rentang tanggal tidak valid.'];

        $today0 = new DateTime('today', new DateTimeZone(TIMEZONE));
        $status = 'Terjadwal'; $backupData = []; $tsTerapkan = null;
        if ($today0 > $end) {
            $status = 'Selesai';
        } elseif ($today0 >= $start) {
            $status = 'Aktif';
            $backupData = terapkanRadiusPegawai($daftarPegawai, $data['latitude'], $data['longitude'], $data['radius']);
            $tsTerapkan = date('Y-m-d H:i:s');
        }

        $id = uuidv4();
        $stmt = db()->prepare(
            "INSERT INTO `jadwalacara` (id,`Nama Acara`,`Tanggal Mulai`,`Tanggal Akhir`,`Latitude`,`Longitude`,`Radius (m)`,`Daftar Pegawai`,`Backup Data`,`Status`,`Dibuat Oleh`,`Timestamp Dibuat`,`Timestamp Diterapkan`)
             VALUES (:id,:na,:tm,:ta,:lat,:lng,:rad,:dp,:bd,:st,:by,NOW(),:tsT)"
        );
        $stmt->execute([
            'id' => $id, 'na' => $data['namaAcara'], 'tm' => $start->format('Y-m-d'), 'ta' => $end->format('Y-m-d'),
            'lat' => $data['latitude'], 'lng' => $data['longitude'], 'rad' => $data['radius'],
            'dp' => json_encode($daftarPegawai), 'bd' => json_encode($backupData), 'st' => $status,
            'by' => $data['username'] ?? 'system', 'tsT' => $tsTerapkan,
        ]);

        logActivity($data['username'] ?? 'system', 'Admin', 'tambah_jadwal_acara',
            'Menambahkan jadwal acara "' . $data['namaAcara'] . '" (' . formatTanggalIndo($start) . ' s/d ' . formatTanggalIndo($end) . ') untuk ' . count($daftarPegawai) . " pegawai. Status awal: $status.");

        return ['success' => true, 'message' => 'Jadwal acara "' . $data['namaAcara'] . '" berhasil disimpan (' . count($daftarPegawai) . ' pegawai).', 'status' => $status];
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function admBatalkanJadwalAcara(string $rowIndex, string $username): array {
    try {
        $stmt = db()->prepare("SELECT * FROM `jadwalacara` WHERE id = :id");
        $stmt->execute(['id' => $rowIndex]);
        $row = $stmt->fetch();
        if (!$row) return ['success' => false, 'message' => 'Jadwal tidak ditemukan.'];
        $status = trim($row['Status']);
        if ($status === 'Selesai' || $status === 'Dibatalkan') {
            return ['success' => false, 'message' => 'Jadwal ini sudah tidak berjalan, tidak perlu dibatalkan.'];
        }
        if ($status === 'Aktif') {
            $backup = json_decode($row['Backup Data'] ?: '{}', true) ?: [];
            kembalikanRadiusPegawai($backup);
        }
        db()->prepare("UPDATE `jadwalacara` SET `Status` = 'Dibatalkan', `Timestamp Dikembalikan` = NOW() WHERE id = :id")->execute(['id' => $rowIndex]);
        logActivity($username ?: 'system', 'Admin', 'batalkan_jadwal_acara', 'Membatalkan jadwal acara "' . $row['Nama Acara'] . '". Setting lokasi/radius pegawai dikembalikan ke default.');
        return ['success' => true, 'message' => 'Jadwal dibatalkan dan setting pegawai dikembalikan ke default.'];
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function admHapusJadwalAcara(string $rowIndex): array {
    try {
        $stmt = db()->prepare("SELECT `Status` FROM `jadwalacara` WHERE id = :id");
        $stmt->execute(['id' => $rowIndex]);
        $row = $stmt->fetch();
        if (!$row) return ['success' => false, 'message' => 'Jadwal tidak ditemukan.'];
        if (in_array(trim($row['Status']), ['Terjadwal', 'Aktif'])) {
            return ['success' => false, 'message' => 'Jadwal masih berjalan. Batalkan dahulu sebelum menghapus.'];
        }
        db()->prepare("DELETE FROM `jadwalacara` WHERE id = :id")->execute(['id' => $rowIndex]);
        logActivity('system', 'Admin', 'hapus_jadwal_acara', "Menghapus riwayat jadwal acara $rowIndex.");
        return ['success' => true, 'message' => 'Riwayat jadwal acara berhasil dihapus.'];
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}