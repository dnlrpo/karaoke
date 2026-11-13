let sortableInstance = null;
let isAdmin = false;
let nightCode = "";
let currentQueue = [];
let registrationEnabled = null;
let participantName = undefined;
let lastReactionId = 0;
let csrfToken = "";
let lastQueueSignature = "";
let isDragging = false;
const sentReactionIds = new Set();

const form = document.getElementById('karaokeForm');
const queueList = document.getElementById('queueList');
const queueBadge = document.getElementById('queueBadge');
const loginModal = document.getElementById('loginModal');
const codeLabel = document.getElementById('currentCodeLabel');
const reactionPanel = document.getElementById('reactionPanel');

// Initial Load
async function init() {
    await fetchQueue();
    // Unified polling: queue + reactions in a single request
    setInterval(fetchQueue, 5000);
}

async function fetchQueue() {
    try {
        const response = await fetch(`api.php?action=get_queue&since=${lastReactionId}`);
        const data = await response.json();
        if (!data.success) return;

        const adminNow = data.is_admin === true;
        if (adminNow !== isAdmin) {
            isAdmin = adminNow;
            updateUIForAdmin();
        }

        if (data.registration_enabled !== registrationEnabled) {
            registrationEnabled = data.registration_enabled;
            updateRegistrationUI();
        }

        const nameNow = data.participant_name || null;
        if (nameNow !== participantName) {
            const hadName = Boolean(participantName);
            participantName = nameNow;
            applyParticipantState();
            if (hadName && !nameNow) {
                showToast('Tu sesión expiró. Valida el código de nuevo.', 'error');
            }
        }

        if (data.csrf_token) csrfToken = data.csrf_token;

        if (isAdmin && data.night_code && data.night_code !== nightCode) {
            nightCode = data.night_code;
            codeLabel.innerText = nightCode;
        }

        // Si el flag quedó atascado (drag cancelado sin onEnd), liberarlo
        if (isDragging && !sortableIsDragging()) {
            isDragging = false;
        }

        // Solo se repinta la cola cuando cambia algo, y nunca mientras se arrastra
        const signature = queueSignature(data.queue, adminNow);
        if (signature !== lastQueueSignature && !isDragging) {
            lastQueueSignature = signature;
            currentQueue = data.queue;
            renderQueue(data.queue);
        }

        // Render only reactions we have not sent ourselves
        (data.reactions || []).forEach(reaction => {
            if (sentReactionIds.has(reaction.id)) return;
            createReactionParticle(reaction.emoji);
            lastReactionId = Math.max(lastReactionId, reaction.id);
        });
        showInitialPin(data.initial_admin_pin);
    } catch (error) {
        console.error("Error fetching queue:", error);
    }
}

/**
 * Huella de la cola: evita reconstruir el DOM (y destruir el drag & drop)
 * en cada poll cuando no ha cambiado nada.
 */
function queueSignature(queue, admin) {
    return JSON.stringify([
        admin === true,
        queue.map(item => [item.id, item.user_name, item.song_title, item.artist_name]),
    ]);
}

function sortableIsDragging() {
    return document.querySelector('.sortable-drag, .sortable-ghost') !== null;
}

let initialPinShown = false;

function showInitialPin(pin) {
    if (!pin || initialPinShown) return;
    initialPinShown = true;
    showToast(`PIN inicial de staff: ${pin} — cámbialo en Configuración de Hoy`);
}

async function sendReaction(emoji) {
    try {
        // Optimistic UI
        createReactionParticle(emoji);

        const response = await fetch('api.php?action=send_reaction', {
            method: 'POST',
            headers: adminFetchHeaders(),
            body: JSON.stringify({ emoji })
        });
        const data = await response.json();
        // Track the server id so the next poll skips our own reaction
        if (data.success && data.id) {
            sentReactionIds.add(data.id);
            lastReactionId = Math.max(lastReactionId, data.id);
        }
    } catch (error) {
        console.error("Error sending reaction:", error);
    }
}

