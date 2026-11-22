const cardStack = document.getElementById('cardStack');
const swipeActions = document.getElementById('swipeActions');
let eventsData = [];
let currentIndex = 0;

// 1. Kumpulan foto dummy beragam (bertema event, konferensi, festival, dan bisnis)
const dummyImages = [
    "https://images.unsplash.com/photo-1540575467063-178a50c2df87?auto=format&fit=crop&w=600&q=80", // Konferensi / Seminar
    "https://images.unsplash.com/photo-1555396273-367ea4eb4db5?auto=format&fit=crop&w=600&q=80", // Festival / Kuliner
    "https://images.unsplash.com/photo-1511512578047-dfb367046420?auto=format&fit=crop&w=600&q=80", // Kompetisi / E-Sport
    "https://images.unsplash.com/photo-1475721027785-f74eccf877e2?auto=format&fit=crop&w=600&q=80", // Seminar / Panggung
    "https://images.unsplash.com/photo-1515187029135-18ee286d815b?auto=format&fit=crop&w=600&q=80", // Workshop / Diskusi
    "https://images.unsplash.com/photo-1505373877841-8d25f7d46678?auto=format&fit=crop&w=600&q=80"  // Pameran / Expo
];

// Fungsi untuk memilih gambar secara konsisten berdasarkan ID profil dari DB
function getProfileImage(profileId, backendLogo) {
    // Jika nanti di database kolom logo/image sudah diisi URL asli, utamakan pakai itu
    if (backendLogo) return backendLogo;
    
    // Jika kosong, pilih secara acak tapi konsisten berdasarkan ID profil
    const index = profileId % dummyImages.length;
    return dummyImages[index];
}

// Ambil data Real User yang sedang login via endpoint /me
function loadUserData() {
    const token = localStorage.getItem('kdt');
    if (!token) return;

    fetch('https://kridaya-v1-forked-production.up.railway.app/api/me', {
        method: 'GET',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'Authorization': 'Bearer ' + token
        }
    })
    .then(res => res.json())
    .then(user => {
        document.getElementById('userNameDisplay').textContent = user.name || 'User KriDaya';
        const roleText = user.role === 'event' ? 'Pihak Event' : 'Pihak Sponsor';
        document.getElementById('userRoleDisplay').textContent = roleText;

        if(user.plan === 'premium') {
            document.getElementById('userPlanText').textContent = 'Premium';
            document.getElementById('planActionLink').textContent = 'Kelola Paket';
        }
    })
    .catch(err => console.error('Gagal memuat profil user:', err));
}

// Fungsi Fetching Data Discover dari Endpoint Railway
function loadEvents() {
    const token = localStorage.getItem('kdt'); 

    if (!token) {
        cardStack.innerHTML = `
            <div class="empty-state">
                <h2>Sesi Belum Masuk</h2>
                <p>Silakan <a href="../index.html" style="color: #16a34a; font-weight: bold;">login terlebih dahulu</a> untuk mengakses fitur swipe.</p>
            </div>
        `;
        swipeActions.style.display = 'none';
        return;
    }

    fetch('https://kridaya-v1-forked-production.up.railway.app/api/discover', {
        method: 'GET',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'Authorization': 'Bearer ' + token
        }
    })
    .then(response => {
        if (response.status === 401) {
            throw new Error('Sesi kedaluwarsa atau token tidak valid (401 Unauthorized).');
        }
        if (!response.ok) {
            throw new Error('Gagal mengambil data dari server.');
        }
        return response.json();
    })
    .then(data => {
        eventsData = data; 
        currentIndex = 0;
        renderCards();
    })
    .catch(error => {
        console.error('Error fetching discover data:', error);
        cardStack.innerHTML = `
            <div class="empty-state">
                <h2>Belum Ada Profil Mitra</h2>
                <p>Belum ada data profil tandingan yang berstatus approved di database.</p>
            </div>
        `;
        swipeActions.style.display = 'none';
    });
}

function renderCards() {
    cardStack.innerHTML = '';
    
    if (eventsData.length === 0 || currentIndex >= eventsData.length) {
        cardStack.innerHTML = `
            <div class="empty-state">
                <h2>Semua Profil Telah Dilihat!</h2>
                <p>Nantikan penawaran kerja sama menarik lainnya.</p>
            </div>
        `;
        swipeActions.style.display = 'none';
        return;
    }

    for (let i = eventsData.length - 1; i >= currentIndex; i--) {
        const item = eventsData[i];
        const card = document.createElement('div');
        card.classList.add('swipe-card');
        card.style.zIndex = eventsData.length - i;
        
        // Memanggil fungsi random konsisten berdasarkan ID profil
        const imageUrl = getProfileImage(item.id, item.logo || item.image);

        card.innerHTML = `
            <div class="card-image" style="background-image: url('${imageUrl}')">
                <span class="card-badge">${item.category || 'Umum'}</span>
            </div>
            <div class="card-content">
                <div>
                    <h3>${item.name}</h3>
                    <div class="card-meta">
                        <span><i class="fa-solid fa-location-dot"></i> ${item.location || 'Lokasi tidak diatur'}</span>
                    </div>
                    <p class="card-desc" style="margin-top: 8px;">${item.description || 'Tidak ada deskripsi.'}</p>
                </div>
                <div style="display: flex; justify-content: space-between; align-items: center; border-top: 1px solid #f3f4f6; padding-top: 12px;">
                    <span style="font-size: 12px; color: #6b7280;">Status</span>
                    <span style="font-size: 13px; color: #16a34a; font-weight: 600;"><i class="fa-solid fa-circle-check"></i> Terverifikasi</span>
                </div>
            </div>
        `;

        if (i === currentIndex) {
            initSwipe(card);
        }

        cardStack.appendChild(card);
    }
}

