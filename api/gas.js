/**
 * /api/gas.js — Vercel Serverless Proxy ke Google Apps Script
 *
 * Perbaikan dari versi sebelumnya:
 * - Retry otomatis (3x, backoff 0/1.2s/2.5s) saat GAS mengembalikan
 *   HTML (bukan JSON) — ini terjadi saat GAS overload/timeout sesaat
 *   atau Google sempat menyisipkan halaman auth/error.
 * - Timeout 25 detik per percobaan via AbortController, supaya satu
 *   percobaan yang menggantung tidak menghabiskan seluruh waktu Vercel.
 * - Hanya percobaan TERAKHIR yang dikembalikan ke client sebagai error,
 *   percobaan sebelumnya yang gagal di-retry secara diam-diam.
 */

export const config = {
  api: {
    bodyParser: true,
    responseLimit: '10mb',
  },
};

const GAS_URL =
  process.env.GAS_URL ||
  'https://script.google.com/macros/s/AKfycbyqwUbbQH6VvyU_zJqILn3_y-cvy_APCkcmZyJKgJGyzIZeHTITAFnApc2iGnnpIqon/exec';

const MAX_RETRIES = 3;
const BACKOFF_MS = [0, 1200, 2500]; // delay sebelum percobaan ke-1, ke-2, ke-3
const TIMEOUT_MS = 25000;

const delay = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

/**
 * Satu kali percobaan memanggil GAS.
 * Return: { ok: true, data } jika sukses dapat JSON valid,
 *         { ok: false, status, reason, detail } jika gagal.
 */
async function tryCallGAS(body) {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), TIMEOUT_MS);

  try {
    const gasResponse = await fetch(GAS_URL, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
      },
      body: JSON.stringify(body),
      redirect: 'follow',
      signal: controller.signal,
    });

    clearTimeout(timer);
    const text = await gasResponse.text();

    try {
      const data = JSON.parse(text);
      return { ok: true, data };
    } catch {
      return {
        ok: false,
        status: 502,
        reason: 'non_json',
        detail: text.slice(0, 200),
      };
    }
  } catch (error) {
    clearTimeout(timer);
    if (error.name === 'AbortError') {
      return { ok: false, status: 504, reason: 'timeout', detail: null };
    }
    return { ok: false, status: 500, reason: 'network_error', detail: error.message };
  }
}

export default async function handler(req, res) {
  if (req.method !== 'POST') {
    return res.status(405).json({ success: false, message: 'Method not allowed' });
  }

  const action = req.body?.action || '(unknown)';
  let lastFail = null;

  for (let attempt = 1; attempt <= MAX_RETRIES; attempt++) {
    if (attempt > 1) {
      await delay(BACKOFF_MS[attempt - 1]);
      console.log(`[gas-proxy] Retry ${attempt}/${MAX_RETRIES} untuk action="${action}"`);
    }

    const result = await tryCallGAS(req.body);

    if (result.ok) {
      return res.status(200).json(result.data);
    }

    lastFail = result;

    if (result.reason === 'non_json') {
      console.error(
        `[gas-proxy] Non-JSON response from GAS (attempt ${attempt}/${MAX_RETRIES}) action="${action}":`,
        result.detail
      );
    } else if (result.reason === 'timeout') {
      console.error(`[gas-proxy] Timeout (attempt ${attempt}/${MAX_RETRIES}) action="${action}"`);
    } else {
      console.error(
        `[gas-proxy] Fetch error (attempt ${attempt}/${MAX_RETRIES}) action="${action}":`,
        result.detail
      );
    }
  }

  // Semua percobaan gagal — kembalikan respons error terakhir ke client
  if (lastFail.reason === 'non_json') {
    return res.status(502).json({
      success: false,
      message: 'Response tidak valid dari GAS backend (sudah dicoba ' + MAX_RETRIES + 'x).',
      raw: lastFail.detail,
    });
  }
  if (lastFail.reason === 'timeout') {
    return res.status(504).json({
      success: false,
      message: 'GAS backend tidak merespons (timeout, sudah dicoba ' + MAX_RETRIES + 'x).',
    });
  }
  return res.status(500).json({
    success: false,
    message: 'Gagal menghubungi GAS backend: ' + lastFail.detail,
  });
}
