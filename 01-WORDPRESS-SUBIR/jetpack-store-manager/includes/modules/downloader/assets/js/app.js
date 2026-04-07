document.addEventListener('DOMContentLoaded', function () {
    console.log('!!! JDD UI UPDATE v2.5.0 LOADED !!!');
    const container = document.getElementById('jdd-app-container');
    if (!container) return;

    // Check if settings are available
    if (typeof jddSettings === 'undefined') {
        container.innerHTML = '<div class="jdd-error">Error: Plugin settings not loaded.</div>';
        return;
    }

    const { apiKey, rootFolderId } = jddSettings;

    if (!apiKey || !rootFolderId) {
        container.innerHTML = '<div class="jdd-error">Please configure the API Key and Root Folder ID in the plugin settings.</div>';
        return;
    }

    // Initialize App
    initApp(container, apiKey, rootFolderId);
});

// State
let currentFolderId = null;
let folderHistory = [];
let accessToken = null; // OAuth Token
let tokenClient = null; // GIS Client

// Selected items state
let selectedItems = [];

// Catalog State
let catalogMetadata = {}; // folderId -> { images: [], tags: [] }
let validCatalogFolderIds = []; // IDs that have metadata
let availableTags = {}; // slug -> name
let activeTags = []; // currently filtered tags
let viewMode = 'grid'; // 'list' or 'grid'

// Placeholder for Client ID
let OAUTH_CLIENT_ID = '';
const DISCOVERY_DOC = 'https://www.googleapis.com/discovery/v1/apis/drive/v3/rest';
const SCOPES = 'https://www.googleapis.com/auth/drive.readonly';

// Token persistence keys
const TOKEN_STORAGE_KEY = 'jdd_access_token';
const TOKEN_EXPIRY_KEY = 'jdd_token_expiry';


function initApp(container, apiKey, rootFolderId) {
    currentFolderId = rootFolderId;
    OAUTH_CLIENT_ID = apiKey;

    // Verify Client ID is loaded
    if (OAUTH_CLIENT_ID) {
        const maskedId = OAUTH_CLIENT_ID.substring(0, 8) + '...' + OAUTH_CLIENT_ID.substring(OAUTH_CLIENT_ID.length - 8);
        console.log('[JDD] Google OAuth ID:', maskedId);
    }

    // Inject Google Identity Services Script
    const script = document.createElement('script');
    script.src = 'https://accounts.google.com/gsi/client';
    script.async = true;
    script.defer = true;
    script.onload = () => initGoogleAuth(container, rootFolderId);
    document.body.appendChild(script);

    // Initial Loading State
    container.innerHTML = `
        <div class="jdd-app">
	            <div class="jdd-header">
	                <div class="jdd-logo" aria-hidden="true">⬇️</div>
	                <div class="jdd-header-content">
	                    <h2>Gestor de Descargas</h2>
	                    <p class="jdd-subtitle">Descarga carpetas completas directamente a tu PC</p>
	                </div>
	            </div>
            <div id="jdd-auth-container" class="jdd-auth-container" style="text-align:center; padding: 50px 40px;">
                <p style="font-size: 15px; color: var(--jdd-text-muted); margin-bottom: 20px;">Para descargar sin límites, inicia sesión con tu cuenta de Google.</p>
                <button id="jdd-auth-btn" class="jdd-btn jdd-btn-primary" style="font-size: 1em; padding: 14px 28px; border-radius: 30px;">🔑 Conectar con Google Drive</button>
            </div>
            <div id="jdd-main-interface" style="display:none;">
                <!-- Mobile Warning -->
                <div id="jdd-mobile-warning" class="jdd-mobile-warning" style="display:none;">
                    📱 <strong>Modo Móvil:</strong> La descarga de carpetas solo funciona en PC (Chrome/Edge). Puedes navegar, previsualizar y descargar archivos individuales.
                </div>
                <!-- Main Interface (Hidden until login) -->
                <!-- Instructions -->
                <!-- Instructions (Collapsible) -->
                <div class="jdd-instructions-wrapper">
                     <button class="jdd-instructions-toggle" onclick="toggleInstructions()">
                        <span>🚀 Guía Rápida de Uso</span>
                        <span id="jdd-instr-icon">▼</span>
                     </button>
                    <div id="jdd-instructions-content" class="jdd-instructions-v2" style="display:none;">
                        <h3 class="jdd-section-title" style="display:none">🚀 Guía Rápida de Uso</h3> <!-- Hidden title inside -->
                        <div class="jdd-steps-grid">
                            <div class="jdd-step-card">
                                <div class="jdd-step-number">PASO 1</div>
                                <div class="jdd-step-icon">📂</div>
                                <div class="jdd-step-text">
                                    <strong>Navega</strong>
                                    <span>Busca y entra en las carpetas</span>
                                </div>
                            </div>
                            <div class="jdd-step-card">
                                <div class="jdd-step-number">PASO 2</div>
                                <div class="jdd-step-icon">✅</div>
                                <div class="jdd-step-text">
                                    <strong>Selecciona</strong>
                                    <span>Marca lo que quieras bajar</span>
                                </div>
                            </div>
                            <div class="jdd-step-card">
                                <div class="jdd-step-number">PASO 3</div>
                                <div class="jdd-step-icon">📁</div>
                                <div class="jdd-step-text">
                                    <strong>Elige Destino</strong>
                                    <span>Selecciona carpeta en tu PC</span>
                                </div>
                            </div>
                            <div class="jdd-step-card">
                                <div class="jdd-step-number">PASO 4</div>
                                <div class="jdd-step-icon">⚡</div>
                                <div class="jdd-step-text">
                                    <strong>Descarga</strong>
                                    <span>¡El proceso iniciará solo!</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Navigation Header -->
                <div class="jdd-nav-bar">
                    <div id="jdd-breadcrumbs" class="jdd-breadcrumbs"></div>
                    
                    <div style="display:flex; gap:12px; align-items:center;">
                        <!-- View Toggle -->
                        <div class="jdd-view-toggle">
                            <div class="jdd-toggle-option active" id="jdd-view-grid" onclick="switchView('grid')">📅 Catálogo</div>
                            <div class="jdd-toggle-option" id="jdd-view-list" onclick="switchView('list')">📄 Lista</div>
                        </div>

                        <div id="jdd-batch-actions" class="jdd-batch-actions" style="display:none;">
                            <span id="jdd-selected-count">0 seleccionados</span>
                            <button id="jdd-batch-add-btn" class="jdd-btn jdd-btn-primary">📥 Descargar Selección</button>
                            <button id="jdd-clear-selection-btn" class="jdd-btn jdd-btn-secondary">Limpiar</button>
                        </div>

                         <!-- Permanent Downloads Button -->
                        <button id="jdd-show-downloads-btn" class="jdd-btn jdd-btn-secondary" onclick="toggleQueuePanel()">
                            📦 Abrir descargas en curso
                        </button>
                    </div>
                </div>

                <!-- Main Content Area with Sidebar -->
                <div class="jdd-catalog-container">
                    <!-- Sidebar -->
                    <div id="jdd-sidebar" class="jdd-sidebar" style="display:none;">
                        <div class="jdd-sidebar-title">🔍 Filtros</div>
                        <div id="jdd-filter-list"></div>
                    </div>

                    <!-- File List / Grid -->
                    <div id="jdd-content" class="jdd-explorer-list-container" style="flex:1;">
                        <div class="jdd-loading">Cargando archivos...</div>
                    </div>
                </div>
                
                <!-- Bottom Panel (Resizable) -->
                <div id="jdd-bottom-panel" class="jdd-bottom-panel">
                    <div id="jdd-resize-handle" class="jdd-resize-handle"></div>
                    <div class="jdd-panel-header">
                        <div class="jdd-panel-title">
                            <span>📦 Mis Descargas</span>
                            <span id="jdd-queue-count" class="jdd-badge">0</span>
                        </div>
                        <button id="jdd-toggle-queue" class="jdd-toggle-btn">▼</button>
                    </div>
                    <div id="jdd-panel-body" class="jdd-panel-body">
                        <div class="jdd-download-stats">
                            <div class="jdd-stats-row">
                                <span id="jdd-speed-text">⚡ Velocidad: -- KB/s</span>
                                <span id="jdd-eta">⏱ Tiempo restante: --:--</span>
                                <span id="jdd-status-text">📋 Esperando...</span>
                            </div>
                            <div class="jdd-controls-row">
                                <button id="jdd-pause-btn" class="jdd-btn" disabled>⏸ Pausar</button>
                                <button id="jdd-cancel-btn" class="jdd-btn jdd-btn-danger" disabled>✖ Cancelar</button>
                            </div>
                        </div>
                        <div id="jdd-queue-list" class="jdd-queue-list">
                            <table class="jdd-queue-table">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Carpeta</th>
                                        <th>Estado</th>
                                        <th>Acciones</th>
                                    </tr>
                                </thead>
                                <tbody id="jdd-queue-body"></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    `;

    // Event Listeners for App
    document.getElementById('jdd-pause-btn').addEventListener('click', togglePause);
    document.getElementById('jdd-cancel-btn').addEventListener('click', cancelDownload);
    document.getElementById('jdd-toggle-queue').addEventListener('click', toggleQueuePanel);
    document.getElementById('jdd-batch-add-btn').addEventListener('click', () => batchAddToQueue());
    document.getElementById('jdd-clear-selection-btn').addEventListener('click', clearSelection);

    // Resizable panel
    initResizablePanel();
}

