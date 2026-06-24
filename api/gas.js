/**
 * /api/gas.js — Vercel Serverless Proxy ke Google Apps Script
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

export default async function handler(req, res) {
  if (req.method !== 'POST') {
    return res.status(405).json({ success: false, message: 'Method not allowed' });
  }

  try {
    const gasResponse = await fetch(GAS_URL, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
      },
      body: JSON.stringify(req.body),
      redirect: 'follow',
    });

    const text = await gasResponse.text();

    let data;
    try {
      data = JSON.parse(text);
    } catch {
      console.error('[gas-proxy] Non-JSON response from GAS:', text.slice(0, 500));
      return res.status(502).json({
        success: false,
        message: 'Response tidak valid dari GAS backend.',
        raw: text.slice(0, 200),
      });
    }

    return res.status(200).json(data);
  } catch (error) {
    console.error('[gas-proxy] Fetch error:', error);
    return res.status(500).json({
      success: false,
      message: 'Gagal menghubungi GAS backend: ' + error.message,
    });
  }
}
