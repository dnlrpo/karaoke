let sortableInstance = null;
let isAdmin = false;
let nightCode = "";
let currentQueue = [];
let registrationEnabled = true;
let accessCodeValidated = false;
let lastReactionId = 0;

const form = document.getElementById('karaokeForm');
const queueList = document.getElementById('queueList');
const queueBadge = document.getElementById('queueBadge');
const loginModal = document.getElementById('loginModal');
const codeLabel = document.getElementById('currentCodeLabel');
const reactionPanel = document.getElementById('reactionPanel');

// Initial Load
async function init() {
    await fetchQueue();
    // Polling for updates
    setInterval(fetchQueue, 5000);
    // Polling for reactions faster
    setInterval(fetchReactions, 2000);
}

async function fetchQueue() {
    try {
        const response = await fetch('api.php?action=get_queue');
        const data = await response.json();
        if (data.success) {
            currentQueue = data.queue;
            renderQueue(data.queue);
            isAdmin = data.is_admin;
            registrationEnabled = data.registration_enabled;
            accessCodeValidated = data.access_code_validated || false;
            updateUIForAdmin();
            updateRegistrationUI();
            updateAccessCodeUI();
            if (isAdmin && data.night_code) {
                nightCode = data.night_code;
                codeLabel.innerText = nightCode;
            }
        }
    } catch (error) {
        console.error("Error fetching queue:", error);
    }
}

async function fetchReactions() {
    try {
        const response = await fetch(`api.php?action=get_reactions&since=${lastReactionId}`);
        const data = await response.json();
        if (data.success && data.reactions.length > 0) {
            data.reactions.forEach(reaction => {
                // If it's a reaction from someone else (we don't track who sent what, but we can avoid showing twice the one we just sent if we wanted, 
                // but usually in these systems it's fine to see your own reaction pop up if the server confirms it).
                // Actually, since we do optimistic UI in sendReaction, we should ideally skip it here if we track sent IDs, 
                // but simpler is just show all from server and maybe avoid optimistic UI if it's too fast.
                createReactionParticle(reaction.emoji);
                lastReactionId = Math.max(lastReactionId, reaction.id);
            });
        }
    } catch (error) {
        console.error("Error fetching reactions:", error);
    }
}

async function sendReaction(emoji) {
    try {
        // Optimistic UI
        createReactionParticle(emoji);

        await fetch('api.php?action=send_reaction', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ emoji })
        });
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
            headers: { 'Content-Type': 'application/json' },
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

function updateAccessCodeUI() {
    const accessCodeField = document.getElementById('accessCodeField');
    const accessCodeValidatedDiv = document.getElementById('accessCodeValidated');
    const accessCodeInput = document.getElementById('accessCode');

    if (accessCodeValidated) {
        accessCodeField.classList.add('hidden');
        accessCodeValidatedDiv.classList.remove('hidden');
        if (accessCodeInput) {
            accessCodeInput.removeAttribute('required');
        }
    } else {
        accessCodeField.classList.remove('hidden');
        accessCodeValidatedDiv.classList.add('hidden');
        if (accessCodeInput) {
            accessCodeInput.setAttribute('required', 'required');
        }
    }
}

// Night Code Logic
async function updateNightCode() {
    const val = document.getElementById('newNightCode').value.trim().toUpperCase();
    if (!val) return;

    try {
        const response = await fetch('api.php?action=update_night_code', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
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
    if (val.length !== 4 || isNaN(val)) {
        showToast("El PIN debe ser de 4 dígitos", "error");
        return;
    }

    if (!confirm("¿Estás seguro de que deseas cambiar el PIN de acceso Staff?")) return;

    try {
        const response = await fetch('api.php?action=update_staff_pin', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
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
            headers: { 'Content-Type': 'application/json' },
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
        const response = await fetch('api.php?action=logout');
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
                <img src="https://latrillacultural.com/img/logo-circular.webp" class="w-32 h-32 mb-6 grayscale">
                <p class="text-xl font-bold">LISTA VACÍA</p>
                <p class="text-sm">Ingresa el código del local para anotarte.</p>
            </div>
        `;
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
                <button onclick="removeFromQueue(${item.id})" class="flex items-center gap-2 px-4 py-2 bg-[#ce2b47]/20 text-[#ce2b47] hover:bg-[#ce2b47] hover:text-white rounded-xl transition-all font-bold text-xs ml-2">
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
                        <span class="${index === 0 ? 'text-[#f9af53]' : 'text-[#329bac]'} font-bold">${escapeHTML(item.song_title)}</span> 
                        <span class="text-slate-500 mx-1">/</span> 
                        <span class="text-slate-400">${escapeHTML(item.artist_name)}</span>
                    </p>
                </div>
            </div>
            ${adminButtons}
        `;
        queueList.appendChild(div);
    });

    if (isAdmin) {
        if (sortableInstance) sortableInstance.destroy();
        sortableInstance = new Sortable(queueList, {
            animation: 150,
            handle: '.drag-handle',
            ghostClass: 'bg-white/10',
            onEnd: function (evt) {
                const newOrderIds = Array.from(queueList.children).map(el => el.getAttribute('data-id'));
                updateOrder(newOrderIds);
            }
        });
    } else {
        if (sortableInstance) {
            sortableInstance.destroy();
            sortableInstance = null;
        }
    }
}

async function autoReorderQueue() {
    if (!isAdmin) return;
    if (!confirm("¿Deseas optimizar la cola automáticamente? Esto evitará que canten dos veces seguidas y adelantará a los nuevos participantes.")) return;

    try {
        const response = await fetch('api.php?action=auto_reorder', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' }
        });
        const data = await response.json();
        if (data.success) {
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
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ orderedIds })
        });
        const data = await response.json();
        if (!data.success) {
            showToast(data.message, "error");
            fetchQueue(); // Revert
        }
    } catch (error) {
        showToast("Error de conexión", "error");
        fetchQueue();
    }
}

async function removeFromQueue(id) {
    if (!isAdmin) return;
    try {
        const response = await fetch('api.php?action=remove_from_queue', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id })
        });
        const data = await response.json();
        if (data.success) {
            fetchQueue();
            showToast(data.message);
        }
    } catch (error) {
        showToast("Error de conexión", "error");
    }
}

form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const payload = {
        userName: document.getElementById('userName').value,
        songTitle: document.getElementById('songTitle').value,
        artistName: document.getElementById('artistName').value,
        accessCode: accessCodeValidated ? '' : document.getElementById('accessCode').value
    };

    try {
        const response = await fetch('api.php?action=add_to_queue', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const data = await response.json();
        if (data.success) {
            fetchQueue();
            form.reset();
            showToast(data.message);
        } else {
            showToast(data.message, "error");
            if (data.message.includes("Código")) {
                document.getElementById('accessCode').focus();
            }
        }
    } catch (error) {
        showToast("Error de conexión", "error");
    }
});

document.getElementById('clearAll').addEventListener('click', async () => {
    if (isAdmin && confirm("¿Deseas reiniciar toda la lista de hoy?")) {
        try {
            const response = await fetch('api.php?action=clear_queue');
            const data = await response.json();
            if (data.success) {
                fetchQueue();
                showToast(data.message);
            }
        } catch (error) {
            showToast("Error de conexión", "error");
        }
    }
});

function escapeHTML(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

// Init
init();