function createReactionParticle(emoji) {
    const p = document.createElement('div');
    p.className = 'reaction-particle';
    p.innerText = emoji;

    // Ajustes aleatorios
    const startX = Math.random() * (window.innerWidth - 80) + 40;
    const randomXOffset = (Math.random() - 0.5) * 150;
    const randomRotate = (Math.random() - 0.5) * 90;

    p.style.left = `${startX}px`;
    p.style.setProperty('--random-x', `${randomXOffset}px`);
    p.style.setProperty('--random-rotate', `${randomRotate}deg`);

    document.body.appendChild(p);

    // Eliminar después de la animación
    setTimeout(() => p.remove(), 3000);
}

function updateUIForAdmin() {
    if (isAdmin) {
        document.body.classList.add('admin-mode');
        document.getElementById('adminStatusBadge').classList.remove('hidden');
        document.getElementById('adminIcon').className = 'fas fa-unlock text-[#f9af53]';
        document.getElementById('adminBtnText').innerText = "Cerrar Staff";
    } else {
        document.body.classList.remove('admin-mode');
        document.getElementById('adminStatusBadge').classList.add('hidden');
        document.getElementById('adminIcon').className = 'fas fa-lock text-slate-500';
        document.getElementById('adminBtnText').innerText = "Acceso Staff";
    }
}

function updateRegistrationUI() {
    const form = document.getElementById('karaokeForm');
    const message = document.getElementById('registrationClosedMessage');
    const toggle = document.getElementById('registrationToggle');

    if (registrationEnabled) {
        form.classList.remove('hidden');
        message.classList.add('hidden');
    } else {
        form.classList.add('hidden');
        message.classList.remove('hidden');
    }

    if (toggle) {
        toggle.checked = registrationEnabled;
    }
}

async function toggleRegistration() {
    if (!isAdmin) return;

    const toggle = document.getElementById('registrationToggle');
    const enabled = toggle.checked;

    try {
        const response = await fetch('api.php?action=toggle_registration', {
            method: 'POST',
            headers: adminFetchHeaders(),
            body: JSON.stringify({ enabled })
        });
        const data = await response.json();
        if (data.success) {
            registrationEnabled = enabled;
            updateRegistrationUI();
            showToast(data.message);
        } else {
            showToast(data.message, "error");
            toggle.checked = !enabled; // Revert
        }
    } catch (error) {
        showToast("Error de conexión", "error");
        toggle.checked = !enabled; // Revert
    }
}

/**
 * Alterna entre el paso de identidad (nombre + código) y el de canción.
 */
function applyParticipantState() {
    const identityStep = document.getElementById('identityStep');
    const songStep = document.getElementById('songStep');
    const nameEl = document.getElementById('participantName');

    if (participantName) {
        nameEl.textContent = participantName;
        identityStep.classList.add('hidden');
        songStep.classList.remove('hidden');
    } else {
        identityStep.classList.remove('hidden');
        songStep.classList.add('hidden');
    }
}

function setFieldError(id, message) {
    const el = document.getElementById(id);
    if (!el) return;
    el.textContent = message || '';
    el.classList.toggle('hidden', !message);
}

async function validateCode() {
    setFieldError('identityError', '');
    const name = document.getElementById('userName').value.trim();
    const code = document.getElementById('accessCode').value.trim().toUpperCase();

    if (!name) {
        setFieldError('identityError', 'Escribe tu nombre');
        document.getElementById('userName').focus();
        return;
    }
    if (!code) {
        setFieldError('identityError', 'Escribe el código de la noche');
        document.getElementById('accessCode').focus();
        return;
    }

    try {
        const response = await fetch('api.php?action=validate_code', {
            method: 'POST',
            headers: adminFetchHeaders(),
            body: JSON.stringify({ userName: name, accessCode: code })
        });
        const data = await response.json();

        if (!data.success) {
            setFieldError('identityError', data.message || 'No se pudo validar el código');
            return;
        }

        participantName = data.participant_name;
        applyParticipantState();
        document.getElementById('songTitle').focus();
        showToast('Código validado');
    } catch (error) {
        setFieldError('identityError', 'Error de conexión');
    }
}

