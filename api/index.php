<?php
// ============================================================
// Router utama API Presensi YoriAPP (pengganti doGet/doPost Apps Script)
// Endpoint: /api/index.php?action=...
// ============================================================

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/functions.php';

function res($data) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

$action = $_GET['action'] ?? null;
$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET') {
        if (!$action) {
            res(['success' => true, 'message' => 'API Presensi YoriAPP aktif. ']);
        }

        // Dukungan terbatas untuk GET, hanya untuk aksi read-only yang aman
        if ($action === 'getSession') {
            $sessionData = getSessionData($_GET['sessionId'] ?? null);
            res($sessionData);
        }

        res(['success' => false, 'message' => 'Aksi "' . $action . '" hanya didukung lewat metode POST.']);
    }

    if ($method === 'POST') {
        $raw = file_get_contents('php://input');
        $payload = [];
        if ($raw) {
            $payload = json_decode($raw, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                res(['success' => false, 'message' => 'Data permintaan tidak valid (JSON rusak).']);
            }
        }

        switch ($action) {
            case 'login':
                res(verifyLogin($payload['username'] ?? null, $payload['password'] ?? null));
                break;
            case 'presensi':
                res(uploadFile($payload));
                break;
            case 'getSession':
                res(getSessionData($payload['sessionId'] ?? null));
                break;
            case 'logout':
                res(['success' => logoutSession($payload['sessionId'] ?? null)]);
                break;
            case 'resetPassword':
                res(resetPassword($payload['username'] ?? null, $payload['newPassword'] ?? null, $payload['verificationCode'] ?? null));
                break;
            case 'cekPresensi':
                res(cekPresensi($payload['username'] ?? null, $payload['password'] ?? null));
                break;
            case 'getWaktuPresensi':
                res(getWaktuPresensi());
                break;
            // --- Aksi admin tambahan (opsional, tidak dipakai frontend utama) ---
            case 'getAttendanceReport':
                res(getAttendanceReport($payload['startDate'] ?? '', $payload['endDate'] ?? ''));
                break;
            case 'sendAttendanceReport':
                res(sendAttendanceReport($payload['email'] ?? '', $payload['startDate'] ?? '', $payload['endDate'] ?? ''));
                break;
            case 'backupData':
                res(backupData());
                break;
            default:
                res(['success' => false, 'message' => 'Aksi tidak dikenali: ' . $action]);
        }
    }

    res(['success' => false, 'message' => 'Metode tidak didukung.']);
} catch (Throwable $err) {
    error_log('Error in router: ' . $err->getMessage());
    res(['success' => false, 'message' => $err->getMessage()]);
}