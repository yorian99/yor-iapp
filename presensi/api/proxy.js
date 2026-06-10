// api/proxy.js
export default async function handler(req, res) {
  // Set CORS headers agar frontend dapat mengakses response
  res.setHeader('Access-Control-Allow-Origin', '*');
  res.setHeader('Access-Control-Allow-Methods', 'POST, OPTIONS');
  res.setHeader('Access-Control-Allow-Headers', 'Content-Type');

  // Tangani preflight OPTIONS
  if (req.method === 'OPTIONS') {
    return res.status(200).end();
  }

  // Hanya terima POST
  if (req.method !== 'POST') {
    return res.status(405).json({ error: 'Method not allowed' });
  }

  // Ambil action dari query parameter
  const { action } = req.query;
  if (!action) {
    return res.status(400).json({ error: 'Missing action parameter' });
  }

  // URL Google Apps Script (ganti jika perlu)
  const GAS_URL = 'https://script.google.com/macros/s/AKfycbzqgwrpj7mMOz2sgB5vfwfArPwU5o1h9MkIXrBh-6Vw5_Bl0yGiGGFN22dJdB8JwC_y/exec';

  try {
    // Kirim request ke GAS
    const gasResponse = await fetch(`${GAS_URL}?action=${action}`, {
      method: 'POST',
      headers: {
        'Content-Type': 'text/plain', // GAS menerima text/plain untuk menghindari preflight
      },
      body: JSON.stringify(req.body), // Teruskan body dari frontend
    });

    const data = await gasResponse.json();
    res.status(200).json(data);
  } catch (error) {
    console.error('Proxy error:', error);
    res.status(500).json({ error: 'Internal server error', details: error.message });
  }
}
