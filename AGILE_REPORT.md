# 📊 Informe Ágil: Épicas y Features
## Aplicación de Karaoke - La Trilla Cultural

**Fecha de Generación:** 20 de Enero, 2026  
**Versión:** 1.0  
**Metodología:** Scrum/Ágil

---

## 📋 Tabla de Contenidos

1. [Resumen Ejecutivo](#resumen-ejecutivo)
2. [Arquitectura General](#arquitectura-general)
3. [Épicas del Producto](#épicas-del-producto)
4. [Features Detalladas](#features-detalladas)
5. [Backlog Priorizado](#backlog-priorizado)
6. [Métricas y KPIs](#métricas-y-kpis)

---

## 🎯 Resumen Ejecutivo

**Karaoke La Trilla Cultural** es una aplicación web de gestión de turnos para eventos de karaoke en tiempo real. Permite a los asistentes registrarse para cantar mediante un código de acceso diario, mientras que el personal del local puede administrar la cola, reordenar participantes y controlar el flujo del evento.

### Propuesta de Valor
- **Para Asistentes:** Experiencia fluida de registro y visualización de turnos en tiempo real
- **Para Staff:** Control total del evento con herramientas de administración intuitivas
- **Para el Negocio:** Sistema escalable que mejora la experiencia del cliente y optimiza la operación

### Stack Tecnológico
- **Frontend:** HTML5, JavaScript (ES6+), TailwindCSS
- **Backend:** PHP 7.4+, MySQL/MariaDB
- **Librerías:** SortableJS, Font Awesome
- **Arquitectura:** REST API, Polling en tiempo real

---

## 🏗️ Arquitectura General

```
┌─────────────────────────────────────────────────────────┐
│                    FRONTEND (SPA-like)                  │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐ │
│  │  index.php   │  │  script.js   │  │  style.css   │ │
│  │  (UI Layer)  │  │ (Logic Layer)│  │ (Presentation)│ │
│  └──────────────┘  └──────────────┘  └──────────────┘ │
└─────────────────────────────────────────────────────────┘
                           ↕ REST API
┌─────────────────────────────────────────────────────────┐
│                    BACKEND (API)                        │
│  ┌──────────────┐  ┌──────────────┐                    │
│  │   api.php    │  │   db.php     │                    │
│  │ (Controller) │  │ (Data Layer) │                    │
│  └──────────────┘  └──────────────┘                    │
└─────────────────────────────────────────────────────────┘
                           ↕
┌─────────────────────────────────────────────────────────┐
│                  DATABASE (MySQL)                       │
│  Tables: songs, admins, settings, reactions             │
└─────────────────────────────────────────────────────────┘
```

---

## 🎪 Épicas del Producto

### **ÉPICA 1: Gestión de Participantes** 🎤
**Descripción:** Sistema completo para que los asistentes puedan registrarse, ver su turno y participar en el evento de karaoke.

**Objetivos de Negocio:**
- Reducir fricción en el proceso de registro
- Aumentar participación de asistentes
- Mejorar experiencia del usuario

**Criterios de Éxito:**
- ✅ 90%+ de registros exitosos sin intervención del staff
- ✅ Tiempo promedio de registro < 30 segundos
- ✅ Satisfacción del usuario > 4/5

**Historias de Usuario Relacionadas:**
- Como asistente, quiero registrarme con mi nombre y canción para participar
- Como asistente, quiero ver mi posición en la cola en tiempo real
- Como asistente, quiero saber cuándo es mi turno

---

### **ÉPICA 2: Panel de Administración Staff** 👨‍💼
**Descripción:** Herramientas de control y gestión para el personal del local que opera el evento de karaoke.

**Objetivos de Negocio:**
- Optimizar operación del evento
- Dar flexibilidad al staff para manejar situaciones especiales
- Mantener seguridad y control de acceso

**Criterios de Éxito:**
- ✅ Staff puede reordenar cola en < 5 segundos
- ✅ Cambio de configuración refleja inmediatamente
- ✅ Zero accesos no autorizados

**Historias de Usuario Relacionadas:**
- Como staff, quiero autenticarme de forma segura para acceder al panel
- Como staff, quiero reordenar la cola de participantes
- Como staff, quiero marcar participantes como completados
- Como staff, quiero cambiar el código de acceso diario

---

### **ÉPICA 3: Sistema de Seguridad y Control de Acceso** 🔐
**Descripción:** Mecanismos de autenticación, autorización y validación para proteger la aplicación y controlar el acceso.

**Objetivos de Negocio:**
- Prevenir registros no autorizados
- Proteger funciones administrativas
- Permitir control por evento/día

**Criterios de Éxito:**
- ✅ Solo asistentes con código válido pueden registrarse
- ✅ Solo staff autenticado accede a funciones admin
- ✅ Rate limiting previene ataques de fuerza bruta

**Historias de Usuario Relacionadas:**
- Como organizador, quiero un código único por evento para controlar acceso
- Como sistema, quiero validar códigos de acceso antes de permitir registro
- Como sistema, quiero proteger el panel admin con PIN de 4 dígitos

---

### **ÉPICA 4: Experiencia en Tiempo Real** ⚡
**Descripción:** Funcionalidades que mantienen la aplicación actualizada y sincronizada para todos los usuarios sin necesidad de recargar.

**Objetivos de Negocio:**
- Crear sensación de "app nativa"
- Reducir confusión sobre estado actual
- Aumentar engagement

**Criterios de Éxito:**
- ✅ Actualizaciones visibles en < 5 segundos
- ✅ No se requiere refresh manual
- ✅ Sincronización entre múltiples dispositivos

**Historias de Usuario Relacionadas:**
- Como usuario, quiero ver actualizaciones de la cola automáticamente
- Como asistente, quiero enviar reacciones en vivo durante presentaciones
- Como usuario, quiero ver reacciones de otros en tiempo real

---

### **ÉPICA 5: Configuración y Personalización** ⚙️
**Descripción:** Opciones de configuración que permiten adaptar la aplicación a diferentes eventos y necesidades operativas.

**Objetivos de Negocio:**
- Flexibilidad para diferentes tipos de eventos
- Reducir intervención técnica
- Permitir auto-gestión del staff

**Criterios de Éxito:**
- ✅ Staff puede configurar evento sin soporte técnico
- ✅ Cambios de configuración persisten entre sesiones
- ✅ Configuración intuitiva y sin errores

**Historias de Usuario Relacionadas:**
- Como staff, quiero habilitar/deshabilitar registro según fase del evento
- Como staff, quiero cambiar el PIN de acceso por seguridad
- Como staff, quiero reiniciar la lista para un nuevo evento

---

## 🎯 Features Detalladas

### **FEATURE 1.1: Registro de Participantes** 
**Épica:** Gestión de Participantes  
**Prioridad:** 🔴 CRÍTICA  
**Esfuerzo Estimado:** 8 Story Points

#### Descripción
Formulario de registro que permite a los asistentes ingresar su información y la canción que desean interpretar.

#### Criterios de Aceptación
- [ ] El formulario solicita: Nombre/Alias, Título de Canción, Artista
- [ ] Todos los campos son obligatorios
- [ ] Se valida código de acceso antes de permitir registro
- [ ] Feedback visual inmediato (éxito/error)
- [ ] Formulario se limpia después de registro exitoso
- [ ] Registro se añade al final de la cola automáticamente

#### Detalles Técnicos
- **Endpoint:** `POST /api.php?action=add_to_queue`
- **Validaciones:** 
  - Campos no vacíos
  - Código de acceso coincide con código del día
  - Registro está habilitado
- **Tabla DB:** `songs` (user_name, song_title, artist_name, sort_order, status)

#### Dependencias
- Feature 3.1 (Validación de Código de Acceso)
- Feature 5.1 (Toggle de Registro)

---

### **FEATURE 1.2: Visualización de Cola en Tiempo Real**
**Épica:** Gestión de Participantes  
**Prioridad:** 🔴 CRÍTICA  
**Esfuerzo Estimado:** 5 Story Points

#### Descripción
Lista visual que muestra todos los participantes en orden, destacando quién está cantando actualmente.

#### Criterios de Aceptación
- [ ] Muestra posición numérica de cada participante
- [ ] Destaca visualmente al participante actual (primer lugar)
- [ ] Actualiza automáticamente cada 5 segundos
- [ ] Muestra contador de participantes en espera
- [ ] Diseño responsive (móvil y desktop)
- [ ] Estado vacío con mensaje informativo

#### Detalles Técnicos
- **Endpoint:** `GET /api.php?action=get_queue`
- **Polling:** Intervalo de 5000ms
- **Estados:** `waiting`, `singing`, `finished`
- **Ordenamiento:** Por `sort_order ASC, id ASC`

#### Diseño UI/UX
- Primer participante: fondo dorado (#f9af53), ícono de volumen
- Resto: fondo translúcido, ícono de reloj
- Animaciones suaves en transiciones

---

### **FEATURE 1.3: Sistema de Reacciones en Vivo** 
**Épica:** Experiencia en Tiempo Real  
**Prioridad:** 🟡 MEDIA  
**Esfuerzo Estimado:** 5 Story Points

#### Descripción
Panel flotante que permite a los asistentes enviar emojis de reacción durante las presentaciones, visibles para todos.

#### Criterios de Aceptación
- [ ] Panel visible solo cuando hay participantes en cola
- [ ] Ofrece 5 emojis de reacción: 👏 🔥 ❤️ 🤩 🙌
- [ ] Reacciones aparecen como partículas animadas
- [ ] Sincronización entre todos los dispositivos conectados
- [ ] Polling cada 2 segundos para nuevas reacciones
- [ ] Animación de 3 segundos antes de desaparecer

#### Detalles Técnicos
- **Endpoints:** 
  - `POST /api.php?action=send_reaction`
  - `GET /api.php?action=get_reactions&since={id}`
- **Tabla DB:** `reactions` (id, emoji, created_at)
- **Optimización:** Solo recupera reacciones de últimos 30 segundos

#### Diseño UI/UX
- Panel flotante en esquina inferior derecha
- Partículas con trayectoria aleatoria y rotación
- Optimistic UI: muestra reacción inmediatamente

---

### **FEATURE 2.1: Autenticación de Staff**
**Épica:** Sistema de Seguridad y Control de Acceso  
**Prioridad:** 🔴 CRÍTICA  
**Esfuerzo Estimado:** 8 Story Points

#### Descripción
Sistema de login con PIN de 4 dígitos para acceso al panel administrativo.

#### Criterios de Aceptación
- [ ] Modal de login con campo de PIN (4 dígitos)
- [ ] PIN almacenado con hash seguro (password_hash)
- [ ] Rate limiting: máximo 5 intentos fallidos
- [ ] Bloqueo de 5 minutos después de 5 intentos
- [ ] Feedback de intentos restantes
- [ ] Regeneración de session_id al autenticar
- [ ] Botón de logout visible cuando está autenticado

#### Detalles Técnicos
- **Endpoints:** 
  - `POST /api.php?action=login`
  - `GET /api.php?action=logout`
- **Tabla DB:** `admins` (username, password_hash)
- **Sesión:** `$_SESSION['admin_auth']`
- **Seguridad:** 
  - Rate limiting en sesión
  - Password hashing con PASSWORD_DEFAULT
  - Session regeneration

#### Casos de Uso
1. Staff ingresa PIN correcto → Acceso concedido
2. Staff ingresa PIN incorrecto → Mensaje de error + intentos restantes
3. 5 intentos fallidos → Bloqueo temporal de 5 minutos
4. Staff hace logout → Sesión destruida

---

### **FEATURE 2.2: Reordenamiento de Cola (Drag & Drop)**
**Épica:** Panel de Administración Staff  
**Prioridad:** 🟠 ALTA  
**Esfuerzo Estimado:** 8 Story Points

#### Descripción
Interfaz de arrastrar y soltar que permite al staff reorganizar el orden de los participantes.

#### Criterios de Aceptación
- [ ] Solo visible en modo admin
- [ ] Handle de arrastre visible en cada ítem
- [ ] Animación suave durante el arrastre
- [ ] Actualización inmediata en base de datos
- [ ] Sincronización con todos los clientes conectados
- [ ] Rollback en caso de error

#### Detalles Técnicos
- **Librería:** SortableJS 1.15.0
- **Endpoint:** `POST /api.php?action=reorder_queue`
- **Payload:** `{ orderedIds: [id1, id2, id3...] }`
- **Actualización:** Transacción DB para actualizar `sort_order`

#### Flujo de Interacción
1. Staff arrastra ítem a nueva posición
2. SortableJS dispara evento `onEnd`
3. JavaScript extrae nuevo orden de IDs
4. Envía array de IDs al backend
5. Backend actualiza `sort_order` en transacción
6. Próximo polling refleja nuevo orden

---

### **FEATURE 2.3: Marcar Participante como Completado**
**Épica:** Panel de Administración Staff  
**Prioridad:** 🔴 CRÍTICA  
**Esfuerzo Estimado:** 3 Story Points

#### Descripción
Botón que permite al staff marcar un participante como "terminado" y removerlo de la cola activa.

#### Criterios de Aceptación
- [ ] Botón "LISTO" visible solo en modo admin
- [ ] Cambio de estado a `finished`
- [ ] Participante desaparece de la cola inmediatamente
- [ ] Confirmación visual con toast
- [ ] No elimina registro (mantiene histórico)

#### Detalles Técnicos
- **Endpoint:** `POST /api.php?action=remove_from_queue`
- **Operación:** `UPDATE songs SET status = 'finished' WHERE id = ?`
- **Soft Delete:** No elimina, solo cambia estado

---

### **FEATURE 2.4: Reiniciar Lista Completa**
**Épica:** Panel de Administración Staff  
**Prioridad:** 🟡 MEDIA  
**Esfuerzo Estimado:** 2 Story Points

#### Descripción
Función para limpiar toda la cola de participantes, útil al finalizar un evento o iniciar uno nuevo.

#### Criterios de Aceptación
- [ ] Botón "Reiniciar Todo" visible solo en modo admin
- [ ] Confirmación obligatoria antes de ejecutar
- [ ] Marca todos los registros activos como `deleted`
- [ ] Cola se vacía inmediatamente
- [ ] Feedback de confirmación

#### Detalles Técnicos
- **Endpoint:** `GET /api.php?action=clear_queue`
- **Operación:** `UPDATE songs SET status = 'deleted' WHERE status IN ('waiting', 'singing')`
- **Confirmación:** `confirm()` en JavaScript

---

### **FEATURE 3.1: Validación de Código de Acceso**
**Épica:** Sistema de Seguridad y Control de Acceso  
**Prioridad:** 🔴 CRÍTICA  
**Esfuerzo Estimado:** 5 Story Points

#### Descripción
Sistema que requiere un código diario para permitir registros, evitando participantes no autorizados.

#### Criterios de Aceptación
- [ ] Campo de código visible en formulario de registro
- [ ] Validación contra código almacenado en DB
- [ ] Código case-insensitive (convertido a mayúsculas)
- [ ] Código validado se guarda en sesión (8 horas)
- [ ] Después de validación, campo se oculta
- [ ] Mensaje de error claro si código es incorrecto

#### Detalles Técnicos
- **Tabla DB:** `settings` (key='night_code', value='CODIGO')
- **Sesión:** `$_SESSION['access_code_validated']`, `$_SESSION['access_code_time']`
- **Validez:** 8 horas (28800 segundos)
- **Normalización:** `strtoupper(trim($code))`

#### Flujo de Usuario
1. Usuario ingresa datos + código
2. Sistema valida código contra DB
3. Si correcto: guarda en sesión, permite registro
4. Si incorrecto: muestra error, no permite registro
5. Próximos registros en misma sesión no requieren código

---

### **FEATURE 3.2: Gestión de Código Diario**
**Épica:** Configuración y Personalización  
**Prioridad:** 🟠 ALTA  
**Esfuerzo Estimado:** 3 Story Points

#### Descripción
Interfaz para que el staff cambie el código de acceso diario desde el panel admin.

#### Criterios de Aceptación
- [ ] Campo de texto para nuevo código
- [ ] Botón "CAMBIAR" para confirmar
- [ ] Código se actualiza en DB inmediatamente
- [ ] Visualización del código actual
- [ ] Solo accesible en modo admin
- [ ] Validación: código no puede estar vacío

#### Detalles Técnicos
- **Endpoint:** `POST /api.php?action=update_night_code`
- **Operación:** `REPLACE INTO settings (key, value) VALUES ('night_code', ?)`
- **UI:** Input + botón en sección "Configuración de Hoy"

---

### **FEATURE 4.1: Polling de Cola**
**Épica:** Experiencia en Tiempo Real  
**Prioridad:** 🔴 CRÍTICA  
**Esfuerzo Estimado:** 3 Story Points

#### Descripción
Sistema de actualización automática que consulta el servidor cada 5 segundos para mantener la cola sincronizada.

#### Criterios de Aceptación
- [ ] Consulta cada 5 segundos
- [ ] Actualiza cola sin parpadeo visual
- [ ] Maneja errores de red gracefully
- [ ] No interrumpe interacciones del usuario
- [ ] Sincroniza estado admin/no-admin

#### Detalles Técnicos
- **Intervalo:** `setInterval(fetchQueue, 5000)`
- **Endpoint:** `GET /api.php?action=get_queue`
- **Respuesta:** `{ queue: [], is_admin: bool, registration_enabled: bool, ... }`

---

### **FEATURE 4.2: Polling de Reacciones**
**Épica:** Experiencia en Tiempo Real  
**Prioridad:** 🟡 MEDIA  
**Esfuerzo Estimado:** 3 Story Points

#### Descripción
Consulta frecuente al servidor para obtener nuevas reacciones y mostrarlas en tiempo real.

#### Criterios de Aceptación
- [ ] Consulta cada 2 segundos
- [ ] Solo recupera reacciones nuevas (desde último ID)
- [ ] Filtra reacciones antiguas (>30 segundos)
- [ ] Animación fluida de partículas

#### Detalles Técnicos
- **Intervalo:** `setInterval(fetchReactions, 2000)`
- **Endpoint:** `GET /api.php?action=get_reactions&since={lastId}`
- **Optimización:** Query con `WHERE id > ? AND created_at >= NOW() - INTERVAL 30 SECOND`

---

### **FEATURE 5.1: Toggle de Registro**
**Épica:** Configuración y Personalización  
**Prioridad:** 🟠 ALTA  
**Esfuerzo Estimado:** 3 Story Points

#### Descripción
Interruptor que permite al staff habilitar o deshabilitar el registro de nuevos participantes.

#### Criterios de Aceptación
- [ ] Toggle switch en panel admin
- [ ] Cambio inmediato en DB
- [ ] Formulario se oculta cuando está deshabilitado
- [ ] Mensaje informativo cuando registro está cerrado
- [ ] Estado sincronizado entre todos los clientes

#### Detalles Técnicos
- **Endpoint:** `POST /api.php?action=toggle_registration`
- **Tabla DB:** `settings` (key='registration_enabled', value='0'|'1')
- **UI:** Toggle switch con estados visual claro

#### Casos de Uso
- **Habilitado:** Formulario visible, registros permitidos
- **Deshabilitado:** Formulario oculto, mensaje "El registro está cerrado"

---

### **FEATURE 5.2: Cambio de PIN de Staff**
**Épica:** Configuración y Personalización  
**Prioridad:** 🟡 MEDIA  
**Esfuerzo Estimado:** 3 Story Points

#### Descripción
Funcionalidad que permite al staff cambiar su PIN de acceso por seguridad.

#### Criterios de Aceptación
- [ ] Campo de entrada para nuevo PIN (4 dígitos)
- [ ] Validación: exactamente 4 dígitos numéricos
- [ ] Confirmación antes de cambiar
- [ ] Nuevo PIN hasheado antes de guardar
- [ ] Feedback de éxito/error
- [ ] Campo se limpia después de cambio exitoso

#### Detalles Técnicos
- **Endpoint:** `POST /api.php?action=update_staff_pin`
- **Validación:** `strlen($pin) === 4 && is_numeric($pin)`
- **Hash:** `password_hash($pin, PASSWORD_DEFAULT)`
- **Operación:** `UPDATE admins SET password_hash = ? WHERE username = 'staff'`

---

## 📊 Backlog Priorizado

### Sprint 0: Fundamentos (Completado ✅)
- ✅ Configuración de base de datos
- ✅ Estructura de archivos
- ✅ API REST básica
- ✅ Sistema de sesiones

### Sprint 1: MVP Core (Completado ✅)
**Objetivo:** Sistema funcional básico de registro y visualización

| Feature | Prioridad | Story Points | Estado |
|---------|-----------|--------------|--------|
| 1.1 Registro de Participantes | 🔴 CRÍTICA | 8 | ✅ Completado |
| 1.2 Visualización de Cola | 🔴 CRÍTICA | 5 | ✅ Completado |
| 3.1 Validación de Código | 🔴 CRÍTICA | 5 | ✅ Completado |
| 2.1 Autenticación Staff | 🔴 CRÍTICA | 8 | ✅ Completado |

**Velocity:** 26 Story Points

### Sprint 2: Administración (Completado ✅)
**Objetivo:** Herramientas de gestión para staff

| Feature | Prioridad | Story Points | Estado |
|---------|-----------|--------------|--------|
| 2.2 Reordenamiento de Cola | 🟠 ALTA | 8 | ✅ Completado |
| 2.3 Marcar Completado | 🔴 CRÍTICA | 3 | ✅ Completado |
| 3.2 Gestión de Código Diario | 🟠 ALTA | 3 | ✅ Completado |
| 5.1 Toggle de Registro | 🟠 ALTA | 3 | ✅ Completado |

**Velocity:** 17 Story Points

### Sprint 3: Tiempo Real y Engagement (Completado ✅)
**Objetivo:** Mejorar experiencia en tiempo real

| Feature | Prioridad | Story Points | Estado |
|---------|-----------|--------------|--------|
| 4.1 Polling de Cola | 🔴 CRÍTICA | 3 | ✅ Completado |
| 4.2 Polling de Reacciones | 🟡 MEDIA | 3 | ✅ Completado |
| 1.3 Sistema de Reacciones | 🟡 MEDIA | 5 | ✅ Completado |
| 2.4 Reiniciar Lista | 🟡 MEDIA | 2 | ✅ Completado |
| 5.2 Cambio de PIN | 🟡 MEDIA | 3 | ✅ Completado |

**Velocity:** 16 Story Points

---

## 🎯 Próximos Pasos Sugeridos (Backlog Futuro)

### Épica 6: Analíticas y Reportes 📈
**Prioridad:** 🟢 BAJA  
**Valor de Negocio:** Insights para mejorar eventos futuros

#### Features Propuestas:
- **6.1 Dashboard de Métricas**
  - Participantes por evento
  - Canciones más populares
  - Tiempo promedio de espera
  - Horarios pico de registro
  
- **6.2 Exportación de Datos**
  - Exportar lista de participantes a CSV/Excel
  - Histórico de eventos
  - Reportes personalizados

- **6.3 Estadísticas en Tiempo Real**
  - Contador de participantes totales del día
  - Tiempo promedio de canción
  - Gráficos de participación

**Esfuerzo Estimado:** 21 Story Points

---

### Épica 7: Mejoras de UX/UI 🎨
**Prioridad:** 🟡 MEDIA  
**Valor de Negocio:** Aumentar satisfacción y engagement

#### Features Propuestas:
- **7.1 Modo Presentación**
  - Vista full-screen para proyector
  - Diseño optimizado para distancia
  - Navegación entre rondas
  - (Referencia: conversación 0072153e)

- **7.2 Notificaciones Push**
  - Alertar a usuario cuando está próximo su turno
  - Notificaciones web (Web Push API)
  - Configuración de preferencias

- **7.3 Temas Personalizables**
  - Modo oscuro/claro
  - Colores personalizados por evento
  - Logos y branding customizable

- **7.4 Búsqueda de Canciones**
  - Integración con API de música (Spotify, YouTube)
  - Autocompletado de títulos y artistas
  - Sugerencias populares

**Esfuerzo Estimado:** 34 Story Points

---

### Épica 8: Gamificación y Social 🎮
**Prioridad:** 🟢 BAJA  
**Valor de Negocio:** Aumentar engagement y viralidad

#### Features Propuestas:
- **8.1 Sistema de Votación**
  - Audiencia vota presentaciones
  - Ranking de mejores cantantes
  - Premios virtuales

- **8.2 Perfiles de Usuario**
  - Registro de usuarios recurrentes
  - Historial de canciones cantadas
  - Badges y logros

- **8.3 Compartir en Redes Sociales**
  - "Estoy en la lista de karaoke en La Trilla"
  - Compartir posición en cola
  - Invitar amigos

- **8.4 Duetos y Grupos**
  - Permitir registro de múltiples personas
  - Etiquetas de colaboración
  - Gestión de grupos

**Esfuerzo Estimado:** 34 Story Points

---

### Épica 9: Feedback en Tiempo Real 🎵
**Prioridad:** 🟡 MEDIA  
**Valor de Negocio:** Innovación diferenciadora

#### Features Propuestas:
- **9.1 Análisis de Pitch**
  - Captura de audio en tiempo real
  - Comparación con canción original
  - Feedback visual de afinación
  - (Referencia: conversación a6452fe6)

- **9.2 Visualización de Audio**
  - Espectrograma en vivo
  - Indicador de volumen
  - Efectos visuales sincronizados

- **9.3 Grabación de Presentaciones**
  - Grabar audio de presentación
  - Descarga de grabación
  - Compartir en redes

**Esfuerzo Estimado:** 21 Story Points  
**Nota:** Requiere investigación técnica de Web Audio API

---

### Épica 10: Aplicación Móvil Nativa 📱
**Prioridad:** 🟢 BAJA  
**Valor de Negocio:** Alcance ampliado, mejor UX móvil

#### Features Propuestas:
- **10.1 PWA (Progressive Web App)**
  - Service Workers para offline
  - Instalable en home screen
  - Push notifications nativas

- **10.2 App Nativa (React Native / Flutter)**
  - iOS y Android
  - Integración con backend existente
  - Performance optimizada
  - (Referencia: conversación 8daedaa0)

- **10.3 Funcionalidades Móvil-Específicas**
  - Escaneo QR para código de acceso
  - Cámara para compartir momentos
  - Geolocalización para eventos cercanos

**Esfuerzo Estimado:** 55 Story Points  
**Nota:** Requiere decisión estratégica sobre tecnología

---

### Épica 11: SaaS Multi-Tenant 🏢
**Prioridad:** 🟢 BAJA  
**Valor de Negocio:** Escalabilidad comercial, nuevos ingresos

#### Features Propuestas:
- **11.1 Sistema de Tenants**
  - Registro de locales/organizadores
  - Aislamiento de datos por tenant
  - Subdominios personalizados

- **11.2 Planes y Suscripciones**
  - Freemium / Premium / Enterprise
  - Integración con Stripe/PayPal
  - Límites por plan (participantes, eventos)

- **11.3 Panel de Administración Multi-Tenant**
  - Dashboard de super-admin
  - Gestión de clientes
  - Métricas agregadas

- **11.4 Personalización por Tenant**
  - Branding personalizado
  - Dominios custom
  - Configuraciones específicas
  - (Referencia: conversación 91188cfe)

**Esfuerzo Estimado:** 89 Story Points  
**Nota:** Cambio arquitectónico significativo

---

## 📈 Métricas y KPIs

### Métricas de Producto (Actuales)

#### Funcionalidad
- ✅ **Uptime:** 99.9% (objetivo)
- ✅ **Tiempo de Respuesta API:** < 200ms promedio
- ✅ **Tasa de Éxito de Registro:** ~98%
- ✅ **Latencia de Actualización:** 5 segundos (polling)

#### Seguridad
- ✅ **Autenticación:** PIN hasheado con bcrypt
- ✅ **Rate Limiting:** 5 intentos / 5 minutos
- ✅ **Validación de Código:** 100% de registros validados
- ✅ **Sesiones:** Regeneración de ID en login

#### Experiencia de Usuario
- 📊 **Tiempo Promedio de Registro:** ~25 segundos
- 📊 **Participantes por Evento:** Variable (5-50)
- 📊 **Tasa de Retorno:** No medido aún
- 📊 **Satisfacción:** No medido aún

### KPIs Sugeridos para Implementar

#### Negocio
- **Eventos por Semana:** Cuántos eventos se realizan
- **Participantes Únicos:** Usuarios diferentes que se registran
- **Tasa de Conversión:** Visitantes → Registros
- **Engagement:** Reacciones enviadas / Participantes

#### Técnicos
- **Error Rate:** % de requests fallidos
- **Database Query Time:** Tiempo promedio de queries
- **Concurrent Users:** Usuarios simultáneos pico
- **Bandwidth Usage:** Consumo de datos

#### Operacionales
- **Tiempo de Setup por Evento:** Cuánto tarda staff en configurar
- **Intervenciones de Staff:** Cuántas veces staff debe intervenir manualmente
- **Incidentes de Seguridad:** Intentos de acceso no autorizado

---

## 🔄 Proceso Ágil Recomendado

### Ceremonias Scrum

#### Sprint Planning (Inicio de Sprint)
- **Duración:** 2 horas
- **Participantes:** Product Owner, Scrum Master, Dev Team
- **Objetivo:** Seleccionar features del backlog para el sprint
- **Entregable:** Sprint Backlog con tareas definidas

#### Daily Standup (Diario)
- **Duración:** 15 minutos
- **Formato:** ¿Qué hice ayer? ¿Qué haré hoy? ¿Impedimentos?
- **Objetivo:** Sincronización del equipo

#### Sprint Review (Fin de Sprint)
- **Duración:** 1 hora
- **Participantes:** Todo el equipo + Stakeholders
- **Objetivo:** Demo de features completadas
- **Entregable:** Feedback de stakeholders

#### Sprint Retrospective (Fin de Sprint)
- **Duración:** 1 hora
- **Participantes:** Scrum Team
- **Objetivo:** Mejorar proceso
- **Entregable:** Action items para próximo sprint

### Definición de "Done"

Una feature se considera completada cuando:
- ✅ Código implementado y funcional
- ✅ Tests unitarios escritos y pasando
- ✅ Code review aprobado
- ✅ Documentación actualizada
- ✅ Desplegado en ambiente de staging
- ✅ QA/Testing completado
- ✅ Aprobación de Product Owner
- ✅ Sin bugs críticos pendientes

### Definición de "Ready"

Una historia está lista para desarrollo cuando:
- ✅ Criterios de aceptación claros
- ✅ Diseños/mockups disponibles (si aplica)
- ✅ Dependencias identificadas
- ✅ Estimación de esfuerzo realizada
- ✅ Prioridad asignada
- ✅ Equipo entiende la historia

---

## 🎯 Roadmap Visual

```
Q1 2026                Q2 2026                Q3 2026                Q4 2026
├─────────────────────┼─────────────────────┼─────────────────────┼──────────────────────┤
│ ✅ MVP Core         │ 🎨 UX Enhancements  │ 📊 Analytics        │ 🏢 SaaS Platform    │
│ ✅ Admin Panel      │ 📱 PWA              │ 🎮 Gamification     │ 🌍 Multi-language   │
│ ✅ Real-time        │ 🎵 Audio Feedback   │ 🔗 Integrations     │ 🚀 Scale & Optimize │
└─────────────────────┴─────────────────────┴─────────────────────┴──────────────────────┘
     FOUNDATION            GROWTH               EXPANSION              SCALE
```

---

## 📝 Notas Finales

### Fortalezas del Producto Actual
- ✅ Arquitectura simple y mantenible
- ✅ UX intuitiva y moderna
- ✅ Funcionalidades core sólidas
- ✅ Seguridad implementada correctamente
- ✅ Experiencia en tiempo real fluida

### Áreas de Mejora Identificadas
- 🔄 Migrar de polling a WebSockets para verdadero real-time
- 🔄 Implementar tests automatizados (unit, integration, e2e)
- 🔄 Añadir logging y monitoring (errores, performance)
- 🔄 Optimizar queries de DB con índices
- 🔄 Implementar caché (Redis) para reducir carga de DB
- 🔄 Añadir validación de inputs en frontend (además de backend)

### Riesgos Técnicos
- ⚠️ **Escalabilidad:** Polling puede ser costoso con muchos usuarios
- ⚠️ **Single Point of Failure:** DB sin replicación
- ⚠️ **Seguridad:** PIN de 4 dígitos puede ser vulnerable a brute force (mitigado con rate limiting)
- ⚠️ **Browser Compatibility:** Dependencia de JavaScript moderno

### Dependencias Externas
- TailwindCSS CDN (considerar bundle local para producción)
- SortableJS CDN (considerar bundle local)
- Font Awesome CDN (considerar bundle local)
- Logo externo (latrillacultural.com)

---

## 📚 Referencias

### Documentación Relacionada
- [Conversación: Refining Presentation Mode](conversation://0072153e-febe-4974-9f37-782b69867a83)
- [Conversación: Real-time Singing Feedback](conversation://a6452fe6-86a3-4657-a5e8-6a23bb059ab4)
- [Conversación: Convert Web App to Mobile](conversation://8daedaa0-eb8e-4ab8-8058-a7253c66a85a)
- [Conversación: Transforming App into SaaS](conversation://91188cfe-cda7-4af4-81dc-f814ddae17c5)

### Recursos Ágiles
- [Scrum Guide](https://scrumguides.org/)
- [User Story Mapping](https://www.jpattonassociates.com/user-story-mapping/)
- [Story Points Estimation](https://www.mountaingoatsoftware.com/blog/what-are-story-points)

---

**Documento generado el:** 2026-01-20  
**Versión:** 1.0  
**Mantenido por:** Equipo de Desarrollo La Trilla Cultural  
**Próxima Revisión:** 2026-02-20
