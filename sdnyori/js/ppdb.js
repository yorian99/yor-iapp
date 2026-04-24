// Replace with your actual Google Apps Script Web App URL
const SCRIPT_URL = 'https://script.google.com/macros/s/YOUR_DEPLOYMENT_ID/exec';

document.getElementById('ppdbForm')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const formData = {
        nama: document.getElementById('nama').value,
        nisn: document.getElementById('nisn').value,
        ttl: document.getElementById('ttl').value,
        jalur: document.getElementById('jalur').value,
        alamat: document.getElementById('alamat').value,
        telepon: document.getElementById('telepon').value,
        email: document.getElementById('email').value,
        timestamp: new Date().toISOString()
    };
    const msgDiv = document.getElementById('ppdbMessage');
    msgDiv.innerHTML = 'Mengirim...';
    try {
        const response = await fetch(SCRIPT_URL, {
            method: 'POST',
            mode: 'no-cors',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(formData)
        });
        msgDiv.innerHTML = 'Pendaftaran berhasil! Kami akan menghubungi Anda.';
        document.getElementById('ppdbForm').reset();
    } catch (err) {
        msgDiv.innerHTML = 'Terjadi kesalahan. Silakan coba lagi.';
    }
});