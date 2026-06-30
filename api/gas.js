/**
 * /api/gas.js — Vercel Serverless Proxy ke Google Apps Script
 * VERSI TAHAN-BANTING:
 *  - Retry otomatis (default 3x) dengan backoff, karena GAS Web App
 *    kadang membalas halaman HTML (timeout/quota) alih-alih JSON.
 *  - Timeout per percobaan (default 25s) supaya request tidak menggantung
 *    sampai limit function Vercel habis.
 *  - Hanya retry untuk error yang "layak dicoba lagi" (timeout, non-JSON,
 *    5xx). Error bisnis dari GAS (success:false karena validasi, dsb)
 *    TIDAK di-retry karena itu bukan masalah koneksi.
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

const MAX_ATTEMPTS = 3;
const ATTEMPT_TIMEOUT_MS = 25000; // di bawah limit 30s default Vercel Hobby

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

async function callGasOnce(payload) {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), ATTEMPT_TIMEOUT_MS);

  try {
    const gasResponse = await fetch(GAS_URL, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
      },
      body: JSON.stringify(payload),
      redirect: 'follow',
      signal: controller.signal,
    });

    const text = await gasResponse.text();

    let data;
    try {
      data = JSON.parse(text);
    } catch {
      // GAS membalas HTML (cold-start/timeout/quota) -> dianggap retryable
      const err = new Error('NON_JSON_RESPONSE');
      err.retryable = true;
      err.raw = text.slice(0, 300);
      throw err;
    }

    if (!gasResponse.ok) {
      const err = new Error('GAS_HTTP_ERROR_' + gasResponse.status);
      err.retryable = gasResponse.status >= 500; // 5xx layak diulang, 4xx tidak
      err.data = data;
      throw err;
    }

    return data;
  } catch (error) {
    if (error.name === 'AbortError') {
      const err = new Error('TIMEOUT');
      err.retryable = true;
      throw err;
    }
    throw error;
  } finally {
    clearTimeout(timer);
  }
}

export default async function handler(req, res) {
  if (req.method !== 'POST') {
    return res.status(405).json({ success: false, message: 'Method not allowed' });
  }

  const action = req.body && req.body.action ? req.body.action : 'unknown';
  let lastError = null;

  for (let attempt = 1; attempt <= MAX_ATTEMPTS; attempt++) {
    try {
      const data = await callGasOnce(req.body);
      return res.status(200).json(data);
    } catch (error) {
      lastError = error;
      const isLast = attempt === MAX_ATTEMPTS;
      const label = error.message === 'TIMEOUT' ? 'Timeout' : 'Non-JSON response from GAS';

      console.error(
        `[gas-proxy] ${label} (attempt ${attempt}/${MAX_ATTEMPTS}) action="${action}"` +
          (error.raw ? `: ${error.raw}` : '')
      );

      // Error bisnis (4xx, bukan masalah koneksi) -> langsung kembalikan, tidak perlu retry
      if (error.retryable === false) {
        return res.status(200).json(
          error.data || { success: false, message: 'GAS menolak permintaan: ' + error.message }
        );
      }

      if (!isLast) {
        // Backoff: 400ms, 900ms, ... sebelum mencoba lagi
        await sleep(400 * attempt);
        continue;
      }
    }
  }

  // Semua percobaan gagal -> kembalikan pesan yang jelas ke frontend
  return res.status(502).json({
    success: false,
    message:
      'Server Google Apps Script sedang lambat merespons setelah ' +
      MAX_ATTEMPTS +
      'x percobaan. Aksi mungkin sudah tersimpan di spreadsheet — silakan refresh untuk memastikan.',
    retryable: true,
    action,
    raw: lastError && lastError.raw ? lastError.raw : undefined,
  });
}