function initGoogleAuth(container, rootFolderId) {
    if (!google) return;

    // Check for existing valid token in localStorage
    const savedToken = localStorage.getItem(TOKEN_STORAGE_KEY);
    const savedExpiry = localStorage.getItem(TOKEN_EXPIRY_KEY);

    if (savedToken && savedExpiry) {
        const expiryTime = parseInt(savedExpiry);
        const now = Date.now();

        // Token is valid if it has more than 5 minutes remaining
        if (expiryTime > now + (5 * 60 * 1000)) {
            accessToken = savedToken;
            console.log('[JDD] Restored session from localStorage');

            // Show main interface directly
            document.getElementById('jdd-auth-container').style.display = 'none';
            document.getElementById('jdd-main-interface').style.display = 'block';

            // Show mobile warning if on mobile device
            if (isMobileDevice()) {
                const mobileWarning = document.getElementById('jdd-mobile-warning');
                if (mobileWarning) mobileWarning.style.display = 'block';
            }

            loadFolder(currentFolderId, 'Inicio');

            // Still initialize token client for future refresh
            initTokenClient();
            return;
        } else {
            // Token expired, clear it
            localStorage.removeItem(TOKEN_STORAGE_KEY);
            localStorage.removeItem(TOKEN_EXPIRY_KEY);
            console.log('[JDD] Saved token expired, cleared');
        }
    }

    initTokenClient();

    document.getElementById('jdd-auth-btn').onclick = () => {
        tokenClient.requestAccessToken();
    };
}

// Fetch Catalog Metadata early
async function fetchCatalogMetadata() {
    try {
        const response = await fetch('/wp-json/jdd/v1/catalog');
        if (response.ok) {
            const data = await response.json();
            catalogMetadata = data.items || {};
            availableTags = data.available_tags || {};
            validCatalogFolderIds = Object.keys(catalogMetadata);
            console.log('[JDD] Catalog Metadata Loaded:', Object.keys(catalogMetadata).length, 'items');
        }
    } catch (err) {
        console.warn('[JDD] Failed to load catalog metadata', err);
    }
}

function initTokenClient() {
    // Fetch catalog metadata in parallel with auth init
    fetchCatalogMetadata();

    tokenClient = google.accounts.oauth2.initTokenClient({
        client_id: OAUTH_CLIENT_ID,
        scope: SCOPES,
        callback: (tokenResponse) => {
            if (tokenResponse.access_token) {
                accessToken = tokenResponse.access_token;
                console.log('[JDD] OAuth Token received');

                // Save token and expiry to localStorage (default 1 hour = 3600 seconds)
                const expiresIn = tokenResponse.expires_in || 3600;
                const expiryTime = Date.now() + (expiresIn * 1000);
                localStorage.setItem(TOKEN_STORAGE_KEY, accessToken);
                localStorage.setItem(TOKEN_EXPIRY_KEY, expiryTime.toString());
                console.log('[JDD] Token saved to localStorage, expires in', expiresIn, 'seconds');

                // Show main interface
                document.getElementById('jdd-auth-container').style.display = 'none';
                document.getElementById('jdd-main-interface').style.display = 'block';

                // Show mobile warning if on mobile device
                if (isMobileDevice()) {
                    const mobileWarning = document.getElementById('jdd-mobile-warning');
                    if (mobileWarning) mobileWarning.style.display = 'block';
                }

                // Load Folder
                loadFolder(currentFolderId, 'Inicio');
            }
        },
    });
}

async function loadFolder(folderId, folderName) {
    const contentDiv = document.getElementById('jdd-content');
    contentDiv.innerHTML = '<div class="jdd-loading">Cargando archivos...</div>';

    // Update Breadcrumbs
    updateBreadcrumbs(folderId, folderName);

    // Conditional View Logic
    const isRoot = (folderId === jddSettings.rootFolderId);
    const viewToggle = document.querySelector('.jdd-view-toggle');
    const sidebar = document.getElementById('jdd-sidebar');

    if (isRoot) {
        if (viewToggle) viewToggle.style.display = 'flex';
        // Restore user preference for view mode? For now, we respect the global variable 'viewMode' 
        // effectively persisting it per session.
    } else {
        if (viewToggle) viewToggle.style.display = 'none';
        if (sidebar) sidebar.style.display = 'none';
        // Force list view for subfolders internally, but don't change global viewMode?
        // Actually, let's just force renderList inside renderFiles for non-root items.
        // Or better: temporary switch local view state?
        // Let's stick to the plan: Hide toggle, force List view.

        // We can just set a flag for renderFiles or pass it.
    }

    try {
        const files = await listGoogleDriveFiles(folderId);

        // Sync Trigger (Only on Root)
        if (isRoot) {
            syncCatalogItems(files);
        }

        renderFiles(files); // renderFiles handles sidebar visibility on its own usually? 
        // We need to make sure renderFiles respects the "Hidden Sidebar" rule for subfolders.

    } catch (error) {
        console.error('Error loading folder:', error);
        contentDiv.innerHTML = `<div class="jdd-error">
            <p><strong>Error al cargar la carpeta:</strong> ${error.message}</p>
            <p><small>Intentando acceder al ID: ${folderId}</small></p>
            <p><small>Asegúrate de haber iniciado sesión correctamente.</small></p>
        </div>`;
    }
}

async function listGoogleDriveFiles(folderId) {
    if (!accessToken) throw new Error('No Access Token');

    const query = `'${folderId}' in parents and trashed = false`;
    const fields = 'nextPageToken, files(id, name, mimeType, size, webContentLink)';
    let allFiles = [];
    let pageToken = null;

    do {
        let url = `https://www.googleapis.com/drive/v3/files?q=${encodeURIComponent(query)}&fields=${encodeURIComponent(fields)}&pageSize=1000&orderBy=folder,name`;
        if (pageToken) {
            url += `&pageToken=${pageToken}`;
        }

        const response = await fetch(url, {
            headers: {
                'Authorization': `Bearer ${accessToken}`
            }
        });

        if (!response.ok) {
            const errorData = await response.json();
            throw new Error(errorData.error?.message || 'Error desconocido de API');
        }

        const data = await response.json();
        if (data.files) {
            allFiles = allFiles.concat(data.files);
        }
        pageToken = data.nextPageToken;

    } while (pageToken);



    window.lastFiles = allFiles; // Store for filtering
    return allFiles;
}

// Helper to switch view
window.switchView = function (mode) {
    viewMode = mode;
    document.getElementById('jdd-view-grid').classList.toggle('active', mode === 'grid');
    document.getElementById('jdd-view-list').classList.toggle('active', mode === 'list');

    // Refresh current view if data exists
    const contentDiv = document.getElementById('jdd-content');
    if (contentDiv.querySelector('table') || contentDiv.querySelector('.jdd-catalog-grid')) {
        // We need to re-render with the LAST loaded files. 
        // We can access them if we stored them, or just let the user navigate. 
        // For now, let's reload the current folder.
        loadFolder(currentFolderId, document.title || 'Actualizar');
    }
}

