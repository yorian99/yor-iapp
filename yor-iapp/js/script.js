// Mobile menu toggle
const mobileBtn = document.getElementById('mobile-menu');
const navLinks = document.getElementById('nav-links');
if (mobileBtn && navLinks) {
  mobileBtn.addEventListener('click', () => {
    navLinks.classList.toggle('active');
  });

  // Close menu when link clicked
  document.querySelectorAll('.nav-links a').forEach(link => {
    link.addEventListener('click', () => {
      navLinks.classList.remove('active');
    });
  });
}

// Video Modal handling
const modal = document.getElementById('videoModal');
const openBtn = document.getElementById('videoModalBtn');
const closeSpan = document.querySelector('.close-modal');

if (openBtn && modal) {
  openBtn.addEventListener('click', () => {
    modal.style.display = 'flex';
    const videoEl = modal.querySelector('video');
    if (videoEl) videoEl.load();
  });
}

if (closeSpan && modal) {
  closeSpan.addEventListener('click', () => {
    modal.style.display = 'none';
    const videoEl = modal.querySelector('video');
    if (videoEl) videoEl.pause();
  });
}

window.addEventListener('click', (e) => {
  if (e.target === modal) {
    modal.style.display = 'none';
    const videoEl = modal.querySelector('video');
    if (videoEl) videoEl.pause();
  }
});

// Form submission
const form = document.getElementById('registrationForm');
if (form) {
  form.addEventListener('submit', (e) => {
    e.preventDefault();
    const nama = document.getElementById('nama').value.trim();
    if (!nama) {
      alert('Mohon isi nama lengkap.');
      return;
    }
    alert(`Terima kasih ${nama}, data pendaftaran akan segera diproses oleh panitia PPDB SDN Jagir 1 Sine.`);
    form.reset();
  });
}

console.log('Landing page SDN Jagir 1 Sine - siap upload gambar di folder /images dan /videos');
