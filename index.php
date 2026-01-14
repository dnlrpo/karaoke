<?php
require_once __DIR__ . '/db.php';
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Karaoke La Trilla Cultural</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Sortable/1.15.0/Sortable.min.js"></script>
    <link rel="stylesheet" href="style.css">
</head>

<body class="p-4 md:p-8">

    <div class="max-w-5xl mx-auto space-y-8">

        <!-- Header -->
        <header class="flex flex-col md:flex-row justify-between items-center gap-6">
            <div class="flex items-center gap-4">
                <img src="https://latrillacultural.com/img/logo-circular.webp" alt="Logo La Trilla"
                    class="w-16 h-16 logo-img">
                <div>
                    <h1 class="text-3xl font-bold tracking-tight">KARAOKE <span class="text-[#f9af53]">LA TRILLA</span>
                    </h1>
                    <div class="flex items-center gap-2 mt-1">
                        <p class="text-slate-400 text-sm">Cultura y Música</p>
                        <span id="adminStatusBadge" class="admin-badge hidden">MODO STAFF</span>
                    </div>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <button id="toggleAdminBtn"
                    class="glass-card px-4 py-2 hover:bg-white/5 transition-all flex items-center gap-2 text-sm border-white/20">
                    <i id="adminIcon" class="fas fa-lock text-slate-500"></i>
                    <span id="adminBtnText" class="font-semibold">Acceso Staff</span>
                </button>
            </div>
        </header>

        <div class="grid grid-cols-1 lg:grid-cols-12 gap-8">

            <!-- Formulario y Ajustes -->
            <div class="lg:col-span-5 space-y-6">
                <!-- Registro (Asistentes) -->
                <section id="registrationSection" class="glass-card p-8 shadow-2xl border-t-4 border-[#329bac]">
                    <h2 class="text-xl font-bold mb-6 flex items-center gap-2 text-white">
                        <i class="fas fa-play-circle text-[#f9af53]"></i> Pide tu Turno
                    </h2>
                    <form id="karaokeForm" class="space-y-4">
                        <div>
                            <label
                                class="text-[10px] uppercase tracking-widest text-slate-500 font-bold mb-1 block">Nombre
                                / Alias</label>
                            <input type="text" id="userName" required placeholder="¿Cómo te llamamos?"
                                class="input-field w-full rounded-xl px-4 py-3 transition-all">
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label
                                    class="text-[10px] uppercase tracking-widest text-slate-500 font-bold mb-1 block">Canción</label>
                                <input type="text" id="songTitle" required placeholder="Título"
                                    class="input-field w-full rounded-xl px-4 py-3 transition-all">
                            </div>
                            <div>
                                <label
                                    class="text-[10px] uppercase tracking-widest text-slate-500 font-bold mb-1 block">Artista</label>
                                <input type="text" id="artistName" required placeholder="Artista"
                                    class="input-field w-full rounded-xl px-4 py-3 transition-all">
                            </div>
                        </div>
                        <div id="accessCodeField" class="pt-2">
                            <label
                                class="text-[10px] uppercase tracking-widest text-[#f9af53] font-bold mb-1 block">Código
                                de la Noche</label>
                            <input type="text" id="accessCode" placeholder="Ingresa el código del local"
                                class="input-field w-full rounded-xl px-4 py-3 transition-all border-[#f9af53]/30">
                        </div>
                        <div id="accessCodeValidated" class="pt-2 hidden">
                            <div class="bg-[#329bac]/10 border border-[#329bac]/30 rounded-xl px-4 py-3 flex items-center gap-2">
                                <i class="fas fa-check-circle text-[#329bac]"></i>
                                <span class="text-sm text-[#329bac] font-bold">Código validado ✓</span>
                            </div>
                        </div>

                        <button type="submit"
                            class="btn-primary w-full py-4 rounded-xl font-bold text-lg shadow-xl active:scale-95 mt-2">
                            Anotarme en la lista
                        </button>
                    </form>
                    <div id="registrationClosedMessage" class="hidden text-center py-8">
                        <i class="fas fa-lock text-slate-500 text-4xl mb-4"></i>
                        <p class="text-slate-400 text-lg font-bold">El registro está cerrado en este momento</p>
                        <p class="text-slate-500 text-sm mt-2">Vuelve en los días de karaoke</p>
                    </div>
                </section>

                <!-- Panel de Control Staff (Solo Visible en Admin Mode) -->
                <section class="glass-card p-6 shadow-2xl border-t-4 border-[#f9af53] admin-only flex-col">
                    <h2 class="text-lg font-bold mb-6 text-[#f9af53] text-center w-full">
                        <i class="fas fa-cog text-3xl block mb-3 mx-auto"></i> 
                        <span class="tracking-tight block">Configuración de Hoy</span>
                    </h2>
                    <div class="space-y-4">
                        <div>
                            <label class="text-xs text-slate-400 mb-1 block">Código para clientes:</label>
                            <div class="flex flex-col sm:flex-row gap-2 mb-3">
                                <input type="text" id="newNightCode" placeholder="Nuevo código"
                                    class="input-field flex-grow rounded-lg px-3 py-2 text-sm">
                                <button onclick="updateNightCode()"
                                    class="bg-[#f9af53] text-black px-4 py-2 rounded-lg font-bold text-xs hover:bg-white transition-all whitespace-nowrap">
                                    CAMBIAR
                                </button>
                            </div>
                            <div class="bg-white/5 p-3 rounded-xl border border-white/5 flex flex-wrap justify-between items-center gap-2">
                                <p class="text-[10px] text-slate-500 italic">Actual: <span id="currentCodeLabel"
                                        class="text-white font-bold tracking-widest break-all"></span></p>
                            </div>
                        </div>

                        <!-- Cambio de PIN -->
                        <div class="pt-4 border-t border-white/5">
                            <label class="text-xs text-slate-400 mb-1 block">Cambiar PIN Staff (4 dígitos):</label>
                            <div class="flex flex-col sm:flex-row gap-2">
                                <input type="password" id="newStaffPin" placeholder="Nuevo PIN" maxlength="4"
                                    class="input-field flex-grow rounded-lg px-3 py-2 text-sm text-center tracking-[0.3em] min-w-0">
                                <button onclick="updateStaffPin()"
                                    class="bg-white/10 text-white px-4 py-2 rounded-lg font-bold text-xs hover:bg-[#329bac] transition-all border border-white/10 whitespace-nowrap">
                                    GUARDAR
                                </button>
                            </div>
                        </div>

                        <!-- Toggle Registro -->
                        <div class="pt-4 border-t border-white/5">
                            <div class="flex items-center justify-between">
                                <label class="text-xs text-slate-400">Habilitar Registro:</label>
                                <label class="relative inline-flex items-center cursor-pointer">
                                    <input type="checkbox" id="registrationToggle" class="sr-only peer" onchange="toggleRegistration()">
                                    <div class="w-11 h-6 bg-slate-700 peer-focus:outline-none peer-focus:ring-2 peer-focus:ring-[#f9af53] rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-[#329bac]"></div>
                                </label>
                            </div>
                        </div>
                    </div>
                </section>

                <div class="text-center p-2">
                    <p class="text-slate-500 text-xs italic">"Donde la música se encuentra con la cultura"</p>
                </div>
            </div>

            <!-- Lista de Cola -->
            <div class="lg:col-span-7">
                <section class="glass-card h-full flex flex-col shadow-2xl min-h-[500px] border-white/10">
                    <div
                        class="p-6 border-b border-white/10 flex justify-between items-center bg-white/5 rounded-t-2xl">
                        <h2 class="text-xl font-bold flex items-center gap-3">
                            <i class="fas fa-list-ul text-[#f9af53]"></i> Próximos Cantantes
                            <span id="queueBadge"
                                class="bg-[#ce2b47] text-[10px] px-2 py-1 rounded-md font-black uppercase">0</span>
                        </h2>
                        <button id="clearAll"
                            class="text-slate-500 hover:text-[#ce2b47] text-sm transition-colors hidden admin-only-inline">
                            <i class="fas fa-trash-can mr-1"></i> Reiniciar Todo
                        </button>
                    </div>

                    <div id="queueList" class="flex-grow overflow-y-auto p-4 space-y-4">
                        <!-- Lista dinámica -->
                    </div>
                </section>
            </div>
        </div>
    </div>

    <!-- Modal Admin Staff -->
    <div id="loginModal"
        class="fixed inset-0 bg-black/90 backdrop-blur-md hidden items-center justify-center z-[100] p-4">
        <div
            class="glass-card p-10 w-full max-w-sm border-[#329bac]/50 shadow-[0_0_50px_rgba(50,155,172,0.2)] text-center">
            <img src="https://latrillacultural.com/img/logo-circular.webp" class="w-20 h-20 mx-auto mb-6 opacity-80"
                alt="Logo">
            <h3 class="text-2xl font-bold mb-2">Acceso Staff</h3>
            <p class="text-slate-400 text-sm mb-8">Uso exclusivo del personal</p>
            <input type="password" id="adminPin" placeholder="PIN" maxlength="4"
                class="w-full bg-white/5 border border-white/10 rounded-2xl px-4 py-4 text-center text-3xl tracking-[0.8em] focus:border-[#f9af53] outline-none mb-6">
            <div class="flex flex-col gap-3">
                <button onclick="checkPin()"
                    class="w-full py-4 bg-[#f9af53] text-black rounded-xl font-black text-lg hover:bg-white transition-all">ACCEDER</button>
                <button onclick="closeLogin()"
                    class="w-full py-2 text-slate-500 hover:text-white transition-all text-sm">CANCELAR</button>
            </div>
        </div>
    </div>

    <!-- Toast Feedback -->
    <div id="toast"
        class="fixed bottom-10 left-1/2 -translate-x-1/2 bg-white text-black font-bold px-8 py-4 rounded-2xl shadow-[0_10px_40px_rgba(0,0,0,0.5)] opacity-0 transition-all duration-300 pointer-events-none z-50 flex items-center gap-3">
        <div id="toastIcon" class="w-2 h-2 rounded-full bg-[#329bac]"></div>
        <span id="toastMsg"></span>
    </div>

    <script>
        let sortableInstance = null;
        let isAdmin = false;
        let nightCode = "";
        let currentQueue = [];
        let registrationEnabled = true;
        let accessCodeValidated = false;

        const form = document.getElementById('karaokeForm');
        const queueList = document.getElementById('queueList');
        const queueBadge = document.getElementById('queueBadge');
        const loginModal = document.getElementById('loginModal');
        const codeLabel = document.getElementById('currentCodeLabel');

        // Initial Load
        async function init() {
            await fetchQueue();
            // Polling for updates
            setInterval(fetchQueue, 5000);
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
            toast.classList.add('-translate-y-4');
            setTimeout(() => {
                toast.classList.replace('opacity-100', 'opacity-0');
                toast.classList.remove('-translate-y-4');
            }, 3000);
        }

        function renderQueue(queue) {
            queueList.innerHTML = '';
            queueBadge.innerText = queue.length;

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
    </script>
</body>

</html>