function renderFiles(files) {
    const contentDiv = document.getElementById('jdd-content');
    const sidebar = document.getElementById('jdd-sidebar');
    const filterList = document.getElementById('jdd-filter-list');

    // Clear selection when navigating
    selectedItems = [];
    updateBatchActionsUI();

    contentDiv.innerHTML = '';

    // Feature: Show featured image for current folder
    const currentMeta = (typeof catalogMetadata !== 'undefined' && catalogMetadata[currentFolderId]) ? catalogMetadata[currentFolderId] : null;
    if (currentMeta && (currentMeta.image1 || currentMeta.image2)) {
        const headerDiv = document.createElement('div');
        headerDiv.className = 'jdd-folder-featured-header';

        let imagesHtml = '';
        if (currentMeta.image1) {
            imagesHtml += `<img src="${currentMeta.image1}" class="jdd-folder-featured-img" alt="Portada 1">`;
        }
        if (currentMeta.image2) {
            imagesHtml += `<img src="${currentMeta.image2}" class="jdd-folder-featured-img" alt="Portada 2">`;
        }

        headerDiv.innerHTML = imagesHtml;
        contentDiv.appendChild(headerDiv);

        // Add Scroll Hint
        const scrollHint = document.createElement('div');
        scrollHint.className = 'jdd-scroll-hint';
        scrollHint.innerHTML = `<span>Desliza hacia abajo para ver archivos</span> <span>▼</span>`;
        contentDiv.appendChild(scrollHint);
    }

    // Add Section Header for the list
    if (files.length > 0) {
        const sectionHeader = document.createElement('div');
        sectionHeader.className = 'jdd-content-header';
        sectionHeader.innerHTML = `📋 PARA SELECCIONAR TUS CARPETAS DESLIZA HACIA ABAJO`;
        contentDiv.appendChild(sectionHeader);
    }

    if (files.length === 0) {
        const emptyMsg = document.createElement('div');
        emptyMsg.className = 'jdd-empty';
        emptyMsg.textContent = 'Esta carpeta está vacía.';
        contentDiv.appendChild(emptyMsg);
        return;
    }

    const isRoot = (currentFolderId === jddSettings.rootFolderId);

    // Render Sidebar Filters - Only if Root
    if (isRoot && Object.keys(availableTags).length > 0) {
        sidebar.style.display = 'block';

        // Use lastFiles to maintain filter state if needed, or just clear if new render
        if (filterList.innerHTML === '') {
            Object.keys(availableTags).forEach(slug => {
                const div = document.createElement('div');
                div.className = 'jdd-filter-label';
                div.innerHTML = `
                    <input type="checkbox" value="${slug}" onchange="updateCatalogFilter()">
                    <span>${availableTags[slug]}</span>
                `;
                filterList.appendChild(div);
            });
        }
    } else {
        sidebar.style.display = 'none';

    }

    if (isRoot && viewMode === 'grid') {
        renderGrid(files, contentDiv);
    } else {
        renderList(files, contentDiv);
    }
}

function renderGrid(files, container) {
    const grid = document.createElement('div');
    grid.className = 'jdd-catalog-grid';

    files.forEach(file => {
        const isFolder = file.mimeType === 'application/vnd.google-apps.folder';
        // Logic: if it's a folder, check catalog data.

        const meta = (isFolder && catalogMetadata[file.id]) ? catalogMetadata[file.id] : null;

        // Filter logic: If activeTags has items, checks if this item has any of them
        if (activeTags.length > 0) {
            if (!meta || !meta.tags || !meta.tags.some(t => activeTags.includes(t))) {
                return; // Skip this item
            }
        }

        const card = document.createElement('div');
        card.className = 'jdd-catalog-item';
        card.dataset.id = file.id;

        // Image Section
        let imageHtml = '';
        if (meta && (meta.image1 || meta.image2)) {
            // Pick the best image for zoom (image 1 preference)
            const zoomImg = meta.image1 || meta.image2;

            imageHtml = `<div class="jdd-catalog-images">`;

            // Zoom Overlay Button
            imageHtml += `
                <div class="jdd-zoom-overlay">
                    <button class="jdd-zoom-btn" onclick="event.stopPropagation(); openLightbox('${zoomImg}')" title="Zoom">🔍</button>
                </div>
            `;

            if (meta.image1) imageHtml += `<img src="${meta.image1}" class="jdd-catalog-img">`;
            if (meta.image2) imageHtml += `<img src="${meta.image2}" class="jdd-catalog-img">`;
            imageHtml += `</div>`;
        } else {
            // Placeholder
            imageHtml = `
                <div class="jdd-catalog-images" style="background:#eee; align-items:center; justify-content:center; color:#ccc;">
                    <span style="font-size:40px;">${isFolder ? '📁' : '📄'}</span>
                </div>
            `;
        }

        const size = isFolder ? '' : formatBytes(file.size);

        card.innerHTML = `
            ${imageHtml}
            <div class="jdd-catalog-info">
                <div class="jdd-catalog-title" title="${meta?.title || file.name}">${meta?.title || file.name}</div>
                <div class="jdd-catalog-meta">
                    <span>${isFolder ? 'Carpeta' : size}</span>
                </div>
                <div class="jdd-catalog-actions">
                     ${isFolder ?
                `<button class="jdd-catalog-btn jdd-btn-download-cat" onclick="event.stopPropagation(); addToQueue('${file.id}', '${file.name}')">📥 Descargar</button>`
                :
                `<button class="jdd-catalog-btn jdd-btn-download-cat" onclick="event.stopPropagation(); downloadSingleFile('${file.id}', '${file.name}')">↓ Bajar</button>`
            }
                </div>
            </div>
        `;

        // Click to navigate
        if (isFolder) {
            card.addEventListener('click', () => {
                folderHistory.push({ id: currentFolderId, name: document.title }); // fix title later
                currentFolderId = file.id;
                loadFolder(file.id, file.name);
            });
        }

        grid.appendChild(card);
    });

    container.appendChild(grid);
}

window.updateCatalogFilter = function () {
    const checkboxes = document.querySelectorAll('#jdd-filter-list input[type="checkbox"]');
    activeTags = [];
    checkboxes.forEach(cb => {
        if (cb.checked) activeTags.push(cb.value); // Value should be name or slug? App used name.
        // Actually the backend returns Slug->Name map.
        // Let's assume we match by Name for now as the API returned names in the array.
    });

    // Re-render? We need the files. 
    // Issue: renderFiles is called with 'files'. We don't have them here globally.
    // Solution: Store lastFiles globally.
    if (window.lastFiles) {
        const contentDiv = document.getElementById('jdd-content');
        contentDiv.innerHTML = '';
        if (viewMode === 'grid') {
            renderGrid(window.lastFiles, contentDiv);
        } else {
            renderList(window.lastFiles, contentDiv);
        }
    }
}