// Interaksi geser (Mouse & Touch)
function initSwipe(card) {
    let startX = 0;
    let currentX = 0;
    let isDragging = false;

    card.addEventListener('mousedown', (e) => {
        isDragging = true;
        startX = e.clientX;
        card.style.transition = 'none';
    });

    document.addEventListener('mousemove', (e) => {
        if (!isDragging) return;
        currentX = e.clientX - startX;
        const rotate = currentX * 0.08;
        card.style.transform = `translateX(${currentX}px) rotate(${rotate}deg)`;
    });

    document.addEventListener('mouseup', () => {
        if (!isDragging) return;
        isDragging = false;
        handleSwipeEnd(currentX, card);
        currentX = 0;
    });

    card.addEventListener('touchstart', (e) => {
        isDragging = true;
        startX = e.touches[0].clientX;
        card.style.transition = 'none';
    });

    card.addEventListener('touchmove', (e) => {
        if (!isDragging) return;
        currentX = e.touches[0].clientX - startX;
        const rotate = currentX * 0.08;
        card.style.transform = `translateX(${currentX}px) rotate(${rotate}deg)`;
    });

    card.addEventListener('touchend', () => {
        if (!isDragging) return;
        isDragging = false;
        handleSwipeEnd(currentX, card);
        currentX = 0;
    });
}

function handleSwipeEnd(distance, card) {
    card.style.transition = 'transform 0.3s ease, opacity 0.3s ease';
    if (distance > 120) {
        card.style.transform = `translateX(500px) rotate(30deg)`;
        card.style.opacity = '0';
        setTimeout(() => processAction('match', eventsData[currentIndex].id), 300);
    } else if (distance < -120) {
        card.style.transform = `translateX(-500px) rotate(-30deg)`;
        card.style.opacity = '0';
        setTimeout(() => processAction('reject', eventsData[currentIndex].id), 300);
    } else {
        card.style.transform = `translateX(0px) rotate(0deg)`;
    }
}

function processAction(actionType, targetProfileId) {
    console.log(`Aksi: ${actionType} untuk Profile ID: ${targetProfileId}`);
    currentIndex++;
    renderCards();
}

// Tombol manual
document.getElementById('rejectBtn').addEventListener('click', () => {
    const topCard = cardStack.lastElementChild;
    if (topCard && currentIndex < eventsData.length) {
        topCard.style.transition = 'transform 0.3s ease, opacity 0.3s ease';
        topCard.style.transform = `translateX(-500px) rotate(-30deg)`;
        topCard.style.opacity = '0';
        setTimeout(() => processAction('reject', eventsData[currentIndex].id), 300);
    }
});

document.getElementById('matchBtn').addEventListener('click', () => {
    const topCard = cardStack.lastElementChild;
    if (topCard && currentIndex < eventsData.length) {
        topCard.style.transition = 'transform 0.3s ease, opacity 0.3s ease';
        topCard.style.transform = `translateX(500px) rotate(30deg)`;
        topCard.style.opacity = '0';
        setTimeout(() => processAction('match', eventsData[currentIndex].id), 300);
    }
});

// Tombol Logout
document.getElementById('logoutBtn').addEventListener('click', () => {
    localStorage.removeItem('kdt');
    window.location.href = '../index.html';
});

const hamburgerBtn = document.getElementById('hamburgerBtn');
const closeSidebarBtn = document.getElementById('closeSidebarBtn');
const appSidebar = document.getElementById('appSidebar');
const sidebarOverlay = document.getElementById('sidebarOverlay');

function toggleSidebar() {
    appSidebar.classList.toggle('mobile-open');
    sidebarOverlay.classList.toggle('active');
}

if (hamburgerBtn) hamburgerBtn.addEventListener('click', toggleSidebar);
if (closeSidebarBtn) closeSidebarBtn.addEventListener('click', toggleSidebar);
if (sidebarOverlay) sidebarOverlay.addEventListener('click', toggleSidebar);


// Inisialisasi awal
loadUserData();
loadEvents();