async function addSong() {
    setFieldError('songError', '');
    const song = document.getElementById('songTitle').value.trim();
    const artist = document.getElementById('artistName').value.trim();

    if (!song) {
        setFieldError('songError', 'Escribe la canción');
        document.getElementById('songTitle').focus();
        return;
    }
    if (!artist) {
        setFieldError('songError', 'Escribe el artista');
        document.getElementById('artistName').focus();
        return;
    }

    try {
        const response = await fetch('api.php?action=add_to_queue', {
            method: 'POST',
            headers: adminFetchHeaders(),
            body: JSON.stringify({ songTitle: song, artistName: artist })
        });
        const data = await response.json();

        if (!data.success) {
            if (response.status === 403) {
                participantName = null;
                applyParticipantState();
                setFieldError('identityError', data.message || 'Valida el código de la noche');
                return;
            }
            setFieldError('songError', data.message || 'No se pudo anotar la canción');
            return;
        }

        document.getElementById('songTitle').value = '';
        document.getElementById('artistName').value = '';
        showToast(data.position ? `Estás en el turno #${data.position}` : data.message);
        fetchQueue();
        document.getElementById('songTitle').focus();
    } catch (error) {
        setFieldError('songError', 'Error de conexión');
    }
}

// Night Code Logic
async function updateNightCode() {
    const val = document.getElementById('newNightCode').value.trim().toUpperCase();
    if (!val) return;

    try {
        const response = await fetch('api.php?action=update_night_code', {
            method: 'POST',
            headers: adminFetchHeaders(),
            body: JSON.stringify({ newCode: val })
        });
        const data = await response.json();
        if (data.success) {
            nightCode = val;
            codeLabel.innerText = nightCode;
            document.getElementById('newNightCode').value = '';
            showToast(data.message);
        } else {
            showToast(data.message, "error");
        }
    } catch (error) {
        showToast("Error de conexión", "error");
    }
}

async function updateStaffPin() {
    const val = document.getElementById('newStaffPin').value.trim();
    if (val.length !== 6 || isNaN(val)) {
        showToast("El PIN debe ser de 6 dígitos", "error");
        return;
    }

    if (!confirm("¿Estás seguro de que deseas cambiar el PIN de acceso Staff?")) return;

    try {
        const response = await fetch('api.php?action=update_staff_pin', {
            method: 'POST',
            headers: adminFetchHeaders(),
            body: JSON.stringify({ newPin: val })
        });
        const data = await response.json();
        if (data.success) {
            document.getElementById('newStaffPin').value = '';
            showToast(data.message);
        } else {
            showToast(data.message, "error");
        }
    } catch (error) {
        showToast("Error de conexión", "error");
    }
}

// Admin/Staff Logic
document.getElementById('toggleAdminBtn').addEventListener('click', () => {
    if (isAdmin) { logoutAdmin(); }
    else {
        loginModal.style.display = 'flex';
        document.getElementById('adminPin').focus();
    }
});

function closeLogin() {
    loginModal.style.display = 'none';
    document.getElementById('adminPin').value = '';
}

async function checkPin() {
    const pin = document.getElementById('adminPin').value;
    try {
        const response = await fetch('api.php?action=login', {
            method: 'POST',
            headers: adminFetchHeaders(),
            body: JSON.stringify({ pin })
        });
        const data = await response.json();
        if (data.success) {
            isAdmin = true;
            updateUIForAdmin();
            closeLogin();
            showToast(data.message);
            fetchQueue();
        } else {
            showToast(data.message, "error");
            document.getElementById('adminPin').value = '';
        }
    } catch (error) {
        showToast("Error de conexión", "error");
    }
}

async function logoutAdmin() {
    try {
        const response = await fetch('api.php?action=logout', {
            method: 'POST',
            headers: { 'X-CSRF-Token': csrfToken }
        });
        const data = await response.json();
        if (data.success) {
            isAdmin = false;
            updateUIForAdmin();
            showToast(data.message);
            fetchQueue();
        }
    } catch (error) {
        showToast("Error de conexión", "error");
    }
}

function adminFetchHeaders() {
    return { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken };
}