function renderList(files, contentDiv) {
    const table = document.createElement('table');
    table.className = 'jdd-explorer-list';
    table.innerHTML = `
        <thead>
            <tr>
                <th class="jdd-col-check"><input type="checkbox" id="jdd-select-all" title="Seleccionar todo"></th>
                <th>Nombre</th>
                <th>Tamaño</th>
                <th>Tipo</th>
                <th>Acciones</th>
            </tr>
        </thead>
        <tbody id="jdd-file-list-body"></tbody>
    `;

    const tbody = table.querySelector('tbody');
    const selectAllCheckbox = table.querySelector('#jdd-select-all');

    files.forEach(file => {
        // Filter logic for List View too? Yes.
        const isFolder = file.mimeType === 'application/vnd.google-apps.folder';
        const meta = (isFolder && catalogMetadata[file.id]) ? catalogMetadata[file.id] : null;
        if (activeTags.length > 0) {
            // If we rely on tags, we can only filter items that HAVE metadata.
            // If validCatalogFolderIds has this ID, we check tags.
            if (meta) {
                if (!meta.tags.some(t => activeTags.includes(t))) return;
            } else {
                return; // Hide items without metadata if filter is active
            }
        }

        const tr = document.createElement('tr');
        tr.dataset.id = file.id;
        tr.dataset.name = file.name;

        const icon = isFolder ? '📁' : getFileIcon(file.mimeType);
        const size = isFolder ? '-' : formatBytes(file.size);
        const type = isFolder ? 'Carpeta' : getFileType(file.mimeType);

        // Checkbox for folders (for batch queue)
        let checkboxHtml = isFolder
            ? `<input type="checkbox" class="jdd-item-checkbox" data-id="${file.id}" data-name="${file.name}">`
            : '';

        // Action buttons
        let actionHtml = '';
        if (isFolder) {
            actionHtml = `<button class="jdd-add-btn jdd-folder-download" data-id="${file.id}" data-name="${file.name}">📥 Descargar</button>`;
        } else {
            // Single file actions
            const isPreviewable = isMediaFile(file.mimeType);
            actionHtml = `
                <div class="jdd-action-group">
                    // ... (Prev logic)
                    <button class="jdd-item-action-btn jdd-btn-download-item jdd-download-single-btn" data-id="${file.id}" data-name="${file.name}">
                        <span class="jdd-btn-icon">↓&#xFE0E;</span>
                        <span>DESCARGAR</span>
                    </button>
                </div>
            `;
            if (isPreviewable) {
                actionHtml = `
                <div class="jdd-action-group">
                    <button class="jdd-item-action-btn jdd-btn-pause-item jdd-preview-btn" data-id="${file.id}" data-name="${file.name}" data-mime="${file.mimeType}">
                        <span class="jdd-btn-icon">►&#xFE0E;</span>
                        <span>VER</span>
                    </button>
                    <button class="jdd-item-action-btn jdd-btn-download-item jdd-download-single-btn" data-id="${file.id}" data-name="${file.name}">
                        <span class="jdd-btn-icon">↓&#xFE0E;</span>
                        <span>DESCARGAR</span>
                    </button>
                </div>`;
            }
        }

        tr.innerHTML = `
            <td class="jdd-col-check">${checkboxHtml}</td>
            <td class="jdd-name-cell">
                <span class="jdd-item-icon">${icon}</span>
                <span class="jdd-item-name">${meta?.title || file.name}</span>
            </td>
            <td class="jdd-size-cell">${size}</td>
            <td>${type}</td>
            <td class="jdd-actions-cell">${actionHtml}</td>
        `;

        // Checkbox change handler
        const checkbox = tr.querySelector('.jdd-item-checkbox');
        if (checkbox) {
            checkbox.addEventListener('change', () => handleCheckboxChange(file.id, file.name, checkbox.checked));
        }

        // Click on name to navigate (only for folders)
        if (isFolder) {
            const nameCell = tr.querySelector('.jdd-name-cell');
            nameCell.style.cursor = 'pointer';
            nameCell.addEventListener('click', () => {
                folderHistory.push({ id: currentFolderId, name: document.title });
                currentFolderId = file.id;
                loadFolder(file.id, file.name);
            });

            // Folder download button listener
            const addBtn = tr.querySelector('.jdd-folder-download');
            addBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                addToQueue(file.id, file.name);
            });
        } else {
            // Single file download button
            const downloadBtn = tr.querySelector('.jdd-download-single-btn');
            if (downloadBtn) {
                downloadBtn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    downloadSingleFile(file.id, file.name);
                });
            }

            // Preview button
            const previewBtn = tr.querySelector('.jdd-preview-btn');
            if (previewBtn) {
                previewBtn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    openPreview(file.id, file.name, file.mimeType);
                });
            }
        }

        tbody.appendChild(tr);
    });

    // Select all handler
    selectAllCheckbox.addEventListener('change', () => {
        const checkboxes = table.querySelectorAll('.jdd-item-checkbox');
        checkboxes.forEach(cb => {
            cb.checked = selectAllCheckbox.checked;
            handleCheckboxChange(cb.dataset.id, cb.dataset.name, cb.checked);
        });
    });

    contentDiv.appendChild(table);
}

// Multi-select helpers
function handleCheckboxChange(id, name, isChecked) {
    if (isChecked) {
        if (!selectedItems.find(item => item.id === id)) {
            selectedItems.push({ id, name });
        }
    } else {
        selectedItems = selectedItems.filter(item => item.id !== id);
    }
    updateBatchActionsUI();
}

function updateBatchActionsUI() {
    const batchActions = document.getElementById('jdd-batch-actions');
    const selectedCount = document.getElementById('jdd-selected-count');

    if (selectedItems.length > 0) {
        batchActions.style.display = 'flex';
        selectedCount.textContent = `${selectedItems.length} seleccionado(s)`;
    } else {
        batchActions.style.display = 'none';
    }
}

async function batchAddToQueue() {
    if (selectedItems.length === 0) return;

    // 1. Compatibility Check
    if (typeof window.showDirectoryPicker !== 'function') {
        alert('Tu navegador no soporta la descarga de carpetas. Usa Chrome o Edge en PC.');
        return;
    }

    // 2. Get Handle ONCE for all items (User Gesture required here)
    if (!globalRootHandle) {
        try {
            alert(`⚠️ SELECCIONA CARPETA DE DESTINO ⚠️\n\nSe preparará la descarga de ${selectedItems.length} carpetas.\nSelecciona la carpeta donde se guardarán todas las descargas.`);
            globalRootHandle = await window.showDirectoryPicker({ mode: 'readwrite' });
            console.log('[JDD] Got root handle for batch:', globalRootHandle);
        } catch (err) {
            console.warn('[JDD] User cancelled picker');
            return;
        }
    }

    // 3. Add all items to queue without asking for folder again
    let addedCount = 0;
    for (const item of selectedItems) {
        // Check for duplicates
        if (allQueueItems.find(q => q.id === item.id && q.status === 'pending')) {
            console.log('[JDD] Skipping duplicate:', item.name);
            continue;
        }

        const queueItem = {
            id: item.id,
            name: item.name,
            status: 'pending',
            progress: 0
        };

        allQueueItems.push(queueItem);
        addedCount++;
    }

    if (addedCount > 0) {
        updateQueueUI();
        document.getElementById('jdd-bottom-panel').style.display = 'block';
        console.log(`[JDD] Added ${addedCount} items to queue`);

        // Start processing if not busy
        if (!isDownloading) {
            processNextItem();
        }
    } else {
        alert('Todas las carpetas seleccionadas ya están en la lista de descarga.');
    }

    clearSelection();
}

function clearSelection() {
    selectedItems = [];
    const checkboxes = document.querySelectorAll('.jdd-item-checkbox');
    checkboxes.forEach(cb => cb.checked = false);
    const selectAll = document.getElementById('jdd-select-all');
    if (selectAll) selectAll.checked = false;
    updateBatchActionsUI();
}

// Panel toggle
function toggleQueuePanel() {
    const bottomPanel = document.getElementById('jdd-bottom-panel');
    if (!bottomPanel) return;

    const isHidden = window.getComputedStyle(bottomPanel).display === 'none';

    if (isHidden) {
        bottomPanel.style.display = 'block';
        // Auto-scroll to ensure it's visible if needed, but since it's fixed bottom, it should be fine
    } else {
        bottomPanel.style.display = 'none';
    }
}

function updateBreadcrumbs(folderId, folderName) {
    const breadcrumbsDiv = document.getElementById('jdd-breadcrumbs');

    // Show prominent back button if not at root
    if (folderHistory.length > 0 || (jddSettings.rootFolderId !== folderId)) {
        breadcrumbsDiv.innerHTML = `
            <button class="jdd-back-btn" onclick="goBack()">
                ⬅️ VOLVER
            </button>
            <span class="jdd-breadcrumb-separator">|</span>
            <strong class="jdd-current-folder">📁 ${folderName}</strong>
        `;
    } else {
        breadcrumbsDiv.innerHTML = `<strong class="jdd-current-folder">📁 ${folderName}</strong>`;
        folderHistory = [];
    }
}

