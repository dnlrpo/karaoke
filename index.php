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

    <script src="script.js"></script>
</body>

</html>