function showToast(msg, type = "success") {
    const toast = document.getElementById('toast');
    const icon = document.getElementById('toastIcon');
    document.getElementById('toastMsg').innerText = msg;
    icon.className = `w-2 h-2 rounded-full ${type === 'success' ? 'bg-[#329bac]' : 'bg-[#ce2b47]'}`;
    toast.classList.replace('opacity-0', 'opacity-100');
    toast.classList.add('translate-y-4');
    setTimeout(() => {
        toast.classList.replace('opacity-100', 'opacity-0');
        toast.classList.remove('translate-y-4');
    }, 3000);
}

function renderQueue(queue) {
    const scrollTop = queueList.scrollTop;
    queueList.innerHTML = '';
    queueBadge.innerText = queue.length;

    // Show/Hide reaction panel Based on whether there is someone singing
    if (queue.length > 0) {
        reactionPanel.classList.remove('hidden-panel');
    } else {
        reactionPanel.classList.add('hidden-panel');
    }

    if (queue.length === 0) {
        queueList.innerHTML = `
            <div class="flex flex-col items-center justify-center py-20 opacity-20 text-center px-10">
                <img src="https://latrillacultural.com/img/logo-circular.webp" alt="Logo La Trilla" class="w-32 h-32 mb-6 grayscale">
                <p class="text-xl font-bold title-font text-[#ce2b47]">Lista Vacía</p>
                <p class="text-sm">Ingresa el código del local para anotarte.</p>
            </div>
        `;
        setupSortable();
        queueList.scrollTop = scrollTop;
        return;
    }

    queue.forEach((item, index) => {
        const div = document.createElement('div');
        div.className = `p-5 rounded-2xl flex items-center justify-between border border-white/5 transition-all ${index === 0 ? 'queue-item-active shadow-[0_0_20px_rgba(249,175,83,0.1)]' : 'bg-white/5'}`;
        div.setAttribute('data-id', item.id); // For SortableJS

        const adminButtons = isAdmin ? `
            <div class="flex items-center gap-4">
                <div class="drag-handle cursor-move text-slate-500 hover:text-white transition-colors p-2">
                    <i class="fas fa-bars"></i>
                </div>
                <button type="button" data-action="remove" data-id="${item.id}" class="flex items-center gap-2 px-4 py-2 bg-[#ce2b47]/20 text-[#ce2b47] hover:bg-[#ce2b47] hover:text-white rounded-xl transition-all font-bold text-xs ml-2">
                    <i class="fas fa-check"></i> LISTO
                </button>
            </div>
        ` : `
            <div class="flex flex-col items-end">
                <span class="text-[10px] text-slate-500 font-black uppercase tracking-widest">${index === 0 ? 'Cantando ahora' : 'En espera'}</span>
                <i class="fas ${index === 0 ? 'fa-volume-high text-[#f9af53]' : 'fa-clock text-slate-700'} mt-1"></i>
            </div>
        `;

        div.innerHTML = `
            <div class="flex items-center gap-5">
                <div class="w-12 h-12 rounded-2xl ${index === 0 ? 'bg-[#f9af53] text-black' : 'bg-white/10 text-white'} flex items-center justify-center font-black text-xl">
                    ${index + 1}
                </div>
                <div>
                    <h4 class="font-bold text-white text-lg leading-tight">${escapeHTML(item.user_name)}</h4>
                    <p class="text-sm mt-0.5">
                        <span class="text-[#329bac] font-bold">${escapeHTML(item.song_title)}</span> 
                        <span class="text-slate-500 mx-1">/</span> 
                        <span class="text-slate-400 MontserratRegular">${escapeHTML(item.artist_name)}</span>
                    </p>
                </div>
            </div>
            ${adminButtons}
        `;
        queueList.appendChild(div);
    });

    setupSortable();
    queueList.scrollTop = scrollTop;
}

/**
 * Crea SortableJS una sola vez por sesión de admin (el contenedor no cambia)
 * y lo destruye al salir del modo staff.
 */
function setupSortable() {
    if (isAdmin) {
        if (sortableInstance) return;
        sortableInstance = new Sortable(queueList, {
            animation: 150,
            handle: '.drag-handle',
            ghostClass: 'bg-white/10',
            onStart: () => {
                isDragging = true;
            },
            onEnd: () => {
                isDragging = false;
                const newOrderIds = Array.from(queueList.children).map(el => el.getAttribute('data-id'));
                updateOrder(newOrderIds);
            }
        });
    } else if (sortableInstance) {
        sortableInstance.destroy();
        sortableInstance = null;
    }
}