window.goBack = function () {
    // This is a bit hacky with the global function, but works for simple prototype
    // Ideally use a proper state manager or class
    // For this MVP, we will just reload the parent or root.
    // Since we didn't store parent IDs in the file object, we rely on history or just reloading root if lost.

    // Better approach: When entering a folder, we need to store its parent.
    // But "goBack" implies popping from history.

    // Let's implement a simple "Back to Root" for now if history is complex, 
    // or implement a proper history stack in the next iteration.
    // For now, let's just reload root to be safe or implement a simple stack.

    // Re-implementing history properly:
    // We need to store the history array globally.
    // When clicking a folder -> push {id: parentId, name: parentName} to history.

    // Let's fix the click handler first to push correct data.
    // See renderFiles modification.

    // Actually, let's just reload root for safety in this step, and refine navigation in the next step.
    loadFolder(jddSettings.rootFolderId, 'Inicio');
    folderHistory = [];
    currentFolderId = jddSettings.rootFolderId;
}

// Queue State
let allQueueItems = []; // All items ever added (for history)
let currentItemIndex = -1; // Index of currently downloading item
let isDownloading = false;
let isPaused = false;
let abortController = null;
let globalRootHandle = null;

// ETA and speed tracking
let downloadStartTime = 0;
let totalBytesDownloaded = 0;
let currentFileSize = 0;
let lastSpeedUpdate = 0;
let lastBytesForSpeed = 0;
let currentSpeed = 0;

// Granular progress tracking for current item
let currentItemBytesDownloaded = 0;
let currentItemTotalBytes = 0;
let uiUpdateInterval = null;

async function addToQueue(folderId, folderName) {
    console.log('[JDD] addToQueue called:', folderId, folderName);

    // 1. Compatibility Check
    if (typeof window.showDirectoryPicker !== 'function') {
        alert('Tu navegador no soporta la descarga de carpetas. Usa Chrome o Edge en PC.');
        return;
    }

    // Check for duplicates (only block if pending or currently downloading)
    const existingItem = allQueueItems.find(item => item.id === folderId && (item.status === 'pending' || item.status === 'downloading'));
    if (existingItem) {
        alert(`Esta carpeta ya está ${existingItem.status === 'downloading' ? 'descargándose' : 'en la lista de descarga'}.`);
        return;
    }

    // 2. Get Handle IMMEDIATELY if not present (User Gesture required here)
    if (!globalRootHandle) {
        try {
            alert('⚠️ PRIMER PASO ⚠️\n\nSelecciona la carpeta donde se guardarán las descargas.');
            globalRootHandle = await window.showDirectoryPicker({ mode: 'readwrite' });
            console.log('[JDD] Got root handle:', globalRootHandle);
        } catch (err) {
            console.warn('[JDD] User cancelled picker');
            return;
        }
    }

    // 3. Create Item
    const item = {
        id: folderId,
        name: folderName,
        status: 'pending',
        progress: 0,
        isPaused: false
    };

    // Add to history
    allQueueItems.push(item);
    updateQueueUI();

    // Ensure panel is visible and expanded
    const bottomPanel = document.getElementById('jdd-bottom-panel');
    const panelBody = document.getElementById('jdd-panel-body');
    if (bottomPanel) bottomPanel.style.display = 'block';
    if (panelBody) panelBody.style.display = 'block';
    const toggleBtn = document.getElementById('jdd-toggle-queue');
    if (toggleBtn) toggleBtn.textContent = '▼';

    // 4. Start if not busy
    if (!isDownloading) {
        processNextItem();
    }
}

function processNextItem() {
    console.log('[JDD] processNextItem called, allQueueItems:', allQueueItems.length);

    // Find next pending item
    const nextIndex = allQueueItems.findIndex(item => item.status === 'pending');
    console.log('[JDD] Next pending index:', nextIndex);

    if (nextIndex === -1) {
        // If all remaining items are paused, we don't say "finished" yet
        const anyPaused = allQueueItems.some(item => item.status === 'pending' && item.isPaused);
        const statusText = document.getElementById('jdd-status-text');

        if (anyPaused) {
            isDownloading = false;
            currentItemIndex = -1;
            if (statusText) statusText.textContent = '⏸ En espera (Items pausados)';
            updateQueueUI();
            return;
        }

        isDownloading = false;
        currentItemIndex = -1;
        const pauseBtn = document.getElementById('jdd-pause-btn');
        const cancelBtn = document.getElementById('jdd-cancel-btn');
        if (pauseBtn) pauseBtn.disabled = true;
        if (cancelBtn) cancelBtn.disabled = true;
        updateQueueUI();
        console.log('[JDD] Queue finished - no pending items');
        alert('¡Todas las descargas han terminado!');
        return;
    }

    // Check if next item is paused
    let targetIndex = nextIndex;
    if (allQueueItems[nextIndex].isPaused) {
        // Look for any other non-paused pending item
        const nonPausedIndex = allQueueItems.findIndex(item => item.status === 'pending' && !item.isPaused);
        if (nonPausedIndex === -1) {
            isDownloading = false;
            const statusText = document.getElementById('jdd-status-text');
            if (statusText) statusText.textContent = '⏸ En espera (Items pausados)';
            updateQueueUI();
            return;
        }
        targetIndex = nonPausedIndex;
    }

    currentItemIndex = targetIndex;
    console.log('[JDD] Processing item at index:', currentItemIndex, allQueueItems[currentItemIndex].name);
    processItem(allQueueItems[currentItemIndex]);
}

async function processItem(item) {
    console.log('[JDD] processItem:', item.name);

    isDownloading = true;
    item.status = 'downloading';
    item.progress = 0;
    downloadStartTime = Date.now();
    totalBytesDownloaded = 0;
    lastSpeedUpdate = Date.now();
    lastBytesForSpeed = 0;
    currentSpeed = 0;
    currentItemBytesDownloaded = 0;
    currentItemTotalBytes = 0;
    currentItemTotalBytes = 0;
    updateQueueUI();

    // Track Download via API
    try {
        fetch('/wp-json/jdd/v1/track', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ folder_id: item.id, folder_name: item.name })
        });
    } catch (e) {
        console.warn('Tracking failed', e);
    }

    // Start periodic UI updates for real-time feedback
    if (uiUpdateInterval) clearInterval(uiUpdateInterval);
    uiUpdateInterval = setInterval(() => {
        if (isDownloading && !isPaused) {
            // Update progress based on bytes if we have total
            if (currentItemTotalBytes > 0) {
                item.progress = Math.min((currentItemBytesDownloaded / currentItemTotalBytes) * 100, 99.99);
            }
            updateQueueUI();
        }
    }, 250);

    const pauseBtn = document.getElementById('jdd-pause-btn');
    const cancelBtn = document.getElementById('jdd-cancel-btn');

    pauseBtn.disabled = false;
    cancelBtn.disabled = false;

    try {
        console.log('[JDD] Creating subfolder:', item.name);
        const itemDirHandle = await globalRootHandle.getDirectoryHandle(item.name, { create: true });
        console.log('[JDD] Subfolder created, starting download');

        abortController = new AbortController();
        // Use global accessToken (implied)
        await downloadFolderRecursive(item.id, itemDirHandle, abortController.signal, item);

        item.status = 'completed';
        item.progress = 100;
        console.log('[JDD] Item completed:', item.name);

    } catch (err) {
        console.error('[JDD] Error:', err);
        if (err.name === 'AbortError') {
            item.status = 'cancelled';
        } else {
            item.status = 'failed';
        }
    } finally {
        // Stop the periodic UI updater
        if (uiUpdateInterval) {
            clearInterval(uiUpdateInterval);
            uiUpdateInterval = null;
        }
        updateQueueUI();
        // Process next
        processNextItem();
    }
}

