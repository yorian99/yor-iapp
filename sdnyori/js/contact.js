const CONTACT_SCRIPT_URL = 'https://script.google.com/macros/s/YOUR_DEPLOYMENT_ID/exec';

document.getElementById('contactForm')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const data = {
        name: document.getElementById('contactName').value,
        email: document.getElementById('contactEmail').value,
        subject: document.getElementById('contactSubject').value,
        message: document.getElementById('contactMessage').value,
        timestamp: new Date().toISOString()
    };
    const statusDiv = document.getElementById('contactMessageStatus');
    statusDiv.innerHTML = 'Mengirim...';
    try {
        await fetch(CONTACT_SCRIPT_URL, {
            method: 'POST',
            mode: 'no-cors',
            body: JSON.stringify(data)
        });
        statusDiv.innerHTML = 'Pesan terkirim! Terima kasih.';
        document.getElementById('contactForm').reset();
    } catch (err) {
        statusDiv.innerHTML = 'Gagal mengirim. Coba lagi nanti.';
    }
});