async function autoReorderQueue() {
    if (!isAdmin) return;
    if (!confirm("¿Deseas optimizar la cola automáticamente? Esto evitará que canten dos veces seguidas y adelantará a los nuevos participantes.")) return;

    try {
        const response = await fetch('api.php?action=auto_reorder', {
            method: 'POST',
            headers: adminFetchHeaders()
        });
        const data = await response.json();
        if (data.success) {
            lastQueueSignature = ""; // fuerza repintado con el orden del servidor
            isDragging = false;
            fetchQueue();
            showToast(data.message);
        } else {
            showToast(data.message, "error");
        }
    } catch (error) {
        showToast("Error de conexión", "error");
    }
}

async function updateOrder(orderedIds) {
    try {
        const response = await fetch('api.php?action=reorder_queue', {
            method: 'POST',
            headers: adminFetchHeaders(),
            body: JSON.stringify({ orderedIds })
        });
        const data = await response.json();
        if (!data.success) {
            showToast(data.message, "error");
            lastQueueSignature = ""; // fuerza el repintado al revertir
            fetchQueue(); // Revert
        }
    } catch (error) {
        showToast("Error de conexión", "error");
        lastQueueSignature = "";
        fetchQueue();
    }
}

async function removeFromQueue(id) {
    if (!isAdmin) return;
    try {
        const response = await fetch('api.php?action=remove_from_queue', {
            method: 'POST',
            headers: adminFetchHeaders(),
            body: JSON.stringify({ id })
        });
        const data = await response.json();
        if (data.success) {
            lastQueueSignature = "";
            fetchQueue();
            showToast(data.message);
        }
    } catch (error) {
        showToast("Error de conexión", "error");
    }
}

form.addEventListener('submit', (event) => {
    event.preventDefault();
    if (participantName) {
        addSong();
    } else {
        validateCode();
    }
});

document.getElementById('changeParticipant').addEventListener('click', async () => {
    if (!confirm("¿Otra persona pedirá turnos desde este dispositivo?")) return;

    try {
        const response = await fetch('api.php?action=reset_participant', {
            method: 'POST',
            headers: adminFetchHeaders()
        });
        const data = await response.json();

        if (!data.success) {
            showToast(data.message, "error");
            return;
        }

        participantName = null;
        applyParticipantState();
        document.getElementById('userName').value = '';
        document.getElementById('accessCode').value = '';
        document.getElementById('userName').focus();
        fetchQueue();
    } catch (error) {
        showToast("Error de conexión", "error");
    }
});

document.getElementById('clearAll').addEventListener('click', async () => {
    if (isAdmin && confirm("¿Deseas reiniciar toda la lista de hoy?")) {
        try {
            const response = await fetch('api.php?action=clear_queue', {
                method: 'POST',
                headers: { 'X-CSRF-Token': csrfToken }
            });
            const data = await response.json();
            if (data.success) {
                lastQueueSignature = "";
                fetchQueue();
                showToast(data.message);
            }
        } catch (error) {
            showToast("Error de conexión", "error");
        }
    }
});

// Delegación de eventos: el HTML solo lleva data-action (compatible con CSP
// sin 'unsafe-inline' y sin handlers inline en index.php).
document.addEventListener('click', (event) => {
    const target = event.target.closest('[data-action]');
    if (!target) return;

    switch (target.dataset.action) {
        case 'update-night-code':
            updateNightCode();
            break;
        case 'update-staff-pin':
            updateStaffPin();
            break;
        case 'auto-reorder':
            autoReorderQueue();
            break;
        case 'login':
            checkPin();
            break;
        case 'close-login':
            closeLogin();
            break;
        case 'reaction':
            sendReaction(target.dataset.emoji);
            break;
        case 'remove':
            removeFromQueue(Number(target.dataset.id));
            break;
    }
});

document.getElementById('registrationToggle').addEventListener('change', toggleRegistration);

document.getElementById('adminPin').addEventListener('keydown', (event) => {
    if (event.key === 'Enter') {
        event.preventDefault();
        checkPin();
    }
});

function escapeHTML(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

// Init
init();