function updateQueueUI() {
    const tbody = document.getElementById('jdd-queue-body');
    const queueCount = document.getElementById('jdd-queue-count');
    const statusText = document.getElementById('jdd-status-text');
    const etaText = document.getElementById('jdd-eta');

    if (!tbody) return;

    tbody.innerHTML = '';

    // Count pending items
    const pendingCount = allQueueItems.filter(i => i.status === 'pending' || i.status === 'downloading').length;
    queueCount.textContent = pendingCount;

    if (allQueueItems.length === 0) {
        tbody.innerHTML = `<tr><td colspan="3" style="text-align:center; color:#888; padding:20px;">Lista vacía</td></tr>`;
        statusText.textContent = 'Esperando...';
        return;
    }

    allQueueItems.forEach((item, index) => {
        const tr = document.createElement('tr');
        tr.className = `jdd-queue-row jdd-status-${item.status}`;

        const pauseSymbol = item.isPaused ? '►&#xFE0E;' : '❚❚&#xFE0E;';
        const pauseLabel = item.isPaused ? 'REANUDAR' : 'PAUSAR';
        const pauseTitle = item.isPaused ? 'Reanudar' : 'Pausar';

        if (item.status === 'downloading') {
            tr.innerHTML = `
                <td colspan="4" class="jdd-active-cell">
                    <div class="jdd-active-row-content">
                        <div class="jdd-active-header">
                            <strong>${item.name}</strong>
                            <span class="jdd-inline-percent">${item.progress.toFixed(2)}%</span>
                        </div>
                        <div class="jdd-inline-progress-container">
                            <div class="jdd-inline-progress-bar" style="width: ${item.progress}%"></div>
                        </div>
                        <div class="jdd-active-footer">
                            <div class="jdd-status-info">
                                <span>${item.isPaused ? '❚❚&#xFE0E; Pausado' : '↓&#xFE0E; Descargando'}</span>
                                <span id="jdd-active-eta">${document.getElementById('jdd-eta').textContent}</span>
                            </div>
                            <div class="jdd-action-group">
                                <button class="jdd-item-action-btn jdd-btn-pause-item" onclick="togglePauseItem(${index})" title="${pauseTitle}">
                                    <span class="jdd-btn-icon">${pauseSymbol}</span>
                                    <span>${pauseLabel}</span>
                                </button>
                                <button class="jdd-item-action-btn jdd-btn-cancel-item" onclick="cancelQueueItem(${index})" title="Cancelar">
                                    <span class="jdd-btn-icon">✕&#xFE0E;</span>
                                    <span>CANCELAR</span>
                                </button>
                            </div>
                        </div>
                    </div>
                </td>
            `;
            // Keep the main status text updated as backup
            if (statusText) statusText.textContent = item.isPaused ? `Pausado: ${item.name}` : `Descargando: ${item.name}`;
        } else {
            let actionButtons = '';
            if (item.status === 'pending') {
                actionButtons = `
                    <button class="jdd-item-action-btn jdd-btn-pause-item" onclick="togglePauseItem(${index})" title="${pauseTitle}">
                        <span class="jdd-btn-icon">${pauseSymbol}</span>
                        <span>${pauseLabel}</span>
                    </button>
                    <button class="jdd-item-action-btn jdd-btn-cancel-item" onclick="cancelQueueItem(${index})" title="Cancelar">
                        <span class="jdd-btn-icon">✕&#xFE0E;</span>
                        <span>CANCELAR</span>
                    </button>
                `;
            }

            tr.innerHTML = `
                <td>${index + 1}</td>
                <td>${item.name}</td>
                <td>${getStatusLabel(item.status)}</td>
                <td><div class="jdd-action-group">${actionButtons}</div></td>
            `;
        }
        tbody.appendChild(tr);
    });

    // Update ETA and Speed
    const speedText = document.getElementById('jdd-speed-text');
    if (isDownloading && downloadStartTime > 0 && currentItemBytesDownloaded > 0) {
        const elapsed = (Date.now() - downloadStartTime) / 1000;
        const avgSpeed = currentItemBytesDownloaded / elapsed;

        // Use current speed if available, otherwise average
        const displaySpeed = currentSpeed > 0 ? currentSpeed : avgSpeed;
        speedText.textContent = `⚡ Velocidad: ${formatSpeed(displaySpeed)}`;

        // Calculate Time Remaining based on remaining bytes for the entire folder
        if (currentItemTotalBytes > 0 && avgSpeed > 0) {
            const remainingBytes = currentItemTotalBytes - currentItemBytesDownloaded;
            const remainingSeconds = remainingBytes / avgSpeed;
            etaText.textContent = `⏱ Tiempo restante: ${formatTime(remainingSeconds)}`;
        } else {
            etaText.textContent = '⏱ Tiempo restante: calculando...';
        }
    } else {
        etaText.textContent = '⏱ Tiempo restante: --:--';
        speedText.textContent = '⚡ Velocidad: -- KB/s';
    }
}

function formatTime(seconds) {
    if (!isFinite(seconds) || seconds < 0) return '--:--:--';
    const hrs = Math.floor(seconds / 3600);
    const mins = Math.floor((seconds % 3600) / 60);
    const secs = Math.floor(seconds % 60);

    if (hrs > 0) {
        return `${hrs}:${mins.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`;
    }
    return `${mins.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`;
}

function formatSpeed(bytesPerSecond) {
    if (!bytesPerSecond || bytesPerSecond <= 0) return '-- KB/s';
    if (bytesPerSecond >= 1048576) {
        return `${(bytesPerSecond / 1048576).toFixed(2)} MB/s`;
    }
    return `${(bytesPerSecond / 1024).toFixed(1)} KB/s`;
}

// Resizable panel function
function initResizablePanel() {
    const panel = document.getElementById('jdd-bottom-panel');
    const handle = document.getElementById('jdd-resize-handle');

    if (!handle || !panel) return;

    let isResizing = false;
    let startY = 0;
    let startHeight = 0;

    handle.addEventListener('mousedown', (e) => {
        isResizing = true;
        startY = e.clientY;
        startHeight = panel.offsetHeight;
        document.body.style.cursor = 'ns-resize';
        document.body.style.userSelect = 'none';
    });

    document.addEventListener('mousemove', (e) => {
        if (!isResizing) return;
        const diff = startY - e.clientY;
        const newHeight = Math.min(Math.max(startHeight + diff, 100), window.innerHeight - 100);
        panel.style.height = newHeight + 'px';
    });

    document.addEventListener('mouseup', () => {
        isResizing = false;
        document.body.style.cursor = '';
        document.body.style.userSelect = '';
    });
}

function getStatusLabel(status) {
    switch (status) {
        case 'pending': return '⏳ Pendiente';
        case 'downloading': return '⬇️ Descargando';
        case 'completed': return '✅ Listo';
        case 'failed': return '❌ Error';
        case 'cancelled': return '🚫 Cancelado';
        default: return status;
    }
}

