<?php
// ============================================================
// Router API Admin/Monitoring (pengganti doPost() di code.gs)
// Endpoint: /api/admin.php  — body: { action: '...', args: [...] }
// Dipanggil oleh e-presensi.html lewat runGoogleScript(fn, ...args)
// ============================================================

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/admin_functions.php';

function res($data) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    res(['success' => true, 'message' => 'API Admin/Monitoring Presensi aktif.']);
}

try {
    $raw = file_get_contents('php://input');
    $params = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        res(['success' => false, 'message' => 'Data permintaan tidak valid (JSON rusak).']);
    }
    $action = $params['action'] ?? '';
    $args = $params['args'] ?? [];
    // Helper kecil supaya argumen opsional tidak error walau tidak dikirim
    $arg = fn($i, $default = null) => $args[$i] ?? $default;

    switch ($action) {
        case 'login':                 $result = admLogin((string)$arg(0, ''), (string)$arg(1, '')); break;
        case 'getPresensi':           $result = admGetPresensi(); break;
        case 'getIjin':                $result = admGetIjin(); break;
        case 'updateKeteranganBukti': $result = admUpdateKeteranganBukti((int)$arg(0), (string)$arg(1, '')); break;
        case 'updateDokumenIjin':     $result = admUpdateDokumenIjin((int)$arg(0), (string)$arg(1, ''), (string)$arg(2, '')); break;
        case 'resetDataPresensi':     $result = admResetDataPresensi((string)$arg(0, '')); break;
        case 'getPegawai':            $result = admGetPegawai((string)$arg(0, '')); break;
        case 'addPegawai':            $result = admAddPegawai((array)$arg(0, [])); break;
        case 'updatePegawai':         $result = admUpdatePegawai((int)$arg(0), (array)$arg(1, [])); break;
        case 'deletePegawai':         $result = admDeletePegawai((int)$arg(0)); break;
        case 'deleteDokumenIjin':     $result = admDeleteDokumenIjin((int)$arg(0)); break;
        case 'setStatusRentang':      $result = admSetStatusRentang((string)$arg(0, ''), (string)$arg(1, ''), (string)$arg(2, ''), (string)$arg(3, '')); break;
        case 'getActivityLogs':       $result = admGetActivityLogs((string)$arg(0, '')); break;
        case 'getPegawaiByLembaga':   $result = admGetPegawaiByLembaga((string)$arg(0, '')); break;
        case 'loginAndGetPegawai':    $result = admLoginAndGetPegawai((string)$arg(0, ''), (string)$arg(1, '')); break;
        case 'submitPengajuan':       $result = admSubmitPengajuan((array)$arg(0, [])); break;
        case 'getRiwayatPengajuan':   $result = admGetRiwayatPengajuan((string)$arg(0, ''), $arg(1)); break;
        case 'getLembaga':            $result = admGetLembaga(); break;
        case 'addLembaga':            $result = admAddLembaga((array)$arg(0, [])); break;
        case 'updateLembaga':         $result = admUpdateLembaga((int)$arg(0), (array)$arg(1, [])); break;
        case 'deleteLembaga':         $result = admDeleteLembaga((int)$arg(0)); break;
        case 'uploadBuktiFoto':       $result = admUploadBuktiFoto((string)$arg(0, ''), (string)$arg(1, '')); break;
        case 'addPresensi':           $result = admAddPresensi((array)$arg(0, []), (string)$arg(1, '')); break;
        case 'updatePresensi':        $result = admUpdatePresensi((int)$arg(0), (array)$arg(1, [])); break;
        case 'deletePresensi':        $result = admDeletePresensi((int)$arg(0)); break;
        case 'saveArsipPDFFromBlob':  $result = admSaveArsipPDFFromBlob((string)$arg(0, ''), (string)$arg(1, ''), (array)$arg(2, [])); break;
        case 'getArsipList':          $result = admGetArsipList((string)$arg(0, ''), (string)$arg(1, '')); break;
        case 'deleteArsip':           $result = admDeleteArsip((string)$arg(0, '')); break;
        case 'arsipkanDataPresensi':  $result = admArsipkanDataPresensi((string)$arg(0, ''), (string)$arg(1, ''), (string)$arg(2, '')); break;
        case 'getArsipPresensiList':  $result = admGetArsipPresensiList(); break;
        case 'getPresensiArsip':      $result = admGetPresensiArsip((string)$arg(0, '')); break;
        case 'hapusArsipPresensi':    $result = admHapusArsipPresensi((string)$arg(0, '')); break;
        case 'getJadwalAcara':        $result = admGetJadwalAcara(); break;
        case 'addJadwalAcara':        $result = admAddJadwalAcara((array)$arg(0, [])); break;
        case 'batalkanJadwalAcara':   $result = admBatalkanJadwalAcara((string)$arg(0, ''), (string)$arg(1, '')); break;
        case 'hapusJadwalAcara':      $result = admHapusJadwalAcara((string)$arg(0, '')); break;
        case 'getWaktuPresensi':      $result = admGetWaktuPresensi(); break;
        case 'setWaktuPresensi':      $result = admSetWaktuPresensi((array)$arg(0, []), (string)$arg(1, '')); break;
        case 'prosesTidakHadirHarian':$result = admProsesTidakHadirHarian((string)$arg(0, '')); break;
        default:
            res(['success' => false, 'message' => 'Action tidak dikenal: ' . $action]);
    }
    res($result);
} catch (Throwable $error) {
    res(['success' => false, 'message' => $error->getMessage()]);
}