async function downloadFolderRecursive(sourceFolderId, parentDirHandle, signal, item, isRoot = true) {
    console.log('[JDD] downloadFolderRecursive called for folder:', sourceFolderId, 'isRoot:', isRoot);
    // Use Global accessToken
    const files = await listGoogleDriveFiles(sourceFolderId);
    console.log('[JDD] Files found in folder:', files.length);

    if (files.length === 0) {
        console.log('[JDD] Folder is empty, skipping');
        return;
    }

    // Calculate total bytes for this folder (only at root level to set the total)
    if (isRoot && currentItemTotalBytes === 0) {
        currentItemTotalBytes = await calculateTotalBytes(sourceFolderId);
        console.log('[JDD] Total bytes to download:', currentItemTotalBytes);
    }

    for (const file of files) {
        console.log('[JDD] Processing file:', file.name, 'Type:', file.mimeType);

        // Check Pause State (Initial check)
        while (isPaused || item.isPaused) {
            await new Promise(r => setTimeout(r, 500));
            if (signal.aborted || item.status === 'cancelled') break;
        }
        if (signal.aborted || item.status === 'cancelled') throw new DOMException('Aborted', 'AbortError');

        // Throttling: 500ms delay between files to be nice to the API
        await new Promise(r => setTimeout(r, 500));

        if (file.mimeType === 'application/vnd.google-apps.folder') {
            console.log('[JDD] Entering subfolder:', file.name);
            const newDirHandle = await parentDirHandle.getDirectoryHandle(file.name, { create: true });
            await downloadFolderRecursive(file.id, newDirHandle, signal, item, false);
        } else {
            console.log('[JDD] Downloading file:', file.name);

            // SMART PAUSE LOOP
            let fileDownloaded = false;
            while (!fileDownloaded) {
                if (signal.aborted) throw new DOMException('Aborted', 'AbortError');

                // Wait if paused (e.g. triggered by previous error or user)
                while (isPaused || item.isPaused) {
                    await new Promise(r => setTimeout(r, 500));
                    if (signal.aborted || item.status === 'cancelled') break;
                }
                if (signal.aborted || item.status === 'cancelled') throw new DOMException('Aborted', 'AbortError');

                try {
                    await downloadFile(file, parentDirHandle, signal);
                    fileDownloaded = true; // Success, exit loop
                    console.log('[JDD] File downloaded:', file.name);

                } catch (err) {
                    console.error('[JDD] Error downloading file:', file.name, err);

                    // Check for API Limits (403 or 429)
                    if (err.message && (err.message.includes('403') || err.message.includes('429'))) {
                        console.warn('[JDD] API LIMIT DETECTED. PAUSING.');
                        isPaused = true;

                        // Notify User in UI
                        const statusText = document.getElementById('jdd-status-text');
                        if (statusText) statusText.textContent = '⛔ Límite API detectado. PAUSADO.';

                        alert(`⚠️ LÍMITE DE GOOGLE DRIVE DETECTADO ⚠️\n\nGoogle ha bloqueado temporalmente las descargas (Error 403/429).\n\nLA DESCARGA SE HA PAUSADO AUTOMÁTICAMENTE.\n\nPor favor, espera unos minutos (o hasta mañana si es límite diario) y pulsa "Reanudar" para continuar donde te quedaste.`);

                        updateQueueUI();
                        // Loop continues, will hit "while(isPaused)" at top
                    } else {
                        // Fatal error (not API limit), skip file or fail?
                        // For now, we throw to fail the folder or skip? 
                        // Current logic was to fail. Let's try to notify and maybe continue?
                        // Original behavior: throw -> fails item. Let's keep that for non-API errors.
                        throw err;
                    }
                }
            }
        }
    }
    console.log('[JDD] Folder download complete');
}

// Helper to calculate total bytes recursively
async function calculateTotalBytes(folderId) {
    let total = 0;
    const files = await listGoogleDriveFiles(folderId);
    for (const file of files) {
        if (file.mimeType === 'application/vnd.google-apps.folder') {
            total += await calculateTotalBytes(file.id);
        } else {
            total += parseInt(file.size) || 0;
        }
    }
    return total;
}

// Helper for Google Drive API Requests with Retry and Error Handling
async function googleDriveRequest(endpoint, options = {}) {
    if (!accessToken) throw new Error('No Access Token');

    const url = endpoint.startsWith('http') ? endpoint : `https://www.googleapis.com/drive/v3/${endpoint}`;
    const method = options.method || 'GET';
    const retries = options.retries || 3;

    // Default Headers
    const headers = {
        'Authorization': `Bearer ${accessToken}`,
        'Content-Type': 'application/json',
        ...options.headers
    };

    for (let i = 0; i <= retries; i++) {
        try {
            if (i > 0) {
                const delay = 1000 * Math.pow(2, i);
                console.log(`[JDD] Retrying request... (Attempt ${i + 1}/${retries + 1}) in ${delay}ms`);
                await new Promise(r => setTimeout(r, delay));
            }

            const response = await fetch(url, {
                method,
                headers,
                body: options.body,
                signal: options.signal,
                referrerPolicy: 'origin'
            });

            if (response.ok) return response; // Return full response handling to caller usually, or json?

            // Handle Limits
            if (response.status === 403 || response.status === 429) {
                throw new Error(`HTTP ${response.status} - Rate Limit`);
            }

            // If it's a 4xx error that isn't rate limit, maybe don't retry?
            if (response.status >= 400 && response.status < 500) {
                // For now, treat all as errors.
                throw new Error(`HTTP ${response.status}`);
            }

            throw new Error(`HTTP ${response.status}`);

        } catch (err) {
            if (options.signal && options.signal.aborted) throw err;
            if (i === retries) throw err; // Final failure
            // Loop continues
        }
    }
}

async function downloadFile(file, dirHandle, signal) {
    try {
        const fileHandle = await dirHandle.getFileHandle(file.name, { create: true });
        const url = `https://www.googleapis.com/drive/v3/files/${file.id}?alt=media`;

        // Use our new helper (but we need response body for stream)
        const response = await googleDriveRequest(url, { signal, retries: 5 });

        // Track bytes for ETA
        const fileSize = parseInt(file.size) || 0;
        currentFileSize = fileSize;

        const writable = await fileHandle.createWritable();
        const reader = response.body.getReader();

        while (true) {
            const { done, value } = await reader.read();
            if (done) break;
            await writable.write(value);
            totalBytesDownloaded += value.length;
            currentItemBytesDownloaded += value.length;

            // Calculate real-time speed every 500ms
            const now = Date.now();
            if (now - lastSpeedUpdate >= 500) {
                const bytesInInterval = totalBytesDownloaded - lastBytesForSpeed;
                const timeInterval = (now - lastSpeedUpdate) / 1000;
                currentSpeed = bytesInInterval / timeInterval;
                lastBytesForSpeed = totalBytesDownloaded;
                lastSpeedUpdate = now;
            }
        }

        await writable.close();
    } catch (err) {
        if (err.name === 'AbortError') throw err;
        console.error(`Error file ${file.name}:`, err);
        throw err;
    }
}

/* Removed duplicate toggleQueue function */

function togglePause() {
    isPaused = !isPaused;
    const btn = document.getElementById('jdd-pause-btn');
    const statusText = document.getElementById('jdd-status-text');

    if (isPaused) {
        btn.textContent = 'Reanudar';
        if (statusText) statusText.textContent = '⏸ Pausado por el usuario';
    } else {
        btn.textContent = 'Pausar';
        if (statusText) statusText.textContent = '▶️ Reanudando...';
    }
}

// Per-item Queue Handlers
window.togglePauseItem = function (index) {
    const item = allQueueItems[index];
    if (!item) return;

    item.isPaused = !item.isPaused;
    console.log(`[JDD] Item ${index} paused status:`, item.isPaused);

    // If we just unpaused a pending item, and we aren't downloading, trigger queue processing
    if (!item.isPaused && item.status === 'pending' && !isDownloading) {
        processNextItem();
    }

    // Special case: if we pause the active item, the main downloader loop (downloadFolderRecursive)
    // catches it via the "while(item.isPaused)" check.

    updateQueueUI();
};

window.cancelQueueItem = function (index) {
    const item = allQueueItems[index];
    if (!item) return;

    if (confirm(`¿Cancelar descarga de "${item.name}"?`)) {
        if (item.status === 'downloading') {
            // This triggers the abortController if it matches the active item
            cancelDownload();
        } else {
            item.status = 'cancelled';
            updateQueueUI();
        }
    }
};

function cancelDownload() {
    if (confirm('¿Cancelar descarga actual?')) {
        if (abortController) abortController.abort();
        isPaused = false;

        // Mark current as cancelled
        if (currentItemIndex !== -1 && allQueueItems[currentItemIndex]) {
            allQueueItems[currentItemIndex].status = 'cancelled';
            updateQueueUI();
        }

        // Small delay to allow abort to process, then try next
        setTimeout(() => {
            processNextItem();
        }, 500);
    }
}

function formatBytes(bytes, decimals = 2) {
    if (!+bytes) return '0 Bytes';
    const k = 1024;
    const dm = decimals < 0 ? 0 : decimals;
    const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return `${parseFloat((bytes / Math.pow(k, i)).toFixed(dm))} ${sizes[i]}`;
}

// Helper: Get file icon based on MIME type
function getFileIcon(mimeType) {
    if (!mimeType) return '📄';
    if (mimeType.startsWith('audio/')) return '🎵';
    if (mimeType.startsWith('video/')) return '🎬';
    if (mimeType.startsWith('image/')) return '🖼️';
    if (mimeType.includes('pdf')) return '📕';
    if (mimeType.includes('zip') || mimeType.includes('rar') || mimeType.includes('compressed')) return '📦';
    if (mimeType.includes('text') || mimeType.includes('document')) return '📝';
    return '📄';
}

// Helper: Get human-readable file type
function getFileType(mimeType) {
    if (!mimeType) return 'Archivo';
    if (mimeType.startsWith('audio/')) return 'Audio';
    if (mimeType.startsWith('video/')) return 'Video';
    if (mimeType.startsWith('image/')) return 'Imagen';
    if (mimeType.includes('pdf')) return 'PDF';
    if (mimeType.includes('zip') || mimeType.includes('rar')) return 'Comprimido';
    return 'Archivo';
}

// Helper: Check if file is previewable media
function isMediaFile(mimeType) {
    if (!mimeType) return false;
    return mimeType.startsWith('audio/') || mimeType.startsWith('video/');
}

// Helper: Check if mobile device
function isMobileDevice() {
    return /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);
}

// Download single file directly
async function downloadSingleFile(fileId, fileName) {
    console.log('[JDD] Downloading single file:', fileName);

    try {
        const url = `https://www.googleapis.com/drive/v3/files/${fileId}?alt=media`;
        const response = await fetch(url, {
            headers: {
                'Authorization': `Bearer ${accessToken}`
            }
        });

        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }

        const blob = await response.blob();
        const downloadUrl = URL.createObjectURL(blob);

        const a = document.createElement('a');
        a.href = downloadUrl;
        a.download = fileName;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(downloadUrl);

        console.log('[JDD] Single file download triggered:', fileName);
    } catch (err) {
        console.error('[JDD] Error downloading file:', err);
        alert(`Error al descargar ${fileName}: ${err.message}`);
    }
}

// Open preview modal for media files
function openPreview(fileId, fileName, mimeType) {
    console.log('[JDD] Opening preview for:', fileName, mimeType);

    // Create or get modal
    let modal = document.getElementById('jdd-preview-modal');
    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'jdd-preview-modal';
        modal.className = 'jdd-preview-modal';
        modal.innerHTML = `
            <div class="jdd-preview-overlay" onclick="closePreview()"></div>
            <div class="jdd-preview-content">
                <div class="jdd-preview-header">
                    <span id="jdd-preview-title"></span>
                    <button class="jdd-preview-close" onclick="closePreview()">✕</button>
                </div>
                <div id="jdd-preview-body" class="jdd-preview-body"></div>
            </div>
        `;
        document.body.appendChild(modal);
    }

    const previewBody = document.getElementById('jdd-preview-body');
    const previewTitle = document.getElementById('jdd-preview-title');
    previewTitle.textContent = fileName;

    // Create media element based on type
    const mediaUrl = `https://www.googleapis.com/drive/v3/files/${fileId}?alt=media`;

    if (mimeType.startsWith('audio/')) {
        previewBody.innerHTML = `
            <audio controls autoplay style="width: 100%;">
                <source src="${mediaUrl}" type="${mimeType}">
                Tu navegador no soporta audio.
            </audio>
        `;
        // Add auth header via fetch for audio
        fetchMediaForPreview(fileId, mimeType, 'audio', previewBody);
    } else if (mimeType.startsWith('video/')) {
        previewBody.innerHTML = `
            <video controls autoplay style="width: 100%; max-height: 70vh;">
                <source src="${mediaUrl}" type="${mimeType}">
                Tu navegador no soporta video.
            </video>
        `;
        fetchMediaForPreview(fileId, mimeType, 'video', previewBody);
    }

    modal.style.display = 'flex';
}

// Fetch media with auth for preview
async function fetchMediaForPreview(fileId, mimeType, mediaType, container) {
    try {
        const url = `https://www.googleapis.com/drive/v3/files/${fileId}?alt=media`;
        const response = await fetch(url, {
            headers: {
                'Authorization': `Bearer ${accessToken}`
            }
        });

        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }

        const blob = await response.blob();
        const blobUrl = URL.createObjectURL(blob);

        if (mediaType === 'audio') {
            container.innerHTML = `
                <audio controls autoplay style="width: 100%;">
                    <source src="${blobUrl}" type="${mimeType}">
                </audio>
            `;
        } else if (mediaType === 'video') {
            container.innerHTML = `
                <video controls autoplay style="width: 100%; max-height: 70vh;">
                    <source src="${blobUrl}" type="${mimeType}">
                </video>
            `;
        }
    } catch (err) {
        console.error('[JDD] Preview error:', err);
        container.innerHTML = `<p style="color: var(--jdd-danger);">Error al cargar preview: ${err.message}</p>`;
    }
}

// Close preview modal
function closePreview() {
    const modal = document.getElementById('jdd-preview-modal');
    if (modal) {
        // Stop any playing media
        const audio = modal.querySelector('audio');
        const video = modal.querySelector('video');
        if (audio) audio.pause();
        if (video) video.pause();

        modal.style.display = 'none';
        document.getElementById('jdd-preview-body').innerHTML = '';
    }
}
// Queue Control Functions
window.togglePauseItem = function (index) {
    const item = allQueueItems[index];
    if (!item) return;

    item.isPaused = !item.isPaused;
    console.log(`[JDD] Toggle pause for item ${index}:`, item.isPaused);

    // If it was the current item, we might need to continue or wait
    if (index === currentItemIndex && !item.isPaused) {
        // Resume UI etc (the loop in processItem handles the actual waiting)
    } else if (!isDownloading && !item.isPaused) {
        // If nothing is downloading and we just unpaused someone, start
        processNextItem();
    }

    updateQueueUI();
};

window.cancelQueueItem = function (index) {
    const item = allQueueItems[index];
    if (!item) return;

    if (confirm(`¿Cancelar descarga de "${item.name}"?`)) {
        item.status = 'cancelled';
        console.log(`[JDD] Cancelled item ${index}`);

        // If it's the current one, the abort controller in processItem will catch it
        // since we now check item.status in the pause loop too.

        updateQueueUI();

        if (!isDownloading) {
            processNextItem();
        }
    }
};
// Sync Catalog Items with Backend
async function syncCatalogItems(files) {
    // Filter only folders
    const folders = files.filter(f => f.mimeType === 'application/vnd.google-apps.folder');
    if (folders.length === 0) return;

    const itemsToSync = folders.map(f => ({ id: f.id, name: f.name }));

    try {
        console.log('[JDD] Syncing catalog items...', itemsToSync.length);
        const response = await fetch('/wp-json/jdd/v1/sync', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ items: itemsToSync })
        });

        if (response.ok) {
            const result = await response.json();
            console.log('[JDD] Sync items result:', result);
            // If new items were created, we might want to re-fetch metadata
            if (result.created > 0 || result.trashed > 0) {
                fetchCatalogMetadata();
            }
        }
    } catch (e) {
        console.warn('[JDD] Sync failed', e);
    }
}

// Lightbox Functions
window.openLightbox = function (imgSrc) {
    // Create lightbox if not exists
    let lightbox = document.getElementById('jdd-lightbox');
    if (!lightbox) {
        lightbox = document.createElement('div');
        lightbox.id = 'jdd-lightbox';
        lightbox.className = 'jdd-lightbox';
        lightbox.innerHTML = `
            <button class="jdd-lightbox-close" onclick="closeLightbox()">×</button>
            <img src="" id="jdd-lightbox-img" alt="Zoom">
        `;
        document.body.appendChild(lightbox);

        // Close on background click
        lightbox.addEventListener('click', (e) => {
            if (e.target === lightbox) closeLightbox();
        });
    }

    document.getElementById('jdd-lightbox-img').src = imgSrc;
    lightbox.style.display = 'flex';
};

window.closeLightbox = function () {
    const lightbox = document.getElementById('jdd-lightbox');
    if (lightbox) {
        lightbox.style.display = 'none';
        document.getElementById('jdd-lightbox-img').src = '';
    }
};


window.toggleInstructions = function () {
    const content = document.getElementById('jdd-instructions-content');
    const icon = document.getElementById('jdd-instr-icon');
    if (content.style.display === 'none') {
        content.style.display = 'block';
        icon.textContent = '▲';
    } else {
        content.style.display = 'none';
        icon.textContent = '▼';
    }
};
