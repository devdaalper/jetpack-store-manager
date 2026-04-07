/**
 * MediaVault Client 8.0 (Access Control Edition)
 * Features: Tiers, Admin Panel, Lock Visualization, WhatsApp Integration
 */

document.addEventListener('DOMContentLoaded', () => {
    console.log('[MediaVault] VERSION 8.0 LOADED - (Access Control Edition)');
    window.MediaVault = new MediaVaultApp();
    window.DownloadManager = new DownloadManager();
    window.AdminManager = new AdminManager();

    // Protect downloads from accidental page unload
    window.addEventListener('beforeunload', (e) => {
        if (window.DownloadManager && window.DownloadManager.hasActiveDownloads()) {
            e.preventDefault();
            e.returnValue = '¡Hay descargas en curso! Si sales, se cancelarán.';
            return e.returnValue;
        }
    });
});

// Safe reload helper - asks confirmation if downloads are active
function safeReload() {
    // IMPORTANT: Do not reload the page. Reloading cancels in-progress downloads.
    // Use a soft refresh (AJAX) instead and let the user refresh manually via the browser if needed.
    try {
        if (window.MediaVault && typeof window.MediaVault.softRefresh === 'function') {
            window.MediaVault.softRefresh();
            return;
        }
    } catch (e) {
        // noop
    }
    alert('No se pudo refrescar sin recargar. Si necesitas recargar, usa el botón de refrescar del navegador (⚠️ puede cancelar descargas).');
}

// Basic escaping helpers (S3 object names are untrusted input).
function mvEscapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function mvEscapeAttr(value) {
    // Attributes are quoted, but escape backticks too as defense-in-depth.
    return mvEscapeHtml(value).replace(/`/g, '&#96;');
}

function mvFlagEnabled(value) {
    return value === true || value === 1 || value === '1' || value === 'true';
}

// ============================================
// DOWNLOAD MANAGER CLASS
// ============================================

class DownloadManager {
    constructor() {
        this.downloads = new Map(); // id -> DownloadState
        this.activeDownloadId = null;
        this.panelVisible = false;

        // DOM Elements
        this.toggle = document.getElementById('mv-dm-toggle');
        this.badge = document.getElementById('mv-dm-badge');
        this.panel = document.getElementById('mv-dm-panel');
        this.body = document.getElementById('mv-dm-body');
        this.countSpan = document.getElementById('mv-dm-count');

        this.bindEvents();
    }

    // Check if there are active downloads (not completed/cancelled)
    hasActiveDownloads() {
        for (const [id, state] of this.downloads) {
            if (state.status === 'downloading' || state.status === 'queued' || state.status === 'paused') {
                return true;
            }
        }
        return false;
    }

    bindEvents() {
        // Toggle panel visibility
        this.toggle?.addEventListener('click', () => this.togglePanel());

        // Minimize button
        document.getElementById('mv-dm-minimize')?.addEventListener('click', () => this.togglePanel());

        // Close button
        document.getElementById('mv-dm-close')?.addEventListener('click', () => this.togglePanel());

        // Delegate click events for action buttons
        this.body?.addEventListener('click', (e) => {
            const btn = e.target.closest('.mv-dm-action-btn');
            if (!btn) return;

            const item = btn.closest('.mv-dm-item');
            const id = item?.dataset.id;
            if (!id) return;

            if (btn.classList.contains('pause')) {
                this.pauseOrResume(id);
            } else if (btn.classList.contains('delete')) {
                this.removeDownload(id);
            }
        });
    }

    generateId() {
        return 'dl_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
    }

    // Add a new download to the queue
    addDownload(folderName, files, rootHandle, meta = {}) {
        const id = this.generateId();

        const state = {
            id,
            folderName,
            files,
            rootHandle,
            currentIndex: 0,
            totalFiles: files.length,
            downloadedBytes: 0,
            totalBytes: 0, // Will be estimated
            status: 'queued', // queued | downloading | paused | completed | error
            speed: 0,
            eta: 0,
            startTime: null,
            pauseFlag: false,
            errors: [],
            objectPathNorm: String(meta.objectPathNorm || ''),
            bytesAuthorized: Number.isFinite(Number(meta.bytesAuthorized)) ? Math.max(0, Number(meta.bytesAuthorized)) : 0,
            onComplete: (typeof meta.onComplete === 'function') ? meta.onComplete : null
        };

        this.downloads.set(id, state);
        this.updateUI();
        this.showToggle(true);
        this.openPanel();

        // Start processing if nothing is active
        if (!this.activeDownloadId) {
            this.processNext();
        }

        return id;
    }

    async processNext() {
        // Find next queued download
        for (const [id, state] of this.downloads) {
            if (state.status === 'queued') {
                this.activeDownloadId = id;
                await this.startDownload(id);
                return;
            }
        }

        // No more queued downloads
        this.activeDownloadId = null;
    }

    async startDownload(id) {
        const state = this.downloads.get(id);
        if (!state) return;

        state.status = 'downloading';
        state.startTime = Date.now();
        this.updateItemUI(id);

        const { files, rootHandle } = state;

        for (let i = state.currentIndex; i < files.length; i++) {
            // Check for pause/cancel
            if (state.pauseFlag) {
                state.status = 'paused';
                state.pauseFlag = false;
                this.updateItemUI(id);
                this.processNext();
                return;
            }

            if (state.status === 'cancelled') {
                this.downloads.delete(id);
                this.updateUI();
                this.processNext();
                return;
            }

            state.currentIndex = i;
            const file = files[i];

            try {
                // Build folder structure
                const pathParts = file.path.split('/');
                const fileName = pathParts.pop();
                let currentHandle = rootHandle;

                for (const part of pathParts) {
                    if (part) {
                        currentHandle = await currentHandle.getDirectoryHandle(part, { create: true });
                    }
                }

                // Measure download time for speed calculation
                const downloadStart = Date.now();

                // Fetch file
                const response = await fetch(file.url);
                const blob = await response.blob();

                // Update metrics
                const downloadTime = (Date.now() - downloadStart) / 1000;
                state.downloadedBytes += blob.size;
                state.totalBytes += blob.size;

                // Calculate speed (bytes/second)
                if (downloadTime > 0) {
                    state.speed = blob.size / downloadTime;
                }

                // Estimate ETA
                const remainingFiles = files.length - i - 1;
                const avgFileSize = state.downloadedBytes / (i + 1);
                const remainingBytes = avgFileSize * remainingFiles;
                state.eta = state.speed > 0 ? remainingBytes / state.speed : 0;

                // Write file
                const fileHandle = await currentHandle.getFileHandle(fileName, { create: true });
                const writable = await fileHandle.createWritable();
                await writable.write(blob);
                await writable.close();

            } catch (err) {
                console.error(`[DM] Error downloading ${file.name}:`, err);
                state.errors.push(file.name);
            }

            this.updateItemUI(id);

            // Small delay between files
            await new Promise(r => setTimeout(r, 300));
        }

        // Completed
        state.status = 'completed';
        state.currentIndex = files.length;
        this.updateItemUI(id);

        if (typeof state.onComplete === 'function') {
            try {
                state.onComplete({
                    id: state.id,
                    folderName: state.folderName,
                    objectPathNorm: state.objectPathNorm,
                    bytesObserved: Math.max(0, Math.round(state.downloadedBytes)),
                    bytesAuthorized: Math.max(0, Math.round(state.bytesAuthorized)),
                    totalFiles: state.totalFiles,
                    errorsCount: state.errors.length
                });
            } catch (e) {
                // Passive analytics callback must never break download UX.
            }
        }

        // Auto-remove after 30 seconds
        setTimeout(() => {
            if (this.downloads.get(id)?.status === 'completed') {
                this.downloads.delete(id);
                this.updateUI();
            }
        }, 30000);

        this.activeDownloadId = null;
        this.processNext();
    }

    pauseOrResume(id) {
        const state = this.downloads.get(id);
        if (!state) return;

        if (state.status === 'downloading') {
            state.pauseFlag = true; // Will pause on next iteration
        } else if (state.status === 'paused') {
            state.status = 'queued';
            this.updateItemUI(id);
            if (!this.activeDownloadId) {
                this.processNext();
            }
        }
    }

    removeDownload(id) {
        const state = this.downloads.get(id);
        if (!state) return;

        if (state.status === 'downloading') {
            state.status = 'cancelled';
        } else {
            this.downloads.delete(id);
        }

        this.updateUI();

        if (this.activeDownloadId === id) {
            this.activeDownloadId = null;
            this.processNext();
        }
    }

    // --- UI Methods ---

    showToggle(visible) {
        this.toggle?.classList.toggle('visible', visible);
    }

    togglePanel() {
        this.panelVisible = !this.panelVisible;
        this.panel?.classList.toggle('open', this.panelVisible);
    }

    openPanel() {
        this.panelVisible = true;
        this.panel?.classList.add('open');
    }

    updateUI() {
        const count = this.downloads.size;

        // Update badge
        if (this.badge) this.badge.textContent = count;
        if (this.countSpan) this.countSpan.textContent = `(${count})`;

        // Show/hide toggle
        this.showToggle(count > 0);

        // Render items
        this.renderItems();
    }

    updateItemUI(id) {
        const existing = this.body?.querySelector(`[data-id="${id}"]`);
        if (existing) {
            const state = this.downloads.get(id);
            if (state) {
                existing.outerHTML = this.renderItem(state);
            }
        } else {
            this.renderItems();
        }

        // Update count
        const count = this.downloads.size;
        if (this.badge) this.badge.textContent = count;
        if (this.countSpan) this.countSpan.textContent = `(${count})`;
    }

    renderItems() {
        if (!this.body) return;

        if (this.downloads.size === 0) {
            this.body.innerHTML = '<div class="mv-dm-empty">No hay descargas activas</div>';
            return;
        }

        let html = '';
        for (const [id, state] of this.downloads) {
            html += this.renderItem(state);
        }
        this.body.innerHTML = html;
    }

    renderItem(state) {
        const { id, folderName, currentIndex, totalFiles, status, speed, eta } = state;

        const percent = totalFiles > 0 ? Math.round((currentIndex / totalFiles) * 100) : 0;
        const speedStr = this.formatSpeed(speed);
        const etaStr = this.formatEta(eta);

        // Status icon
        let statusIcon = '📁';
        if (status === 'completed') statusIcon = '✅';
        else if (status === 'paused') statusIcon = '⏸️';
        else if (status === 'error') statusIcon = '❌';
        else if (status === 'queued') statusIcon = '⏳';

        // Pause/Resume button - use TEXT instead of emojis
        let pauseBtnText = status === 'paused' ? 'Reanudar' : 'Pausar';
        const showPauseBtn = status === 'downloading' || status === 'paused';

        // Stats text
        let statsText = '';
        if (status === 'downloading') {
            statsText = `
                <span>↓ ${speedStr}</span>
                <span>${currentIndex}/${totalFiles}</span>
                <span>~${etaStr}</span>
            `;
        } else if (status === 'paused') {
            statsText = `<span>Pausado • ${currentIndex}/${totalFiles}</span>`;
        } else if (status === 'completed') {
            statsText = `<span>¡Completado! ${totalFiles} archivos</span>`;
        } else if (status === 'queued') {
            statsText = `<span>En espera • ${totalFiles} archivos</span>`;
        }

        return `
            <div class="mv-dm-item ${status}" data-id="${mvEscapeAttr(id)}">
                <div class="mv-dm-item-header">
                    <div class="mv-dm-item-name">${statusIcon} ${mvEscapeHtml(folderName)}</div>
                    <div class="mv-dm-item-actions">
                        ${showPauseBtn ? `<button class="mv-dm-action-btn pause">${pauseBtnText}</button>` : ''}
                        <button class="mv-dm-action-btn delete">Quitar</button>
                    </div>
                </div>
                <div class="mv-dm-progress-container">
                    <div class="mv-dm-progress-bar">
                        <div class="mv-dm-progress-fill" style="width: ${percent}%"></div>
                    </div>
                    <div class="mv-dm-progress-percent">${percent}%</div>
                </div>
                <div class="mv-dm-stats">${statsText}</div>
            </div>
        `;
    }

    formatSpeed(bytesPerSecond) {
        if (bytesPerSecond <= 0) return '—';
        if (bytesPerSecond < 1024) return bytesPerSecond.toFixed(0) + ' B/s';
        if (bytesPerSecond < 1024 * 1024) return (bytesPerSecond / 1024).toFixed(1) + ' KB/s';
        return (bytesPerSecond / (1024 * 1024)).toFixed(1) + ' MB/s';
    }

    formatEta(seconds) {
        if (seconds <= 0 || !isFinite(seconds)) return '—';
        if (seconds < 60) return '< 1 min';
        if (seconds < 3600) return Math.ceil(seconds / 60) + ' min';
        return Math.round(seconds / 3600) + ' h';
    }
}

// ============================================
// MEDIAVAULT APP CLASS
// ============================================

class MediaVaultApp {
    constructor() {
        // Merge mv_params into userData
        const params = window.mv_params || {};
        const globalData = window.MV_USER_DATA || {};

        this.userData = { ...globalData, ...params };
        this.userData.nonce = this.userData.nonce || this.userData.access_nonce || '';

        // Normalize fields
        if (this.userData.user_tier !== undefined) {
            this.userData.tier = parseInt(this.userData.user_tier);
        }
        if (this.userData.remaining_plays !== undefined) {
            this.userData.remainingPlays = parseInt(this.userData.remaining_plays);
        }

        this.mediaExtensions = this.normalizeMediaExtensions(this.userData.mediaExtensions || {});
        this.currentFilter = 'all'; // State: all, audio, video
        this.currentBpmFilter = 'all'; // State: all, "100-109", etc.
        this.currentDepth = 0; // junction-relative depth reported by the server
        this.isSearching = false;
        this.lastSearchQuery = '';
        this.lastSearchMeta = null;
        this._lastSearchIndexState = '';
        this.searchSession = null; // pre-search UI/URL snapshot for exit without reload
        this.desktopNoticeSessionKey = 'mv_desktop_notice_ack';
        this.desktopNoticeAckMemory = false;
        this.desktopNoticeShownTracked = false;
        this.desktopNoticePendingAction = null;
        this.desktopNoticeAutoPopupShown = false;

        // Track current folder to support non-reload restores even when URL has no folder param.
        this.currentFolder = this.normalizeFolderPath(this.getCurrentFolderFromUrl() || this.getCurrentFolderFromSidebarActive());

        // Abort in-flight requests to prevent races and improve perceived speed.
        this._folderFetchController = null;
        this._folderRequestSeq = 0;
        this._searchFetchController = null;
        this._searchRequestSeq = 0;
        this._searchTimer = null;

        // Fast UI: in-memory folder payload cache (AJAX responses) to avoid repeated slow listings.
        // This is session-only (no localStorage) to avoid stale content and keep behavior predictable.
        this._folderCache = new Map(); // key -> { ts, data }
        this._folderCacheMax = 10;
        this._folderCacheTtlMs = 90 * 1000; // 90s: enough to feel instant when navigating back/forward/search-clear

        // Loading UX helpers (reduce "se trabo" perception on slow folders).
        this._loadingEl = null;
        this._loadingShowTimer = null;
        this._loadingSlowTimer = null;

        // In-app folder navigation (Back/Forward) to keep users away from browser back/refresh.
        // IMPORTANT: This must never cause a full page reload (downloads must keep running).
        this._navStack = [];
        this._navIndex = -1;
        this._navBackBtn = null;
        this._navForwardBtn = null;
        this._navHomeBtn = null;
        this.bindEvents();
        this.restoreView();
        this.currentDepth = this.getCurrentDepthFromBreadcrumbs();

        // --- AJAX Navigation ---
        this.initAjaxNavigation();

        // Keep sidebar highlight consistent even when navigating via AJAX.
        this.updateSidebarActive(this.getCurrentFolderFromUrl() || this.getCurrentFolderFromSidebarActive());
        this.initDesktopRecommendationNotice();
    }

    normalizeFolderPath(path) {
        // Normalize folder paths so comparisons are stable:
        // - no leading/trailing slashes
        // - always trailing slash for folders
        path = (path || '').toString().trim();
        path = path.replace(/^\/+|\/+$/g, '');
        if (!path) return '';
        return path + '/';
    }

    getCurrentFolderFromUrl() {
        try {
            const params = new URLSearchParams(window.location.search);
            return params.get('folder') || '';
        } catch (e) {
            return '';
        }
    }

    getCurrentFolderFromSidebarActive() {
        const active = document.querySelector('.mv-nav-item.active');
        if (!active) return '';
        try {
            const url = new URL(active.getAttribute('href') || '', window.location.origin);
            return url.searchParams.get('folder') || '';
        } catch (e) {
            return '';
        }
    }

    getCurrentDepthFromBreadcrumbs() {
        // Keep a best-effort depth value even when we haven't rendered via AJAX yet.
        // Depth is junction-relative; in this UI the breadcrumb count matches the depth.
        const bc = document.getElementById('mv-breadcrumbs');
        if (!bc) return 0;
        return bc.querySelectorAll('a, span:not(.separator)').length;
    }

    getUserTier() {
        const rawTier = (this.userData && this.userData.tier !== undefined)
            ? this.userData.tier
            : (this.userData?.user_tier ?? 0);
        const parsed = parseInt(rawTier, 10);
        return Number.isFinite(parsed) ? parsed : 0;
    }

    normalizeMediaExtensions(rawMap) {
        const fallback = {
            audio: ['mp3', 'wav', 'flac', 'm4a', 'ogg', 'aac'],
            video: ['mp4', 'mov', 'mkv', 'avi', 'webm', 'wmv']
        };

        const map = (rawMap && typeof rawMap === 'object') ? rawMap : fallback;
        const audio = Array.isArray(map.audio) ? map.audio : fallback.audio;
        const video = Array.isArray(map.video) ? map.video : fallback.video;

        return {
            audio: Array.from(new Set(audio.map(ext => String(ext || '').toLowerCase()).filter(Boolean))),
            video: Array.from(new Set(video.map(ext => String(ext || '').toLowerCase()).filter(Boolean)))
        };
    }

    fileExtension(value) {
        const str = String(value || '').toLowerCase();
        const parts = str.split('.');
        return (parts.length > 1) ? parts.pop() : '';
    }

    isAudioFile(value) {
        const ext = this.fileExtension(value);
        return !!ext && this.mediaExtensions.audio.includes(ext);
    }

    isVideoFile(value) {
        const ext = this.fileExtension(value);
        return !!ext && this.mediaExtensions.video.includes(ext);
    }

    getBpmRangeFromFilter() {
        const raw = String(this.currentBpmFilter || 'all').trim().toLowerCase();
        if (!raw || raw === 'all') return null;
        const m = raw.match(/^(\d{2,3})-(\d{2,3})$/);
        if (!m) return null;
        let min = parseInt(m[1], 10);
        let max = parseInt(m[2], 10);
        if (!Number.isFinite(min) || !Number.isFinite(max)) return null;
        if (min > max) {
            const tmp = min;
            min = max;
            max = tmp;
        }
        return { min, max };
    }

    notifyIndexState(meta) {
        if (!meta || typeof meta !== 'object') return;
        const state = String(meta.index_state || '');
        if (!state || state === this._lastSearchIndexState) return;

        this._lastSearchIndexState = state;

        if (state === 'stale') {
            this.showToast('Aviso: el índice está desactualizado; algunos resultados pueden faltar.', 'info');
        } else if (state === 'empty') {
            this.showToast('Aviso: el índice está vacío; sincroniza para habilitar resultados completos.', 'info');
        }
    }

    isMobileLike() {
        const ua = (navigator.userAgent || '').toLowerCase();
        const mobileUa = /android|iphone|ipad|ipod|iemobile|opera mini|blackberry|mobile|mobi/i.test(ua);
        const touchPoints = Number(navigator.maxTouchPoints || 0);
        const hasTouch = touchPoints > 0 || ('ontouchstart' in window);
        const coarsePointer = window.matchMedia ? window.matchMedia('(pointer: coarse)').matches : false;
        const cssMobile = window.matchMedia ? window.matchMedia('(max-width: 1024px)').matches : false;
        const viewportWidth = Math.max(document.documentElement?.clientWidth || 0, window.innerWidth || 0);
        const viewportHeight = Math.max(document.documentElement?.clientHeight || 0, window.innerHeight || 0);
        const compactViewport = Math.min(viewportWidth, viewportHeight) <= 900 || viewportWidth <= 1200;

        // Heuristica tolerante:
        // - UA movil explicito, o
        // - viewport estilo movil (CSS breakpoint), o
        // - dispositivo tactil con viewport compacto (incluye algunos "desktop mode").
        return mobileUa || cssMobile || (hasTouch && (coarsePointer || compactViewport));
    }

    isDesktopNoticeEligible() {
        const tier = this.getUserTier();
        const rawAdmin = this.userData ? this.userData.isAdmin : false;
        const isAdmin = mvFlagEnabled(rawAdmin);
        return this.isMobileLike() && tier > 0 && !isAdmin;
    }

    hasDesktopNoticeAck() {
        if (this.desktopNoticeAckMemory) return true;

        try {
            return window.sessionStorage.getItem(this.desktopNoticeSessionKey) === '1';
        } catch (e) {
            return false;
        }
    }

    setDesktopNoticeAck() {
        this.desktopNoticeAckMemory = true;
        try {
            window.sessionStorage.setItem(this.desktopNoticeSessionKey, '1');
        } catch (e) {
            // Ignore storage limitations (private mode, blocked storage, etc).
        }
    }

    shouldShowDesktopRecommendationNotice() {
        return this.isDesktopNoticeEligible() && !this.hasDesktopNoticeAck();
    }

    async trackDesktopNoticeEvent(eventName) {
        if (!this.isDesktopNoticeEligible()) return;
        if (!['shown', 'dismissed', 'continue_anyway'].includes(eventName)) return;

        try {
            await this.requestActionV2('mv_track_mobile_notice_event', { event: eventName });
        } catch (e) {
            // Passive analytics must never interrupt UX.
        }
    }

    generateBehaviorEventUuid() {
        const rand = Math.random().toString(16).slice(2, 10);
        const ts = Date.now().toString(16).slice(-8);
        return `mv-${ts}-${rand}`;
    }

    detectBehaviorDeviceClass() {
        try {
            const ua = (navigator.userAgent || '').toLowerCase();
            if (/ipad|tablet|kindle|silk/.test(ua)) return 'tablet';
            if (/mobile|iphone|ipod|android|iemobile|opera mini|mobi/.test(ua)) return 'mobile';
            return 'desktop';
        } catch (e) {
            return 'unknown';
        }
    }

    trackBehaviorEvent(eventName, fields = {}) {
        if (!eventName) return;
        const payload = {
            event_name: eventName,
            event_uuid: this.generateBehaviorEventUuid(),
            source_screen: 'mediavault_vault',
            device_class: this.detectBehaviorDeviceClass(),
            tier: Number.isFinite(parseInt(this.userData?.tier, 10)) ? parseInt(this.userData.tier, 10) : 0,
            region: this.userData?.region || 'unknown',
            ...fields
        };

        this.requestActionV2('jpsm_track_behavior_event', payload).catch(() => {
            // Behavior analytics is fail-open by design.
        });
    }

    showDesktopRecommendationBanner() {
        const banner = document.getElementById('mv-desktop-recommendation-banner');
        if (!banner) return;

        banner.style.display = 'flex';
        banner.setAttribute('aria-hidden', 'false');

        if (!this.desktopNoticeShownTracked) {
            this.desktopNoticeShownTracked = true;
            this.trackDesktopNoticeEvent('shown');
        }
    }

    hideDesktopRecommendationBanner() {
        const banner = document.getElementById('mv-desktop-recommendation-banner');
        if (!banner) return;
        banner.style.display = 'none';
        banner.setAttribute('aria-hidden', 'true');
    }

    showDesktopRecommendationModal() {
        const modal = document.getElementById('mv-desktop-recommendation-modal');
        if (!modal) return;
        modal.style.display = 'flex';
        modal.setAttribute('aria-hidden', 'false');
    }

    hideDesktopRecommendationModal() {
        const modal = document.getElementById('mv-desktop-recommendation-modal');
        if (!modal) return;
        modal.style.display = 'none';
        modal.setAttribute('aria-hidden', 'true');
    }

    runDesktopRecommendationGate(onContinue) {
        if (typeof onContinue !== 'function') return;

        if (!this.shouldShowDesktopRecommendationNotice()) {
            onContinue();
            return;
        }

        this.desktopNoticePendingAction = onContinue;
        this.showDesktopRecommendationModal();
    }

    bindDesktopRecommendationControls() {
        const dismissBtn = document.getElementById('mv-desktop-recommendation-dismiss');
        const cancelBtn = document.getElementById('mv-desktop-recommendation-cancel');
        const continueBtn = document.getElementById('mv-desktop-recommendation-continue');
        const modal = document.getElementById('mv-desktop-recommendation-modal');

        if (dismissBtn && !dismissBtn.dataset.mvBound) {
            dismissBtn.dataset.mvBound = '1';
            dismissBtn.addEventListener('click', () => {
                this.trackDesktopNoticeEvent('dismissed');
                this.setDesktopNoticeAck();
                this.hideDesktopRecommendationBanner();
                this.hideDesktopRecommendationModal();
            });
        }

        if (cancelBtn && !cancelBtn.dataset.mvBound) {
            cancelBtn.dataset.mvBound = '1';
            cancelBtn.addEventListener('click', () => {
                this.trackDesktopNoticeEvent('dismissed');
                this.hideDesktopRecommendationModal();
                this.desktopNoticePendingAction = null;
            });
        }

        if (continueBtn && !continueBtn.dataset.mvBound) {
            continueBtn.dataset.mvBound = '1';
            continueBtn.addEventListener('click', () => {
                const pending = this.desktopNoticePendingAction;
                this.desktopNoticePendingAction = null;

                this.trackDesktopNoticeEvent('continue_anyway');
                this.setDesktopNoticeAck();
                this.hideDesktopRecommendationBanner();
                this.hideDesktopRecommendationModal();

                if (typeof pending === 'function') {
                    pending();
                }
            });
        }

        if (modal && !modal.dataset.mvBound) {
            modal.dataset.mvBound = '1';
            modal.addEventListener('click', (e) => {
                if (e.target !== modal) return;
                this.trackDesktopNoticeEvent('dismissed');
                this.hideDesktopRecommendationModal();
                this.desktopNoticePendingAction = null;
            });
        }
    }

    initDesktopRecommendationNotice() {
        this.bindDesktopRecommendationControls();

        if (!this.shouldShowDesktopRecommendationNotice()) {
            this.hideDesktopRecommendationBanner();
            this.hideDesktopRecommendationModal();
            return;
        }

        this.showDesktopRecommendationBanner();
        if (!this.desktopNoticeAutoPopupShown) {
            this.desktopNoticeAutoPopupShown = true;
            this.showDesktopRecommendationModal();
        }
    }

    beginSearchSessionIfNeeded() {
        if (this.searchSession) return;
        const grid = document.getElementById('mv-grid');
        const preUrl = window.location.pathname + window.location.search;
        const preFolderFromUrl = this.normalizeFolderPath(this.getCurrentFolderFromUrl());
        const preForceListView = !!grid && grid.classList.contains('force-list-view');
        const titleEl = document.getElementById('mv-hero-title');
        const descEl = document.getElementById('mv-hero-desc');
        const iconEl = document.getElementById('mv-hero-icon');
        const bcEl = document.getElementById('mv-breadcrumbs');

        // Snapshot the current folder view so exiting search can be instant (no network).
        const snapshot = {
            heroTitle: titleEl ? titleEl.textContent : '',
            heroDescHtml: descEl ? descEl.innerHTML : '',
            heroIcon: iconEl ? iconEl.textContent : '',
            breadcrumbsHtml: bcEl ? bcEl.innerHTML : '',
            gridHtml: grid ? grid.innerHTML : '',
            currentFolder: this.currentFolder || preFolderFromUrl || ''
        };

        this.searchSession = { preUrl, preFolderFromUrl, preForceListView, snapshot };
    }

    _getFolderCacheEntry(folderKey) {
        const key = this.normalizeFolderPath(folderKey);
        if (!this._folderCache.has(key)) return null;

        const entry = this._folderCache.get(key);
        if (!entry || !entry.ts || !entry.data) return null;

        const age = Date.now() - entry.ts;
        if (age > this._folderCacheTtlMs) {
            this._folderCache.delete(key);
            return null;
        }

        // refresh LRU order
        this._folderCache.delete(key);
        this._folderCache.set(key, entry);
        return entry;
    }

    _setFolderCacheEntry(folderKey, data) {
        const key = this.normalizeFolderPath(folderKey);
        if (!key && key !== '') return;
        if (this._folderCache.has(key)) this._folderCache.delete(key);
        this._folderCache.set(key, { ts: Date.now(), data });
        while (this._folderCache.size > this._folderCacheMax) {
            const firstKey = this._folderCache.keys().next().value;
            this._folderCache.delete(firstKey);
        }
    }

    _ensureLoadingStyles() {
        if (document.getElementById('mv-loading-styles')) return;
        const style = document.createElement('style');
        style.id = 'mv-loading-styles';
        style.textContent = `
            #mv-grid-loading {
                display: none;
                align-items: center;
                gap: 12px;
                padding: 10px 12px;
                border-radius: 999px;
                border: 1px solid var(--mv-border);
                background: rgba(255, 255, 255, 0.92);
                backdrop-filter: blur(8px);
                box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.08), 0 8px 10px -6px rgba(0, 0, 0, 0.06);
                color: var(--mv-text);
                width: min(560px, calc(100vw - 32px));
            }
            #mv-grid-loading .mv-spinner {
                width: 16px;
                height: 16px;
                border-radius: 999px;
                border: 2px solid var(--mv-border);
                border-top-color: var(--mv-accent);
                animation: mvspin 0.9s linear infinite;
                flex: 0 0 auto;
            }
            #mv-grid-loading .mv-loading-title {
                font-weight: 700;
                font-size: 13px;
                line-height: 1.2;
            }
            #mv-grid-loading .mv-loading-sub {
                font-size: 12px;
                color: var(--mv-text-muted);
                line-height: 1.2;
                margin-top: 2px;
            }
            @keyframes mvspin { to { transform: rotate(360deg); } }
        `;
        document.head.appendChild(style);
    }

    _ensureLoadingEl() {
        // Prefer the sticky toolbar host so the loader remains visible even when the user has scrolled.
        // Fallback to the grid if the host isn't present (older templates / unexpected markup).
        const host = document.getElementById('mv-toolbar-status') || document.getElementById('mv-grid');
        if (!host) return null;

        this._ensureLoadingStyles();

        let el = document.getElementById('mv-grid-loading');
        if (!el) {
            el = document.createElement('div');
            el.id = 'mv-grid-loading';
            el.innerHTML = `
                <div class="mv-spinner" aria-hidden="true"></div>
                <div style="min-width: 0;">
                    <div class="mv-loading-title" id="mv-grid-loading-title">Cargando...</div>
                    <div class="mv-loading-sub" id="mv-grid-loading-sub">Preparando contenido</div>
                </div>
            `;
            host.appendChild(el);
        } else if (el.parentElement !== host) {
            host.appendChild(el);
        }

        this._loadingEl = el;
        return el;
    }

    _showLoading(title = 'Cargando...', sub = 'Preparando contenido') {
        const el = this._ensureLoadingEl();
        if (!el) return;

        const titleEl = document.getElementById('mv-grid-loading-title');
        const subEl = document.getElementById('mv-grid-loading-sub');
        if (titleEl) titleEl.textContent = title;
        if (subEl) subEl.textContent = sub;

        // Avoid flicker on very fast responses: show after a short delay.
        if (this._loadingShowTimer) clearTimeout(this._loadingShowTimer);
        this._loadingShowTimer = setTimeout(() => {
            el.style.display = 'flex';
        }, 120);

        // If it takes too long, set expectations (remote folder listing can be slow on big folders).
        if (this._loadingSlowTimer) clearTimeout(this._loadingSlowTimer);
        this._loadingSlowTimer = setTimeout(() => {
            const sub2 = document.getElementById('mv-grid-loading-sub');
            if (sub2) sub2.textContent = 'Esto puede tardar si la carpeta es grande. No cierres la pagina (cancelaria descargas).';
        }, 1200);
    }

    _hideLoading() {
        if (this._loadingShowTimer) {
            clearTimeout(this._loadingShowTimer);
            this._loadingShowTimer = null;
        }
        if (this._loadingSlowTimer) {
            clearTimeout(this._loadingSlowTimer);
            this._loadingSlowTimer = null;
        }
        const el = document.getElementById('mv-grid-loading');
        if (el) el.style.display = 'none';
    }

    exitSearchMode() {
        const session = this.searchSession;
        if (this._searchTimer) {
            clearTimeout(this._searchTimer);
            this._searchTimer = null;
        }
        if (this._searchFetchController && typeof this._searchFetchController.abort === 'function') {
            this._searchFetchController.abort();
        }
        this._searchRequestSeq++; // invalidate any in-flight search response
        this.isSearching = false;
        this.lastSearchQuery = '';

        this._hideLoading();

        const grid = document.getElementById('mv-grid');
        if (grid && session && !session.preForceListView) {
            grid.classList.remove('force-list-view');
        }

        const restoreUrl = (session && session.preUrl) ? session.preUrl : (window.location.pathname + window.location.search);
        if (restoreUrl) {
            // Preserve MediaVault navigation state (Back/Forward buttons rely on mv/navId in history.state).
            const prev = history.state;
            const navId = (prev && typeof prev === 'object' && prev.mv && typeof prev.navId === 'string')
                ? prev.navId
                : this._newNavId();
            const folderForState = this.normalizeFolderPath(
                (session && session.snapshot && session.snapshot.currentFolder)
                    ? session.snapshot.currentFolder
                    : (session && session.preFolderFromUrl)
                        ? session.preFolderFromUrl
                        : (this.currentFolder || this.getCurrentFolderFromUrl() || '')
            );
            const st = { mv: true, folder: folderForState, navId: navId };
            history.replaceState(st, '', restoreUrl);
            this._navReplace(st);
        }

        // If the previous URL had no folder param, keep it that way and let the server pick the junction default.
        const restoreFolder = (session && (session.preFolderFromUrl || (session.snapshot && session.snapshot.currentFolder)))
            ? (session.snapshot && session.snapshot.currentFolder ? session.snapshot.currentFolder : session.preFolderFromUrl)
            : this.normalizeFolderPath(this.getCurrentFolderFromUrl());

        // Instant restore: bring back the pre-search DOM snapshot so it never "feels stuck".
        if (session && session.snapshot && grid && session.snapshot.gridHtml) {
            const titleEl = document.getElementById('mv-hero-title');
            const descEl = document.getElementById('mv-hero-desc');
            const iconEl = document.getElementById('mv-hero-icon');
            const bcEl = document.getElementById('mv-breadcrumbs');

            if (titleEl) titleEl.textContent = session.snapshot.heroTitle || '';
            if (descEl) descEl.innerHTML = session.snapshot.heroDescHtml || '';
            if (iconEl) iconEl.textContent = session.snapshot.heroIcon || '';
            if (bcEl) bcEl.innerHTML = session.snapshot.breadcrumbsHtml || '';
            grid.innerHTML = session.snapshot.gridHtml || '';

            this.currentFolder = this.normalizeFolderPath(session.snapshot.currentFolder || restoreFolder);
            this.updateSidebarActive(this.currentFolder);
            this.currentDepth = this.getCurrentDepthFromBreadcrumbs();
            this.applyFolderLocks();
            this.filterCards('');

            this.searchSession = null;
            return;
        }

        this.searchSession = null;
        return this.loadFolder(restoreFolder);
    }

    softRefresh() {
        const searchInput = document.getElementById('mv-search-input');
        const query = (this.lastSearchQuery || (searchInput ? searchInput.value.trim() : ''));
        if (this.isSearching && query) {
            return this.performGlobalSearch(query);
        }

        const folder = this.normalizeFolderPath(this.getCurrentFolderFromUrl() || this.currentFolder || this.getCurrentFolderFromSidebarActive());
        return this.loadFolder(folder);
    }

    updateSidebarActive(currentFolder) {
        const current = this.normalizeFolderPath(currentFolder);
        const items = document.querySelectorAll('.mv-nav-item');

        let best = null;
        let bestLen = -1;

        items.forEach((item) => {
            item.classList.remove('active');

            let navFolder = '';
            try {
                const url = new URL(item.getAttribute('href') || '', window.location.origin);
                navFolder = url.searchParams.get('folder') || '';
            } catch (e) {
                navFolder = '';
            }

            const normalizedNav = this.normalizeFolderPath(navFolder);
            if (!normalizedNav || !current) return;

            if (current.startsWith(normalizedNav) && normalizedNav.length > bestLen) {
                best = item;
                bestLen = normalizedNav.length;
            }
        });

        if (best) {
            best.classList.add('active');
        }
    }

    bindEvents() {
        // Track file downloads (logs only)
        document.addEventListener('click', (e) => {
            const btn = e.target.closest('.mv-download-btn');
            if (btn && btn.dataset.type === 'file') {
                console.log('[MediaVault] Descargando archivo:', btn.dataset.name);
            }
        });

        // View toggle buttons
        document.querySelectorAll('[data-view]').forEach(btn => {
            btn.addEventListener('click', () => {
                const view = btn.dataset.view;
                this.setView(view);
            });
        });

        // Search Input & Clear
        const searchInput = document.getElementById('mv-search-input');
        const clearBtn = document.getElementById('mv-search-clear');

        if (searchInput) {
            searchInput.addEventListener('input', (e) => {
                const val = e.target.value.trim();

                // Toggle Clear Button
                if (clearBtn) clearBtn.style.display = val.length > 0 ? 'block' : 'none';

                // Immediate Local Filter (Instant Feedback)
                this.filterCards(val);

                // Debounce Global Search
                if (this._searchTimer) {
                    clearTimeout(this._searchTimer);
                    this._searchTimer = null;
                }

                if (val.length === 0) {
                    if (this.isSearching) this.exitSearchMode();
                } else {
                    this._searchTimer = setTimeout(() => {
                        this.performGlobalSearch(val);
                    }, 500); // 500ms debounce
                }
            });

            // Allow clicking the magnifying glass to search immediately
            const searchIcon = searchInput.parentElement.querySelector('.mv-search-icon');
            if (searchIcon) {
                searchIcon.style.cursor = 'pointer';
                searchIcon.addEventListener('click', () => {
                    const val = searchInput.value.trim();
                    if (val) {
                        if (this._searchTimer) {
                            clearTimeout(this._searchTimer);
                            this._searchTimer = null;
                        }
                        this.performGlobalSearch(val);
                    }
                });
            }
            // Execute on Enter key
            searchInput.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') {
                    const val = searchInput.value.trim();
                    if (val) {
                        if (this._searchTimer) {
                            clearTimeout(this._searchTimer);
                            this._searchTimer = null;
                        }
                        this.performGlobalSearch(val);
                    }
                }
            });
        }

        if (clearBtn) {
            clearBtn.addEventListener('click', () => {
                if (searchInput) {
                    searchInput.value = '';
                    searchInput.focus();
                    clearBtn.style.display = 'none';
                    if (this.isSearching) {
                        this.exitSearchMode();
                    } else {
                        this.filterCards('');
                    }
                }
            });
        }

        // Filter Pills
        document.querySelectorAll('.mv-filter-pill').forEach(btn => {
            btn.addEventListener('click', (e) => {
                // UI Toggle
                document.querySelectorAll('.mv-filter-pill').forEach(b => b.classList.remove('active'));
                e.target.classList.add('active');

                // Logic Update
                this.currentFilter = e.target.dataset.filter; // all, audio, video

                // Re-run filter
                const currentSearch = searchInput ? searchInput.value.trim() : '';
                this.filterCards(currentSearch);
            });
        });

        const bpmFilter = document.getElementById('mv-bpm-filter');
        if (bpmFilter) {
            bpmFilter.addEventListener('change', (e) => {
                const nextVal = (e && e.target) ? String(e.target.value || 'all') : 'all';
                this.currentBpmFilter = nextVal || 'all';
                const currentSearch = searchInput ? searchInput.value.trim() : '';
                this.filterCards(currentSearch);
                if (this.isSearching && currentSearch) {
                    this.performGlobalSearch(currentSearch);
                }
            });
        }

        // Individual Folder Download
        document.addEventListener('click', (e) => {
            const btn = e.target.closest('.mv-folder-download-btn');
            if (btn) {
                e.preventDefault();
                e.stopPropagation();

                const card = btn.closest('.jpsm-mv-card');
                const folderPath = btn.dataset.folder || card?.dataset.path || '';
                const folderName = btn.dataset.name || card?.dataset.name || 'Carpeta';

                this.trackBehaviorEvent('download_folder_click', {
                    object_type: 'folder',
                    object_path_norm: folderPath,
                    status: 'click'
                });

                if (card && card.classList.contains('locked')) {
                    this.openUpgradeDialog(card.dataset.name, this.getAllowedTiers(card.dataset.path || ''));
                } else {
                    this.runDesktopRecommendationGate(() => this.downloadFolder(folderPath, folderName));
                }
            }
        });

        // File Download intercept
        document.addEventListener('click', (e) => {
            const btn = e.target.closest('.mv-download-btn');
            if (btn) {
                const card = btn.closest('.jpsm-mv-card');
                const path = btn.dataset.path || card?.dataset.path || '';
                const name = btn.dataset.name || card?.dataset.name || 'Archivo';

                e.preventDefault();
                e.stopPropagation();

                this.trackBehaviorEvent('download_file_click', {
                    object_type: 'file',
                    object_path_norm: path,
                    status: 'click'
                });

                if (card && card.classList.contains('locked')) {
                    this.openUpgradeDialog(name, this.getAllowedTiers(path || ''));
                    return;
                }

                // Demo users cannot download.
                if ((this.userData.tier ?? 0) <= 0) {
                    this.openUpgradeDialog(name, [1, 2, 3, 4, 5]);
                    return;
                }

                if (!path) {
                    this.showToast('Error: archivo inválido.', 'error');
                    return;
                }

                this.runDesktopRecommendationGate(() => this.downloadFile(path, name));
            }
        });

        // Preview Button
        document.addEventListener('click', (e) => {
            const btn = e.target.closest('.mv-preview-btn');
            if (btn) {
                e.preventDefault();
                e.stopPropagation();
                this.playPreview(btn.dataset.path, btn.dataset.name, btn.dataset.type);
            }
        });

        // Initial lock check
        this.applyFolderLocks();

        // Sticky Bar Button
        const stickyBtn = document.getElementById('mv-sticky-cta');
        if (stickyBtn) {
            stickyBtn.addEventListener('click', () => {
                this.openUpgradeDialog('Todo el Contenido', [1, 2, 3, 4, 5]);
            });
        }
    }

    applyFolderLocks() {
        if (!this.userData.tier && this.userData.tier !== 0) return; // Wait for data

        let hasLockedContent = false;
        const isDemo = this.userData.tier === 0;
        const userTier = parseInt(this.userData.tier);

        document.querySelectorAll('.jpsm-mv-card').forEach(card => {
            const path = card.dataset.path || '';
            const type = card.dataset.type;
            const allowedTiers = this.getAllowedTiers(path);

            // Access Check: Is the user's tier in the allowed list? (Also Full Tier 3 always has access? 
            // - The new backend logic 'user_can_access' handles Full Tier exception, but here we strictly check list.
            // - IMPORTANT: Access Manager returns [1,3,5] etc. backend code ensures 3 (Full) is usually in list or handled.
            // - BUT, let's replicate backend logic: If user is Full (3), always allow?
            // - Wait, '3' (Full) might not be in the list if the admin strictly selected strict tiers?
            // - Backend `user_can_access` explicitly says `if ($user_tier === self::TIER_FULL) return true;`.
            // - So we match that here. 3 is Tier Full.
            // - Actually, tiers are 1=Basic, 2=VIP(Legacy), 3=Full? 
            // - Wait, new tiers are 1..5. Access Manager `TIER_FULL = 5`.
            // - I need to be careful with constants.
            // - In frontend PHP injection: `tier: <?php echo intval($user_tier); ?>`
            // - In AccessManager: `const TIER_FULL = 5;`.
            // - So if userTier >= 5 (or == 5), they are God.
            // - For safety, I will check if `allowedTiers.includes(userTier)` OR `userTier >= 5`.

            const isAllowed = allowedTiers.includes(userTier) || userTier >= 5;

            if (!isAllowed) {
                card.classList.add('locked');
                hasLockedContent = true;

                // Select the button (Download for file, View for folder)
                let btn = card.querySelector('.mv-folder-download-btn, .mv-folder-view-btn');
                if (!btn) btn = card.querySelector('.mv-download-btn');

                if (btn) {
                    if (isDemo) {
                        // Demo User: Hide individual buttons, show sticky bar later
                        btn.style.display = 'none';
                    } else {
                        // Paid User (Upgrade needed): Keep button but change text
                        // For View Content (Folder), changing it to "Mejorar Plan" makes sense
                        btn.innerHTML = type === 'folder' ? 'Mejorar Plan 🚀' : 'Desbloquear 🔒';
                        btn.classList.add('mv-unlock-btn');

                        // If it became a link (folder), we might want to disable navigation or handle click
                        if (btn.tagName === 'A') {
                            btn.href = '#';
                            btn.style.pointerEvents = 'auto'; // Ensure click works
                        }
                    }
                }
            }
        });

        // Show Sticky Bar if Demo
        if (isDemo) {
            const stickyBar = document.getElementById('mv-sticky-unlock-bar');
            if (stickyBar) {
                // Small delay for animation
                setTimeout(() => stickyBar.classList.add('visible'), 500);
            }
        }
    }

    getAllowedTiers(path) {
        const perms = this.userData.folderPerms || {};

        // Normalize: remove leading/trailing slashes
        path = path.replace(/^\/+|\/+$/g, '');

        // 1. Try exact match with both formats
        if (perms[path] !== undefined) return this.normalizeTiers(perms[path]);
        if (perms[path + '/'] !== undefined) return this.normalizeTiers(perms[path + '/']);

        // 2. Try parent folder match
        let parts = path.split('/').filter(p => p);

        while (parts.length > 0) {
            parts.pop(); // Remove the last segment
            if (parts.length === 0) break;

            const parentPath = parts.join('/');
            // Check both formats: with and without trailing slash
            if (perms[parentPath] !== undefined) return this.normalizeTiers(perms[parentPath]);
            if (perms[parentPath + '/'] !== undefined) return this.normalizeTiers(perms[parentPath + '/']);
        }

        // Default: No explicit rule = visible to all paid tiers (visual only, backend enforces security)
        return [0, 1, 2, 3, 4, 5];
    }

    normalizeTiers(val) {
        if (Array.isArray(val)) return val.map(Number);
        // Backward compatibility for single integer (min tier)
        const min = parseInt(val);
        // If min is 2, return [2, 3, 4, 5] assuming hierarchy?
        // Let's assume hierarchy for legacy values.
        const all = [0, 1, 2, 3, 4, 5];
        return all.filter(t => t >= min);
    }

    openUpgradeDialog(targetName, allowedTiers) {
        const phone = (this.userData.whatsappNumber || '').toString().trim();
        const email = this.userData.email || '';

        // Normalize tiers if single value passed (fix for TypeError)
        const tiers = this.normalizeTiers(allowedTiers);

        let targetTierName = 'Full';
        if (tiers.includes(2)) targetTierName = 'VIP Básico';
        if (tiers.includes(3)) targetTierName = 'VIP Videos';
        if (tiers.includes(4)) targetTierName = 'VIP Pelis';
        if (tiers.includes(5)) targetTierName = 'Full';


        // Simplification for the message
        const message = `Hola, quiero mejorar mi plan a ${targetTierName} para acceder a "${targetName}". Mi correo registrado es ${email}`;
        if (phone) {
            window.open(`https://wa.me/${phone}?text=${encodeURIComponent(message)}`, '_blank');
            return;
        }

        alert('Este sitio no tiene configurado el número de WhatsApp para upgrades.');
    }

    getAjaxActionUrl(action) {
        const base = this.userData.ajax_url || window.MV_USER_DATA?.ajax_url || '/wp-admin/admin-ajax.php';
        try {
            const url = new URL(base, window.location.origin);
            url.searchParams.set('action', action);
            return url;
        } catch (e) {
            const url = new URL(window.location.href);
            url.searchParams.set('action', action);
            return url;
        }
    }

    async requestActionV2(action, fields = {}) {
        const url = this.getAjaxActionUrl(action);
        const formData = new FormData();
        formData.append('api_version', '2');
        if (this.userData.nonce) formData.append('nonce', this.userData.nonce);

        Object.entries(fields || {}).forEach(([k, v]) => {
            if (v === undefined || v === null) return;
            formData.append(k, v);
        });

        const res = await fetch(url.toString(), { method: 'POST', body: formData });
        let json;
        try {
            json = await res.json();
        } catch (e) {
            throw { ok: false, code: 'invalid_json', message: 'Respuesta inválida del servidor', details: {} };
        }

        // v2 envelope
        if (json && json.success && json.data && json.data.ok) {
            return json.data; // { ok, code, message, data }
        }
        if (json && json.success === false && json.data && json.data.ok === false) {
            throw json.data; // { ok:false, code, message, details }
        }

        // legacy fallback
        const legacyMsg = (json && json.data)
            ? (typeof json.data === 'string' ? json.data : (json.data.message || 'Error'))
            : 'Error';
        throw { ok: false, code: 'error', message: legacyMsg, details: {} };
    }

    async getPreviewUrl(path) {
        const resp = await this.requestActionV2('mv_get_preview_url', { path });
        const url = resp.data?.url || '';

        // Demo: server already decremented and returns remaining plays.
        const remaining = resp.data && typeof resp.data.remaining_plays === 'number' ? resp.data.remaining_plays : null;
        if (this.userData.tier === 0 && remaining !== null && remaining >= 0) {
            this.userData.remainingPlays = remaining;
            const counter = document.getElementById('mv-demo-usage');
            if (counter) {
                counter.innerText = `🔥 ${remaining}/15 Restantes`;
            }
        }

        return url;
    }

    async getDownloadUrl(path) {
        const resp = await this.requestActionV2('mv_get_presigned_url', { path });
        return resp.data?.url || '';
    }

    async downloadFile(path, name) {
        this.showToast('Preparando descarga...', 'info');
        try {
            const url = await this.getDownloadUrl(path);
            if (!url) {
                this.showToast('No se pudo generar el enlace de descarga.', 'error');
                return;
            }

            const a = document.createElement('a');
            a.href = url;
            a.rel = 'noopener';
            if (name) a.download = name;
            document.body.appendChild(a);
            a.click();
            a.remove();
        } catch (err) {
            const code = err?.code || 'error';
            const message = err?.message || 'Error al generar el enlace de descarga.';

            if (code === 'requires_premium' || code === 'access_denied') {
                this.openUpgradeDialog(name || 'Archivo', this.getAllowedTiers(path || ''));
                return;
            }

            this.showToast(message, 'error');
        }
    }

    async playPreview(path, name, type) {
        if (!path) {
            this.showToast('Error: vista previa inválida.', 'error');
            return;
        }

        try {
            const url = await this.getPreviewUrl(path);
            if (!url) return;
            this.showPlayer(url, name, type);
        } catch (err) {
            if (err?.code === 'limit_reached') {
                this.showLimitReachedModal();
                return;
            }
            this.showToast(err?.message || 'Error al cargar la vista previa.', 'error');
        }
    }

    showPlayer(url, name, type) {
        const isVideo = ['mp4', 'mov', 'mkv'].includes(type.toLowerCase());
        const isAdmin = mvFlagEnabled(this.userData && this.userData.isAdmin);
        const safeNameHtml = mvEscapeHtml(name);
        const safeNameAttr = mvEscapeAttr(name);
        const safeUrlAttr = mvEscapeAttr(url);
        let accumulatedTime = 0;
        let lastUpdate = Date.now();
        let limitReached = false;
        let playInterval = null;

        // Remove existing if any
        const existing = document.getElementById('mv-player-modal');
        if (existing) {
            existing.remove();
        }

        const modal = document.createElement('div');
        modal.id = 'mv-player-modal';
        modal.style = `
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.85); z-index: 10000;
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            backdrop-filter: blur(5px);
            padding: 10px;
            box-sizing: border-box;
        `;

        // Inner content wrapper
        const wrapper = document.createElement('div');
        wrapper.style = `
            background: #ffffff; 
            border: 1px solid #e2e8f0; 
            padding: 20px; 
            border-radius: 16px; 
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.2);
            max-width: 900px;
            width: 100%;
            display: flex;
            flex-direction: column;
            gap: 16px;
            position: relative;
            color: #0f172a;
            box-sizing: border-box;
            overflow: hidden;
        `;

        // --- Functions ---
        const closePlayer = () => {
            if (playInterval) clearInterval(playInterval);
            modal.style.opacity = '0';
            setTimeout(() => modal.remove(), 200);
            document.removeEventListener('keydown', escHandler);
        };

        const escHandler = (e) => {
            if (e.key === 'Escape') closePlayer();
        };

        // Header
        const header = `
            <div style="display:flex; justify-content:space-between; align-items:start; margin-bottom:8px;">
                <h3 style="color:#0f172a; margin:0; font-size:1.1rem; line-height:1.4; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:70%;" title="${safeNameAttr}">${safeNameHtml}</h3>
                ${this.userData.tier === 0 ? `
                    <div style="background:#fff7ed; color:#c2410c; border:1px solid #fdba74; padding:4px 10px; border-radius:12px; font-size:12px; font-weight:700; white-space:nowrap; margin-left:12px; display: flex; align-items: center; gap: 4px;">
                       🔥 ${this.userData.remainingPlays} Restantes
                    </div>
                ` : ''}
            </div>
        `;

        // Media content
        let mediaHtml = '';
        if (isVideo) {
            mediaHtml = `<div style="display:flex; justify-content:center; background:black; border-radius:8px; overflow:hidden;"><video src="${safeUrlAttr}" controls autoplay style="width: 100%; max-height: 60vh;"></video></div>`;
        } else {
            mediaHtml = `
                <div style="background:#fff7ed; border:1px solid #fed7aa; padding:30px; border-radius:8px; text-align:center;">
                     <div style="font-size:48px; margin-bottom:20px;">🎵</div>
                     <audio src="${safeUrlAttr}" controls autoplay style="width:100%;"></audio>
                </div>
            `;
        }

        // Close Button (Below controls)
        const closeBtnHtml = `
            <button id="mv-close-player" style="
                width: 100%;
                padding: 12px;
                background: #ffffff;
                color: #0f172a;
                border: 1px solid #e2e8f0;
                border-radius: 8px;
                font-weight: 600;
                cursor: pointer;
                transition: background 0.2s, color 0.2s;
                margin-top: 4px;
            ">Cerrar Reproductor</button>
        `;

        wrapper.innerHTML = header + mediaHtml + closeBtnHtml;
        modal.appendChild(wrapper);
        document.body.appendChild(modal);

        // --- Playback Limit Tracking Logic ---
        const media = wrapper.querySelector('video, audio');
        // Global Restriction: Only 60s allowed per playback session (All Tiers)
        if (media) {
            const checkLimit = () => {
                // Enforce limit if already reached (Prevent user from resuming)
                if (limitReached) {
                    if (!media.paused) media.pause();
                    return;
                }

                const now = Date.now();
                if (!media.paused && !media.ended) {
                    accumulatedTime += (now - lastUpdate) / 1000;
                }
                lastUpdate = now;

                // Trigger limit
                if (accumulatedTime >= 60) {
                    limitReached = true;
                    media.pause();
                    if (playInterval) clearInterval(playInterval);

                    // Add permanent block listener
                    media.addEventListener('play', (e) => {
                        e.preventDefault();
                        media.pause();
                    });

                    if (!wrapper.querySelector('.mv-playback-restricted-overlay')) {
                        // Create overlay to cover THE ENTIRE WRAPPER
                        const restrictedMsg = document.createElement('div');
                        restrictedMsg.className = 'mv-playback-restricted-overlay';
                        restrictedMsg.innerHTML = `
                        <div class="mv-restricted-content">
                            <div style="font-size: 50px; margin-bottom: 20px;">⏳</div>
                            <h4>Límite de Vista Previa Alcanzado</h4>
	                            <p>
	                                Has alcanzado el límite total de 1 minuto de vista previa permitida.<br>
	                                MediaVault es un sistema de descargas. Para ver el contenido completo, por favor descárgalo.
	                            </p>
                            <div style="display: flex; flex-direction: column; gap: 12px; align-items: center;">
                                <button class="mv-close-restricted-btn" style="width: 200px;">Cerrar Reproductor</button>
                            </div>
                        </div>
                    `;
                        wrapper.appendChild(restrictedMsg);
                        restrictedMsg.querySelector('.mv-close-restricted-btn').onclick = () => closePlayer();
                    }
                }
            };

            media.addEventListener('play', () => { lastUpdate = Date.now(); });
            media.addEventListener('timeupdate', checkLimit);
            playInterval = setInterval(checkLimit, 500);
        }

        // Focus close button for accessibility
        setTimeout(() => document.getElementById('mv-close-player').focus(), 100);

        // Events
        document.getElementById('mv-close-player').onclick = closePlayer;
        document.getElementById('mv-close-player').onmouseover = function () {
            this.style.background = '#fff7ed';
            this.style.color = '#ea580c';
            this.style.borderColor = '#fb923c';
        };
        document.getElementById('mv-close-player').onmouseout = function () {
            this.style.background = '#ffffff';
            this.style.color = '#0f172a';
            this.style.borderColor = '#e2e8f0';
        };

        modal.onclick = (e) => {
            if (e.target === modal) closePlayer();
        };

        // Add minimal animation
        modal.animate([
            { opacity: 0 },
            { opacity: 1 }
        ], { duration: 200 });

        wrapper.animate([
            { transform: 'scale(0.95)', opacity: 0 },
            { transform: 'scale(1)', opacity: 1 }
        ], { duration: 250, easing: 'cubic-bezier(0.16, 1, 0.3, 1)' });

        document.addEventListener('keydown', escHandler);
    }

    showLimitReachedModal() {
        const modal = document.createElement('div');
        modal.id = 'mv-limit-modal';
        modal.style = `
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.85); z-index: 10000;
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            backdrop-filter: blur(5px);
            padding: 10px;
            box-sizing: border-box;
        `;

        const wrapper = document.createElement('div');
        wrapper.style = `
            background: #ffffff; 
            border: 1px solid #e2e8f0; 
            padding: 30px; 
            border-radius: 16px; 
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.2);
            max-width: 500px;
            width: 100%;
            display: flex;
            flex-direction: column;
            gap: 20px;
            position: relative;
            color: #0f172a;
            box-sizing: border-box;
            text-align: center;
        `;

        const content = `
            <div style="font-size: 60px; margin-bottom: 0px;">🚫</div>
            <h3 style="margin:0; font-size: 1.5rem; color: #0f172a;">Límite de Pruebas Alcanzado</h3>
            <p style="margin:0; font-size: 1rem; color: #475569; line-height: 1.6;">
                Has consumido tus <strong>15 reproducciones de cortesía</strong>.<br>
                El acceso a vistas previas y descargas está limitado para cuentas de demostración.
            </p>
            <p style="margin:0; font-size: 0.95rem; color: #64748b;">
                Actualiza tu cuenta para disfrutar de acceso ilimitado a todo el contenido de la bóveda.
            </p>
            
            <div style="display: flex; flex-direction: column; gap: 12px; margin-top: 10px;">
                <button id="mv-unlock-limit-btn" style="
                    width: 100%;
                    padding: 14px;
                    background: #2563eb;
                    color: white;
                    border: none;
                    border-radius: 8px;
                    font-weight: 600;
                    font-size: 1rem;
                    cursor: pointer;
                    display: flex; align-items: center; justify-content: center; gap: 8px;
                    box-shadow: 0 4px 6px -1px rgba(37, 99, 235, 0.2);
                ">
                    <span>🔓</span> Desbloquear Acceso Ahora
                </button>
                <button id="mv-close-limit-btn" style="
                    width: 100%;
                    padding: 12px;
                    background: white;
                    color: #64748b;
                    border: 1px solid #e2e8f0;
                    border-radius: 8px;
                    font-weight: 500;
                    cursor: pointer;
                ">
                    Cerrar
                </button>
            </div>
        `;

        wrapper.innerHTML = content;
        modal.appendChild(wrapper);
        document.body.appendChild(modal);

        // Handlers
        const close = () => modal.remove();

        document.getElementById('mv-close-limit-btn').onclick = close;
        modal.onclick = (e) => { if (e.target === modal) close(); };

        document.getElementById('mv-unlock-limit-btn').onclick = () => {
            close();
            this.openUpgradeDialog('Acceso Ilimitado', 1);
        };
    }

    // --- Folder Download (integrates with DownloadManager) ---

    async downloadFolder(path, name) {
        // Check for File System Access API support
        const supportsDirectoryPicker = typeof window.showDirectoryPicker === 'function';

        if (!supportsDirectoryPicker) {
            alert('⚠️ Tu navegador no soporta la descarga organizada de carpetas.\n\nPor favor, usa Google Chrome o Microsoft Edge.\n\nEn Safari y Firefox esta función no está disponible.');
            return;
        }

        this.showGuidanceModal(path, name);
    }

    showGuidanceModal(path, name) {
        const modal = document.getElementById('mv-guidance-modal');
        const confirmBtn = document.getElementById('mv-guidance-confirm');
        const cancelBtn = document.getElementById('mv-guidance-cancel');

        if (!modal || !confirmBtn || !cancelBtn) {
            console.error('[MediaVault] Guidance modal elements not found.');
            return;
        }

        confirmBtn.onclick = async () => {
            modal.style.display = 'none';
            try {
                this.showToast('Selecciona la carpeta nueva que creaste...', 'info');
                const rootHandle = await window.showDirectoryPicker({ mode: 'readwrite' });
                this.executeFolderDownload(path, name, rootHandle);
            } catch (err) {
                if (err.name === 'AbortError') {
                    this.showToast('Selección cancelada.', 'info');
                    return;
                }
                console.error('[MediaVault] Picker error:', err);
                alert(`Error al seleccionar carpeta: ${err.message}`);
            }
        };

        cancelBtn.onclick = () => {
            modal.style.display = 'none';
        };

        modal.style.display = 'flex';
    }

    async executeFolderDownload(path, name, rootHandle) {
        // Fetch file list
        this.showToast(`Obteniendo lista de archivos...`, 'info');

        try {
            const url = new URL(window.location.href);
            url.searchParams.set('action', 'mv_list_folder');
            url.searchParams.set('folder', path);
            if (this.userData.nonce) url.searchParams.set('nonce', this.userData.nonce);

            const res = await fetch(url);
            const json = await res.json();

            if (!json.success) {
                this.showToast('Error: No se pudo obtener la lista de archivos.', 'error');
                return;
            }

            const files = json.data;
            if (files.length === 0) {
                this.showToast('La carpeta parece estar vacía.', 'info');
                return;
            }

            const bytesAuthorized = files.reduce((sum, f) => {
                const size = Number(f && f.size);
                return sum + (Number.isFinite(size) && size > 0 ? size : 0);
            }, 0);

            // Add to Download Manager queue
            window.DownloadManager.addDownload(name, files, rootHandle, {
                objectPathNorm: path,
                bytesAuthorized: bytesAuthorized,
                onComplete: (summary) => {
                    this.trackBehaviorEvent('download_folder_completed', {
                        object_type: 'folder',
                        object_path_norm: summary.objectPathNorm || path,
                        status: summary.errorsCount > 0 ? 'ok' : 'success',
                        files_count: Number(summary.totalFiles || files.length || 0),
                        bytes_authorized: Number(summary.bytesAuthorized || bytesAuthorized || 0),
                        bytes_observed: Number(summary.bytesObserved || 0)
                    });
                }
            });
            this.showToast(`"${name}" añadida a la cola de descargas.`, 'success');

        } catch (err) {
            console.error('[MediaVault] Folder list error:', err);
            this.showToast('Error crítico de conexión.', 'error');
        }
    }


    // --- Feedback System ---

    showToast(message, type = 'info') {
        const container = document.getElementById('mv-toast-container');
        if (!container) return;

        const toast = document.createElement('div');
        toast.className = `mv-toast ${type}`;
        toast.innerText = message;

        container.appendChild(toast);

        setTimeout(() => {
            toast.style.opacity = '0';
            toast.style.transform = 'translateX(20px)';
            toast.style.transition = 'all 0.4s ease';
            setTimeout(() => toast.remove(), 400);
        }, 4000);
    }

    // --- View & Search ---

    setView(view) {
        const grid = document.querySelector('.jpsm-mv-grid');
        if (!grid) return;

        grid.classList.remove('view-grid', 'view-list');
        grid.classList.add(`view-${view}`);

        document.querySelectorAll('[data-view]').forEach(btn => {
            btn.classList.toggle('active', btn.dataset.view === view);
        });

        localStorage.setItem('mv_view', view);
    }

    restoreView() {
        const saved = localStorage.getItem('mv_view') || 'list';
        this.setView(saved);
    }

    filterCards(query) {
        // 1. Prepare Smart Search Tokens
        const rawTerm = query.toLowerCase().trim();
        const tokens = rawTerm.split(/\s+/).filter(t => t.length > 0); // "pop madonna" -> ["pop", "madonna"]
        const bpmRange = this.getBpmRangeFromFilter();
        const bpmFilterActive = !!bpmRange;

        document.querySelectorAll('.jpsm-mv-card').forEach(card => {
            const name = (card.dataset.name || '').toLowerCase();
            const typeRaw = (card.dataset.type || '').toLowerCase(); // file, folder

            // 2. Type Filter Check
            let typeMatch = true;
            if (this.currentFilter === 'audio') {
                const isAudio = this.isAudioFile(name);
                if (!isAudio) typeMatch = false;
            } else if (this.currentFilter === 'video') {
                const isVideo = this.isVideoFile(name);
                if (!isVideo) typeMatch = false;
            }
            // "folder" items are usually kept visible unless searching specific files? 
            // User requested: "All, Audio, Video". Folders should probably be hidden if filtering by Media Type?
            // Let's decide: If filter is Audio/Video, hide folders to let user see flat list of media?
            // Or keep folders? User said "Search by genre/artist". Usually implies flat search.
            // Let's HIDE folders if a specific media filter is active OR if searching.

            // Refined Logic:
            // If Text Search is Active -> Hide Folders (unless folder name matches?) -> Usually Flatten Search is better for "finding artist".
            // If Filter is Active (Audio/Video) -> Hide Folders.

            // UX rule: In home + shallow levels, keep folders visible even if the user has a media filter selected,
            // otherwise the "Inicio" screen can look empty and feel broken on mobile.
            const allowFoldersInShallowLevels = (typeof this.currentDepth === 'number') && this.currentDepth < 2;
            if ((this.currentFilter !== 'all' || bpmFilterActive) && typeRaw === 'folder' && !allowFoldersInShallowLevels) {
                typeMatch = false;
            }

            if (bpmFilterActive && typeRaw === 'file') {
                const isAudio = this.isAudioFile(name);
                if (!isAudio) {
                    typeMatch = false;
                } else {
                    const bpm = parseInt(card.dataset.bpm || '0', 10);
                    if (!Number.isFinite(bpm) || bpm <= 0 || bpm < bpmRange.min || bpm > bpmRange.max) {
                        typeMatch = false;
                    }
                }
            }

            // 3. Smart Search Check (Token based)
            let searchMatch = true;
            if (tokens.length > 0) {
                // all tokens must be present in the name
                const allTokensFound = tokens.every(token => name.includes(token));
                if (!allTokensFound) searchMatch = false;
            }

            // 4. Combine
            card.style.display = (typeMatch && searchMatch) ? '' : 'none';
        });
    }

    async performGlobalSearch(query) {
        // Capture pre-search state only once, so clearing the search can restore without a full reload.
        this.beginSearchSessionIfNeeded();
        this.isSearching = true;
        this.lastSearchQuery = query;

        const requestId = ++this._searchRequestSeq;
        if (this._searchFetchController && typeof this._searchFetchController.abort === 'function') {
            this._searchFetchController.abort();
        }
        this._searchFetchController = (typeof AbortController !== 'undefined') ? new AbortController() : null;

        // Show loading indicator
        const grid = document.getElementById('mv-grid');
        if (grid) grid.style.opacity = '0.5';
        this._showLoading('Buscando...', `Explorando el catalogo: "${query}"`);

        try {
            // Prepare URL
            let fetchUrl;
            if (window.mv_params && window.mv_params.ajaxurl) {
                fetchUrl = new URL(window.mv_params.ajaxurl);
            } else if (window.MV_USER_DATA && window.MV_USER_DATA.ajax_url) {
                fetchUrl = new URL(window.MV_USER_DATA.ajax_url, window.location.origin);
            } else {
                fetchUrl = new URL(window.location.href);
            }

            fetchUrl.searchParams.set('action', 'mv_search_global');
            fetchUrl.searchParams.set('query', query);
            fetchUrl.searchParams.set('api_version', '2');
            fetchUrl.searchParams.set('limit', '100');
            fetchUrl.searchParams.set('offset', '0');
            fetchUrl.searchParams.set('type', this.currentFilter || 'all');
            const bpmRange = this.getBpmRangeFromFilter();
            if (bpmRange) {
                fetchUrl.searchParams.set('bpm_min', String(bpmRange.min));
                fetchUrl.searchParams.set('bpm_max', String(bpmRange.max));
            }
            if (this.userData.nonce) fetchUrl.searchParams.set('nonce', this.userData.nonce);

            const res = await fetch(fetchUrl, this._searchFetchController ? { signal: this._searchFetchController.signal } : undefined);
            if (requestId !== this._searchRequestSeq) return; // stale response
            const data = await res.json();

            let payload = null;
            if (data && data.success && data.data && Array.isArray(data.data.items)) {
                payload = data.data; // legacy success/data payload
            } else if (data && data.success && data.data && data.data.data && Array.isArray(data.data.data.items)) {
                payload = data.data.data; // API v2 envelope inside success/data
            } else if (data && data.success && Array.isArray(data.data)) {
                payload = { items: data.data, meta: null, suggestions: [] }; // old array-only shape
            } else {
                payload = { items: [], meta: null, suggestions: [] };
            }
            const resultItems = Array.isArray(payload.items) ? payload.items : [];
            this.lastSearchMeta = payload.meta || null;
            this.notifyIndexState(this.lastSearchMeta);

            if (data.success) {
                if (grid) {
                    grid.innerHTML = '';
                    grid.style.opacity = '1';

                    // Enforce List View for Search Results
                    grid.classList.add('force-list-view');

                    if (resultItems.length === 0) {
                        let suggestionHtml = '';
                        if (Array.isArray(payload.suggestions) && payload.suggestions.length > 0) {
                            const sug = payload.suggestions.slice(0, 3).map(s => `"${mvEscapeHtml(s)}"`).join(', ');
                            suggestionHtml = `<div style="margin-top:8px; font-size:12px; color:#94a3b8;">Prueba con: ${sug}</div>`;
                        }
                        grid.innerHTML = '<div style="grid-column: 1/-1; text-align:center; padding: 40px; color: #6b7280;">No se encontraron resultados para "' + mvEscapeHtml(query) + '"</div>';
                        if (suggestionHtml) {
                            grid.insertAdjacentHTML('beforeend', `<div style="grid-column: 1/-1; text-align:center;">${suggestionHtml}</div>`);
                        }
                        return;
                    }

                    // --- grouping Logic ---
                    const foldersMap = new Map(); // path -> {name, path}
                    const filesList = [];
                    const userTier = parseInt(this.userData.tier); // Define widely for both loops

                    resultItems.forEach(file => {
                        // 1. Filter Check (Audio/Video)
                        let typeMatch = true;
                        if (this.currentFilter === 'audio' && !this.isAudioFile(file.name)) typeMatch = false;
                        if (this.currentFilter === 'video' && !this.isVideoFile(file.name)) typeMatch = false;
                        if (bpmRange) {
                            if (!this.isAudioFile(file.name)) {
                                typeMatch = false;
                            } else {
                                const bpm = parseInt(file.bpm || 0, 10);
                                if (!Number.isFinite(bpm) || bpm <= 0 || bpm < bpmRange.min || bpm > bpmRange.max) {
                                    typeMatch = false;
                                }
                            }
                        }
                        if (!typeMatch) return;

                        // 2. Add File to list
                        filesList.push(file);

                        // 3. Extract Folder (Max Depth 3)
                        // Path format: "Genre/Artist/Album/Song.mp3"
                        const parts = file.path.split('/');
                        parts.pop(); // Remove filename

                        // Determine folder depth to show
                        // We want "Root/Genre/Artist" max. 
                        // If depth is > 3, we truncate to 3.
                        // Actually user wants: "Maximo nivel posible, pero sin que este sea más abajo que el nivel 3"
                        // So if we have A/B/C/D/file.mp3, we show folder A/B/C.

                        if (parts.length > 0) {
                            let folderPathParts = parts;
                            if (folderPathParts.length > 3) {
                                folderPathParts = folderPathParts.slice(0, 3);
                            }

                            const folderPath = folderPathParts.join('/');
                            const folderName = folderPathParts[folderPathParts.length - 1]; // Visual name

                            if (!foldersMap.has(folderPath)) {
                                foldersMap.set(folderPath, {
                                    name: folderName,
                                    path: folderPath + '/', // Ensure trailing slash for download
                                    displayPath: folderPath
                                });
                            }
                        }
                    });

                    // --- RENDER SECTIONS ---

                    // 1. FOLDERS SECTION
                    if (foldersMap.size > 0) {
                        grid.insertAdjacentHTML('beforeend', `
                            <div style="grid-column: 1/-1; margin-top: 20px; margin-bottom: 10px; border-bottom: 1px solid #e2e8f0; padding-bottom: 8px;">
                                <h3 style="font-size: 1.1rem; color: #0f172a; margin: 0; display:flex; align-items:center; gap:8px;">
                                    📁 Carpetas <span style="font-size:0.8em; color:#64748b; font-weight:normal;">(${foldersMap.size})</span>
                                </h3>
                            </div>
                         `);

                        foldersMap.forEach(folder => {
                            // Folder Card
                            // We use mv-folder-download-btn for "Ver Contenido" behavior modification
                            // logic in click handler: if folder, open it.

                            // BUT user wants "Download Folder" button AND "View Content" (nav).
                            // In search results, "Descargar" should download the folder being shown.
                            // "Ver contenido" should navigate to it? 
                            // Wait, user request: "esto facilitará al usuario descargar la carpeta completa primero... pero, la sección de archivos sueltos deja abierta la posibilidad de descargar un archivo específico"
                            // So for folders, we need a DOWNLOAD button.

                            const safeFolderName = mvEscapeHtml(folder.name);
                            const safeFolderNameAttr = mvEscapeAttr(folder.name);
                            const safeFolderPath = mvEscapeAttr(folder.path);
                            const safeFolderDisplay = mvEscapeHtml(folder.displayPath);

                            const html = `
                                <div class="jpsm-mv-card mv-item-folder ${userTier > 0 ? '' : 'locked'}" data-name="${safeFolderNameAttr}" data-type="folder" data-path="${safeFolderPath}">
                                    <div class="jpsm-mv-cover" style="display:none !important;"></div>
                                    
                                    <!-- Clickable Title Area -->
                                    <a href="?folder=${encodeURIComponent(folder.path)}" style="flex:1; display:flex; align-items:center; gap: 12px; text-decoration:none; color:inherit;">
                                        <div style="font-size:24px;">📁</div>
                                        <div>
                                            <div class="jpsm-mv-title" style="margin:0;">${safeFolderName}</div>
                                            <div class="jpsm-mv-meta" style="font-size:0.8em;">${safeFolderDisplay}</div>
                                        </div>
                                    </a>

                                    <div style="display:flex; gap:8px; margin-left:auto; align-items:center;">
                                        <a href="?folder=${encodeURIComponent(folder.path)}" 
                                            style="border:1px solid #e2e8f0; background:white; color:#0f172a; padding:6px 12px; border-radius:6px; text-decoration:none; font-size:12px; font-weight:600; display:flex; align-items:center;">
                                            Ver Contenido
                                        </a>
                                        ${(userTier > 0) ? `
                                        <button class="mv-folder-download-btn" data-folder="${safeFolderPath}" data-name="${safeFolderNameAttr}" 
                                            style="background:#ea580c; color:white; border:none; padding:6px 12px; border-radius:6px; cursor:pointer; font-size:12px;">
                                            Descargar
                                        </button>
                                        ` : `
                                        <button type="button" class="mv-folder-download-btn locked" 
                                            style="background:#3f3f46; cursor:not-allowed; opacity:0.7; border:none; color:white; padding:6px 12px; border-radius:6px; font-size:12px;"
                                            title="Descarga disponible solo en plan Premium">
                                            🔒 Descargar
                                        </button>
                                        `}
                                    </div>
                                </div>
                             `;
                            grid.insertAdjacentHTML('beforeend', html);
                        });
                    }

                    // 2. FILES SECTION
                    if (filesList.length > 0) {
                        grid.insertAdjacentHTML('beforeend', `
                            <div style="grid-column: 1/-1; margin-top: 30px; margin-bottom: 10px; border-bottom: 1px solid #e2e8f0; padding-bottom: 8px;">
                                <h3 style="font-size: 1.1rem; color: #0f172a; margin: 0; display:flex; align-items:center; gap:8px;">
                                    🎵 Archivos <span style="font-size:0.8em; color:#64748b; font-weight:normal;">(${filesList.length})</span>
                                </h3>
                            </div>
                        `);

                        filesList.forEach(file => {
                            const ext = file.name.split('.').pop();

                            // Determine Icon
                            let icon = '📄';
                            if (this.isAudioFile(file.name)) icon = '🎵';
                            else if (this.isVideoFile(file.name)) icon = '🎬';

                            const allowedTiers = this.getAllowedTiers(file.path);
                            // userTier already defined above
                            const isAllowed = allowedTiers.includes(userTier) || userTier >= 5;
                            const lockedClass = isAllowed ? '' : 'locked';

                            const safeName = mvEscapeAttr(file.name);
                            const safePath = mvEscapeAttr(file.path);
                            const bpm = parseInt(file.bpm || 0, 10);
                            const bpmMeta = Number.isFinite(bpm) && bpm > 0 ? ` • ${bpm} BPM` : '';
                            const html = `
                            <div class="jpsm-mv-card ${lockedClass}" data-name="${safeName}" data-bpm="${Number.isFinite(bpm) ? bpm : 0}" data-type="file" data-path="${safePath}">
                                <div class="jpsm-mv-cover" style="display:none !important;"></div>
                                <div style="flex:1; display:flex; align-items:center; gap: 12px; min-width: 0;">
                                    <div style="font-size:20px;">${icon}</div>
                                    <div style="min-width: 0;">
                                        <div class="jpsm-mv-title" style="white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                                            ${lockedClass ? '🔒 ' : ''}${mvEscapeHtml(file.name)}
                                        </div>
                                        <!-- Reduced metadata text -->
                                        <div class="jpsm-mv-meta" style="font-size:0.75em;">${(file.size / 1024 / 1024).toFixed(1)} MB${bpmMeta}</div>
                                    </div>
                                </div>
                                <div style="display:flex; gap:8px; margin-left:auto; flex-shrink: 0;">
                                    ${(icon === '🎵' || icon === '🎬') ? `
                                    <button type="button" class="mv-preview-btn" data-path="${safePath}" 
                                        data-name="${safeName}" data-type="${mvEscapeAttr(ext)}"
                                        style="border:1px solid #ea580c; background:white; color:#ea580c; padding:4px 10px; border-radius:6px; cursor:pointer; font-size: 12px; font-weight: 600;">Reproducir</button>
                                    ` : ''}
                                    ${(userTier > 0) ? `
                                    <a href="#" class="mv-download-btn" data-path="${safePath}" data-name="${safeName}"
                                       style="background:#f1f5f9; color:#0f172a; padding:4px 10px; text-decoration:none; border-radius:6px; font-size: 12px; display:inline-flex; align-items:center;">
                                       Descargar
                                    </a>
                                    ` : `
                                    <button type="button" class="mv-download-btn locked" 
                                        style="background:#3f3f46; cursor:not-allowed; opacity:0.7; border:none; color:white; padding:4px 10px; border-radius:6px; font-size:12px;"
                                        title="Descarga disponible solo en plan Premium">
                                        🔒 Descargar
                                    </button>
                                    `}
                                </div>
                            </div>
                            `;
                            grid.insertAdjacentHTML('beforeend', html);
                        });
                    }

                    // Ensure locks/upgrade CTAs are applied to search-rendered cards too.
                    // Visibility is global; only downloads are gated.
                    this.applyFolderLocks();

                }
            } else {
                console.error('Search error:', data);
                const errMsg = (data && data.data && typeof data.data === 'string')
                    ? data.data
                    : ((data && data.data && data.data.message) ? data.data.message : 'Error');
                if (grid) grid.innerHTML = '<div style="grid-column: 1/-1; text-align:center; color:red;">Error: ' + mvEscapeHtml(errMsg) + '</div>';
            }
        } catch (err) {
            if (err && err.name === 'AbortError') return;
            console.error('[MediaVault] Global Search Error:', err);
            const grid = document.getElementById('mv-grid');
            if (grid) {
                grid.style.opacity = '1';
                grid.innerHTML = '<div style="grid-column: 1/-1; text-align:center; padding: 40px; color: #ef4444;">Error al buscar: ' + mvEscapeHtml(err && err.message ? err.message : 'Error') + '</div>';
            }
        } finally {
            if (requestId === this._searchRequestSeq) {
                this._hideLoading();
            }
        }
    }

    // --- AJAX NAVIGATION METHODS ---

    _newNavId() {
        return 'mvnav_' + Date.now().toString(36) + '_' + Math.random().toString(36).slice(2, 10);
    }

    _navCanGoHomeFromRoot() {
        if (this._navIndex !== 0) return false;
        const current = this.normalizeFolderPath(this.getCurrentFolderFromUrl() || this.currentFolder || '');
        return !!current; // Deep-linked into a folder; allow back to go to Home instead of leaving MediaVault.
    }

    _updateNavButtons() {
        const canBack = (this._navIndex > 0) || this._navCanGoHomeFromRoot();
        if (this._navBackBtn) this._navBackBtn.disabled = !canBack;
        if (this._navForwardBtn) this._navForwardBtn.disabled = !(this._navIndex >= 0 && this._navIndex < this._navStack.length - 1);
    }

    goHome() {
        // Navigate to the MediaVault "Inicio" (junction view) without reloading the page.
        // This is critical for keeping in-progress downloads alive.
        const current = this.normalizeFolderPath(this.getCurrentFolderFromUrl() || this.currentFolder || '');

        // Ensure Home reflects the latest admin ordering/content (do not serve from stale in-memory cache).
        if (this._folderCache && typeof this._folderCache.delete === 'function') {
            this._folderCache.delete('');
        }

        if (!current && !this.isSearching) {
            // Already home; do a soft refresh in case the index changed.
            this.softRefresh();
            return;
        }

        this.loadFolder('');

        const url = new URL(window.location.href);
        url.searchParams.delete('folder');
        url.searchParams.delete('mv_ajax');
        url.searchParams.delete('nonce');
        const newSearch = url.searchParams.toString();
        const newPath = url.pathname + (newSearch ? `?${newSearch}` : '');

        const st = { mv: true, folder: '', navId: this._newNavId() };
        history.pushState(st, '', newPath);
        this._navPush(st);
    }

    _navInit() {
        // Wire up UI controls (if present) and initialize an MV-only history baseline.
        this._navBackBtn = document.getElementById('mv-nav-back');
        this._navForwardBtn = document.getElementById('mv-nav-forward');
        this._navHomeBtn = document.getElementById('mv-nav-home');

        if (this._navBackBtn) {
            this._navBackBtn.addEventListener('click', (e) => {
                e.preventDefault();
                if (this._navIndex > 0) {
                    history.back();
                    return;
                }
                if (this._navCanGoHomeFromRoot()) {
                    this.goHome();
                }
            });
        }
        if (this._navForwardBtn) {
            this._navForwardBtn.addEventListener('click', (e) => {
                e.preventDefault();
                if (this._navIndex >= 0 && this._navIndex < this._navStack.length - 1) history.forward();
            });
        }
        if (this._navHomeBtn) {
            this._navHomeBtn.addEventListener('click', (e) => {
                e.preventDefault();
                this.goHome();
            });
        }

        const initialFolder = this.normalizeFolderPath(this.getCurrentFolderFromUrl() || this.currentFolder || '');

        let st = history.state;
        if (!st || typeof st !== 'object' || !st.mv || typeof st.navId !== 'string') {
            st = { mv: true, folder: initialFolder, navId: this._newNavId() };
            history.replaceState(st, '', window.location.pathname + window.location.search);
        } else {
            // Normalize folder for consistent popstate handling.
            st.folder = this.normalizeFolderPath(typeof st.folder === 'string' ? st.folder : initialFolder);
            history.replaceState(st, '', window.location.pathname + window.location.search);
        }

        this._navStack = [{ navId: st.navId, folder: st.folder }];
        this._navIndex = 0;
        this._updateNavButtons();
    }

    _navPush(st) {
        if (!st || typeof st.navId !== 'string') return;
        const entry = { navId: st.navId, folder: this.normalizeFolderPath(st.folder || '') };
        if (this._navIndex >= 0 && this._navIndex < this._navStack.length - 1) {
            this._navStack = this._navStack.slice(0, this._navIndex + 1);
        }
        this._navStack.push(entry);
        this._navIndex = this._navStack.length - 1;
        this._updateNavButtons();
    }

    _navReplace(st) {
        if (!st || typeof st.navId !== 'string') return;
        const idx = this._navStack.findIndex((e) => e.navId === st.navId);
        if (idx !== -1) {
            this._navStack[idx].folder = this.normalizeFolderPath(st.folder || '');
            this._navIndex = idx;
        } else if (this._navIndex >= 0 && this._navIndex < this._navStack.length) {
            // Best-effort update of current entry if navId mismatch (should be rare).
            this._navStack[this._navIndex].folder = this.normalizeFolderPath(st.folder || '');
        }
        this._updateNavButtons();
    }

    _navSyncFromPop(state, folderFallback = '') {
        if (state && typeof state === 'object' && state.mv && typeof state.navId === 'string') {
            const idx = this._navStack.findIndex((e) => e.navId === state.navId);
            if (idx !== -1) {
                this._navIndex = idx;
            }
        }
        this._updateNavButtons();
    }

    initAjaxNavigation() {
        this._navInit();

        // Intercept all clicks on folder links (Broad selector to catch breadcrumbs/search)
        document.body.addEventListener('click', (e) => {
            const link = e.target.closest('a');
            if (!link || !link.href) return;

            // 1. Skip external links
            let url;
            try {
                url = new URL(link.href, window.location.origin);
            } catch (err) { return; }

            if (url.origin !== window.location.origin) return;

            // 2. Check if it's a folder navigation link
            const params = new URLSearchParams(url.search);
            const isLogout = params.get('action') === 'mv_logout';
            const hasFolder = params.has('folder');

            // We intercept if it has 'folder' parameter OR if it's a root navigation.
            if (!isLogout && (hasFolder || (url.search === '' && link.classList.contains('mv-nav-item')))) {
                e.preventDefault();
                const folder = this.normalizeFolderPath(params.get('folder') || '');

                this.loadFolder(folder);

                // Update URL in bar
                const newParams = new URLSearchParams(url.search);
                if (folder) newParams.set('folder', folder);
                else newParams.delete('folder');
                const newSearch = newParams.toString();
                const newPath = url.pathname + (newSearch ? `?${newSearch}` : '');

                const st = { mv: true, folder: folder, navId: this._newNavId() };
                history.pushState(st, '', newPath);
                this._navPush(st);
            }
        });

        // Handle Back/Forward
        window.addEventListener('popstate', (e) => {
            // Prefer state.folder (can be present even when URL has no folder param).
            const st = e && e.state && typeof e.state === 'object' ? e.state : null;
            const folderFromState = (st && st.mv && typeof st.folder === 'string') ? st.folder : '';

            const params = new URLSearchParams(window.location.search);
            const folder = folderFromState || this.normalizeFolderPath(params.get('folder') || '');
            this.loadFolder(folder);
            this._navSyncFromPop(st, folder);
        });
    }

    async loadFolder(folder) {
        folder = this.normalizeFolderPath(folder);

        // Navigating folders takes precedence over any pending/in-flight global search.
        if (this._searchTimer) {
            clearTimeout(this._searchTimer);
            this._searchTimer = null;
        }
        if (this._searchFetchController && typeof this._searchFetchController.abort === 'function') {
            this._searchFetchController.abort();
        }
        this._searchRequestSeq++; // invalidate any in-flight search response

        // Invalidate any in-flight folder request immediately (even if we serve from cache).
        const requestId = ++this._folderRequestSeq;
        if (this._folderFetchController && typeof this._folderFetchController.abort === 'function') {
            this._folderFetchController.abort();
        }
        this._folderFetchController = null;

        // If we were viewing global search results, leaving search should never trigger a full reload.
        // Clean up UI state so the folder view behaves normally.
        if (this.isSearching) {
            const grid = document.getElementById('mv-grid');
            if (grid && this.searchSession && !this.searchSession.preForceListView) {
                grid.classList.remove('force-list-view');
            }
            this.isSearching = false;
            this.lastSearchQuery = '';
            this.searchSession = null;
        }

        // Empty folder means: restore junction default (server decides). Do not block.
        if (!folder) {
            this.updateSidebarActive('');
        } else {
            this.updateSidebarActive(folder);
        }

        const grid = document.getElementById('mv-grid');

        // Fast path: render from in-memory cache to avoid repeating slow server/S3 listings.
        const cached = this._getFolderCacheEntry(folder);
        if (cached && cached.data) {
            this._hideLoading();
            if (grid) {
                grid.style.opacity = '1';
                grid.style.pointerEvents = '';
            }
            this.renderGrid(cached.data);
            return;
        }

        // Loading UX: show clear feedback (spinner + slow hint) while keeping the page alive for downloads.
        this._showLoading('Cargando...', folder ? 'Abriendo carpeta' : 'Abriendo biblioteca');

        // Simple loading state - fade + prevent accidental clicks while DOM is mid-transition.
        if (grid) {
            grid.style.opacity = '0.5';
            grid.style.pointerEvents = 'none';
        }

        try {
            const url = new URL(window.location.href);
            this._folderFetchController = (typeof AbortController !== 'undefined') ? new AbortController() : null;

            if (folder) {
                url.searchParams.set('folder', folder);
            } else {
                url.searchParams.delete('folder');
            }
            url.searchParams.set('mv_ajax', '1');
            if (this.userData.nonce) url.searchParams.set('nonce', this.userData.nonce);

            const res = await fetch(url, this._folderFetchController ? { signal: this._folderFetchController.signal } : undefined);
            if (requestId !== this._folderRequestSeq) return; // stale response

            if (!res.ok) {
                throw new Error(`HTTP ${res.status}`);
            }

            const json = await res.json();
            if (requestId !== this._folderRequestSeq) return; // stale response

            if (json.success) {
                // If the server reports different user meta, update client-side state and invalidate caches.
                if (json.data) {
                    this._applyServerUserMeta(json.data);
                }
                if (json.data && json.data.current_folder) {
                    this.currentFolder = this.normalizeFolderPath(json.data.current_folder);
                    this.updateSidebarActive(json.data.current_folder);
                } else {
                    this.currentFolder = folder;
                }
                // Cache under requested key and (if different) the canonical current folder key.
                this._setFolderCacheEntry(folder, json.data);
                if (json.data && json.data.current_folder) {
                    const canonicalKey = this.normalizeFolderPath(json.data.current_folder);
                    if (canonicalKey !== folder) this._setFolderCacheEntry(canonicalKey, json.data);
                }
                this.renderGrid(json.data);
            } else {
                console.error('[MediaVault] AJAX Error:', json);
                // Show error message instead of reload
                if (grid) {
                    const errorMsg = (json.data && typeof json.data === 'string') ? json.data : 'Error desconocido';
                    grid.innerHTML = '<div style="padding: 40px; text-align: center; color: #666;">Error al cargar: ' + mvEscapeHtml(errorMsg) + ' <a href="javascript:safeReload()">Reintentar</a></div>';
                }
            }
        } catch (err) {
            if (err && err.name === 'AbortError') return;
            console.error('[MediaVault] Fetch Error:', err);
            // Show friendly error instead of auto-reload
            if (grid) {
                grid.innerHTML = '<div style="padding: 40px; text-align: center; color: #666;">Error de conexión: ' + mvEscapeHtml(err && err.message ? err.message : 'Error') + ' <a href="javascript:safeReload()">Reintentar</a></div>';
            }
        } finally {
            if (grid && requestId === this._folderRequestSeq) {
                grid.style.opacity = '1';
                grid.style.pointerEvents = '';
            }
            if (requestId === this._folderRequestSeq) {
                this._hideLoading();
            }
        }
    }

    _applyServerUserMeta(data) {
        if (!data || typeof data !== 'object') return;

        const nextTier = (data.user_tier !== undefined && data.user_tier !== null) ? parseInt(data.user_tier, 10) : null;
        const nextRemaining = (data.remaining_plays !== undefined && data.remaining_plays !== null) ? parseInt(data.remaining_plays, 10) : null;
        const prevTier = (this.userData && this.userData.tier !== undefined && this.userData.tier !== null) ? parseInt(this.userData.tier, 10) : null;

        let tierChanged = false;
        if (Number.isInteger(nextTier) && nextTier >= 0 && nextTier !== prevTier) {
            this.userData.tier = nextTier;
            this.userData.user_tier = nextTier;
            tierChanged = true;
        }

        if (Number.isInteger(nextRemaining)) {
            this.userData.remainingPlays = nextRemaining;
            this.userData.remaining_plays = nextRemaining;
        }

        if (typeof data.user_tier_name === 'string' && data.user_tier_name) {
            this.userData.tierName = data.user_tier_name;
        }

        // If tier changed, cached folder payloads are stale because download locks are tier-dependent.
        if (tierChanged) {
            try {
                this._folderCache.clear();
            } catch (e) { }
            this.initDesktopRecommendationNotice();
        }
    }

    renderGrid(data) {
        const grid = document.getElementById('mv-grid');
        if (!grid) return;

        // Ensure UI reflects server-side tier even when the initial HTML was cached.
        this._applyServerUserMeta(data);

        const currentDepth = (data && typeof data.current_depth === 'number') ? data.current_depth : 0;
        this.currentDepth = currentDepth;

        // Folder view is not the global-search results mode.
        this.isSearching = false;
        if (data && data.current_folder) {
            this.currentFolder = this.normalizeFolderPath(data.current_folder);
        }

        // --- UPDATE HEADER INFO ---
        const titleEl = document.getElementById('mv-hero-title');
        const descEl = document.getElementById('mv-hero-desc');
        const iconEl = document.getElementById('mv-hero-icon');
        const breadcrumbsEl = document.getElementById('mv-breadcrumbs');

        if (titleEl && data.hero_title) titleEl.textContent = data.hero_title;
        if (descEl && data.hero_desc_prefix) {
            descEl.textContent = `${data.hero_desc_prefix} • ${data.file_count || 0} Archivos`;
        }
        if (iconEl && data.hero_icon) iconEl.textContent = data.hero_icon;

        if (breadcrumbsEl && data.breadcrumbs) {
            this.updateBreadcrumbs(data.breadcrumbs);
        }
        // --------------------------

        grid.innerHTML = '';

        // Render Folders
        data.folders.forEach(f => {
            const encodedPath = encodeURIComponent(f.path);
            const safeFolderPath = mvEscapeAttr(f.path);
            const safeFolderSort = mvEscapeAttr(f.sort_name);
            const safeFolderName = mvEscapeHtml(f.name);
            const safeFolderNameAttr = mvEscapeAttr(f.name);

            // Folder action:
            // - Home (junction, depth 0): show "Ver contenido" for top-level sections.
            // - Below sidebar sections (depth 1+): show "Descargar" on every folder card (subject to tier/locks).
            let actionBtn = '';
            if (currentDepth >= 1) {
                // Download Button - SECURED FOR DEMO
                if (f && f.can_download) {
                    actionBtn = `
                    <button type="button" class="mv-folder-download-btn" data-folder="${safeFolderPath}"
                        data-name="${safeFolderNameAttr}" title="Descargar contenido de la carpeta">
                        Descargar
                    </button>`;
                } else {
                    actionBtn = `
                    <button type="button" class="mv-folder-download-btn locked" 
                        style="background:#3f3f46; cursor:not-allowed; opacity:0.7; border:none; color:white; padding:4px 10px; border-radius:6px; font-size:12px;"
                        title="Descarga disponible solo en plan Premium">
                        🔒 Descargar
                    </button>`;
                }
            } else {
                actionBtn = `
                <a href="?folder=${encodedPath}" class="mv-folder-view-btn" title="Ver contenido de la carpeta">
                    Ver contenido
                </a>`;
            }

            const html = `
                <div class="jpsm-mv-card mv-item-folder" data-name="${safeFolderSort}" data-type="folder" data-path="${safeFolderPath}">
                    <a href="?folder=${encodedPath}" class="jpsm-mv-card-link">
                        <div class="jpsm-mv-cover">${f.cover_html}</div>
                        <div style="flex:1;">
                            <div class="jpsm-mv-title">
                                <span class="locked-icon" style="display:none; font-size: 0.9em; margin-right: 4px;">🔒</span>
                                ${safeFolderName}
                            </div>
                            <div class="jpsm-mv-meta">Carpeta</div>
                        </div>
                    </a>
                    ${actionBtn}
                </div>
            `;
            grid.insertAdjacentHTML('beforeend', html);
        });

        // Render Files
        data.files.forEach(f => {
            const ext = f.ext.toLowerCase();
            const isMedia = this.isAudioFile(ext) || this.isVideoFile(ext);
            const safePath = mvEscapeAttr(f.path);
            const safeName = mvEscapeHtml(f.name);
            const safeNameAttr = mvEscapeAttr(f.name);
            const bpm = parseInt(f.bpm || 0, 10);
            const bpmMeta = Number.isFinite(bpm) && bpm > 0 ? ` • ${bpm} BPM` : '';

            let actionBtns = '';
            if (isMedia) {
                actionBtns += `<button type="button" class="mv-preview-btn" data-path="${safePath}" data-name="${safeNameAttr}" data-type="${mvEscapeAttr(f.ext)}" title="Reproducir demostración">Reproducir</button>`;
            }

            // Download Link - SECURED FOR DEMO
            // Only show real download link if server says the user can download this file.
            if (f && f.can_download) {
                actionBtns += `<a href="#" class="mv-download-btn" data-path="${safePath}" data-name="${safeNameAttr}" data-type="file" title="Descargar archivo">Descargar</a>`;
            } else {
                // Demo User: Show Locked Button
                actionBtns += `<button type="button" class="mv-download-btn locked" 
                    style="background:#3f3f46; cursor:not-allowed; opacity:0.7; border:none; color:white; padding:4px 10px; border-radius:6px; font-size:12px;"
                    title="Descarga disponible solo en plan Premium">
                    🔒 Descargar
                </button>`;
            }

	            const html = `
	                <div class="jpsm-mv-card mv-item-file" data-name="${mvEscapeAttr(String(f.name || '').toLowerCase())}" data-date="${mvEscapeAttr(f.date)}" data-size="${mvEscapeAttr(f.size)}" data-bpm="${Number.isFinite(bpm) ? bpm : 0}" data-type="file" data-path="${safePath}">
	                    <div class="jpsm-mv-cover">
	                        <div class="jpsm-mv-cover-icon">${mvEscapeHtml(f.icon)}</div>
	                    </div>
	                    <div style="flex:1;">
	                        <div class="jpsm-mv-title">
	                            <span class="locked-icon" style="display:none; font-size: 0.9em; margin-right: 4px;">🔒</span>
	                            ${safeName}
	                        </div>
	                        <div class="jpsm-mv-meta">${mvEscapeHtml(f.size_fmt)} • ${mvEscapeHtml(f.date)}${bpmMeta}</div>
	                        <div class="jpsm-mv-actions" style="display:flex; gap:8px; margin-top:12px; padding: 0 16px;">
	                             ${actionBtns}
	                        </div>
	                    </div>
	                </div>
	            `;
            grid.insertAdjacentHTML('beforeend', html);
        });

        // Re-apply logic
        const searchInput = document.getElementById('mv-search-input');
        const q = searchInput ? searchInput.value.trim() : '';
        this.filterCards(q);
        this.applyFolderLocks();
    }

    updateBreadcrumbs(breadcrumbs) {
        const container = document.getElementById('mv-breadcrumbs');
        if (!container || !breadcrumbs) return;

        let html = '';

        breadcrumbs.forEach((bc, idx) => {
            const isLast = (idx === breadcrumbs.length - 1);
            const safeName = mvEscapeHtml(bc.name);

            if (idx > 0) {
                html += `<span class="separator" style="opacity:0.5">/</span>`;
            }

            if (isLast) {
                html += `<span style="color: var(--mv-text); font-weight: 600;">${safeName}</span>`;
            } else {
                html += `
                    <a href="?folder=${encodeURIComponent(bc.path)}" style="color:var(--mv-text-muted); text-decoration:none; transition: color 0.2s;">
                        ${safeName}
                    </a>
                `;
            }
        });

        container.innerHTML = html;
        // Scroll to end on mobile to show current context
        setTimeout(() => container.scrollLeft = container.scrollWidth, 100);
    }
}

// ============================================
// ADMIN MANAGER CLASS
// ============================================

class AdminManager {
    constructor() {
        this.userData = window.MV_USER_DATA || {};
        this.panelVisible = false;
        this.currentTab = 'users';

        // DOM Elements
        this.toggle = document.getElementById('mv-admin-toggle');
        this.panel = document.getElementById('mv-admin-panel');
        this.closeBtn = document.getElementById('mv-admin-close');

        // Fail-safe: panel is disabled in frontend unless backend explicitly enables it.
        const panelEnabled = mvFlagEnabled(this.userData.frontendAdminPanelEnabled);
        const isAdmin = mvFlagEnabled(this.userData.isAdmin);
        if (panelEnabled && isAdmin && this.toggle && this.panel && this.closeBtn) {
            this.toggle?.classList.add('visible');
            this.bindEvents();
            console.log('[AdminManager] Admin mode enabled');
        } else {
            console.log('[AdminManager] Not an admin');
        }
    }

    bindEvents() {
        // Toggle panel
        this.toggle?.addEventListener('click', () => this.togglePanel());
        this.closeBtn?.addEventListener('click', () => this.togglePanel());

        // Tab switching
        document.querySelectorAll('.mv-admin-tab').forEach(tab => {
            tab.addEventListener('click', (e) => this.switchTab(e.target.dataset.tab));
        });

        // User search
        const searchInput = document.getElementById('mv-admin-user-search');
        let searchTimeout;
        searchInput?.addEventListener('input', (e) => {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => this.searchUser(e.target.value), 500);
        });

        // WhatsApp unlock buttons (delegated)
        document.addEventListener('click', (e) => {
            const unlockBtn = e.target.closest('.mv-unlock-btn');
            if (unlockBtn) {
                e.preventDefault();
                const folderName = unlockBtn.dataset.folder || 'este pack';
                this.openWhatsApp(folderName);
            }
        });

        // Index sync button
        const syncBtn = document.getElementById('mv-sync-index-btn');
        syncBtn?.addEventListener('click', () => this.syncIndex());
    }

    togglePanel() {
        this.panelVisible = !this.panelVisible;
        this.panel?.classList.toggle('open', this.panelVisible);

        if (this.panelVisible && this.currentTab === 'users') {
            // Focus search on open
            document.getElementById('mv-admin-user-search')?.focus();
        }
    }

    switchTab(tabName) {
        this.currentTab = tabName;

        // Update tab buttons
        document.querySelectorAll('.mv-admin-tab').forEach(tab => {
            tab.classList.toggle('active', tab.dataset.tab === tabName);
        });

        // Show/hide tab content
        document.getElementById('mv-admin-tab-users').style.display = tabName === 'users' ? '' : 'none';
        document.getElementById('mv-admin-tab-folders').style.display = tabName === 'folders' ? '' : 'none';
        document.getElementById('mv-admin-tab-leads').style.display = tabName === 'leads' ? '' : 'none';
        const indexTab = document.getElementById('mv-admin-tab-index');
        if (indexTab) indexTab.style.display = tabName === 'index' ? '' : 'none';

        // Load data for tabs
        if (tabName === 'folders') this.loadFolders();
        if (tabName === 'leads') this.loadLeads();
        if (tabName === 'index') this.loadIndexStats();
    }

    async loadIndexStats() {
        try {
            const nonce = this.userData.nonce ? `&nonce=${encodeURIComponent(this.userData.nonce)}` : '';
            const res = await fetch(`?action=mv_index_stats${nonce}`);
            const data = await res.json();

            if (data.success) {
                const stats = data.data;
                document.getElementById('mv-index-total').textContent = stats.total.toLocaleString();
                document.getElementById('mv-index-audio').textContent = stats.audio.toLocaleString();
                document.getElementById('mv-index-video').textContent = stats.video.toLocaleString();

                if (stats.last_sync) {
                    const date = new Date(stats.last_sync);
                    document.getElementById('mv-index-lastsync').textContent = date.toLocaleString('es-MX', {
                        month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit'
                    });
                } else {
                    document.getElementById('mv-index-lastsync').textContent = 'Nunca';
                }
            }
        } catch (err) {
            console.error('[AdminManager] Error loading index stats:', err);
        }
    }

    async syncIndex() {
        const btn = document.getElementById('mv-sync-index-btn');
        const status = document.getElementById('mv-sync-status');

        btn.disabled = true;
        btn.textContent = '⏳ Sincronizando...';
        btn.style.opacity = '0.7';
        status.textContent = 'Esto puede tomar varios minutos...';
        status.style.color = '#f59e0b';

        try {
            const nonce = this.userData.nonce ? `&nonce=${encodeURIComponent(this.userData.nonce)}` : '';
            const res = await fetch(`?action=mv_sync_index${nonce}`);
            const data = await res.json();

            if (data.success) {
                status.textContent = `✅ Sincronizado: ${data.data.synced} archivos`;
                status.style.color = '#22c55e';
                this.loadIndexStats(); // Refresh stats
            } else {
                status.textContent = `❌ Error: ${data.data}`;
                status.style.color = '#ef4444';
            }
        } catch (err) {
            status.textContent = `❌ Error de conexión`;
            status.style.color = '#ef4444';
            console.error('[AdminManager] Sync error:', err);
        } finally {
            btn.disabled = false;
            btn.textContent = '🔄 Sincronizar Índice';
            btn.style.opacity = '1';
        }
    }

    async searchUser(email) {
        if (!email || email.length < 3) {
            document.getElementById('mv-admin-user-results').innerHTML = '<p style="color:#71717a;">Escribe un email para buscar...</p>';
            return;
        }

        try {
            const nonce = this.userData.nonce ? `&nonce=${encodeURIComponent(this.userData.nonce)}` : '';
            const response = await fetch(`?action=mv_get_user_meta&email=${encodeURIComponent(email)}${nonce}`);
            const data = await response.json();

            if (data.success) {
                this.renderUserCard(data.data);
            } else {
                // User not found - show option to create
                this.renderNewUserCard(email);
            }
        } catch (err) {
            console.error('[AdminManager] Error searching user:', err);
        }
    }

    renderUserCard(user) {
        const container = document.getElementById('mv-admin-user-results');
        const tierNames = {
            0: 'Demo',
            1: 'Básico',
            2: 'VIP + Básico',
            3: 'VIP + Videos',
            4: 'VIP + Pelis',
            5: 'Full',
        };
        const safeEmailHtml = mvEscapeHtml(user && user.email ? user.email : '');
        const safeEmailAttr = mvEscapeAttr(user && user.email ? user.email : '');

        container.innerHTML = `
            <div class="mv-admin-user-card">
            <div class="mv-admin-user-email">${safeEmailHtml}</div>
            <div style="display:flex; gap:8px; align-items:center; flex-wrap: wrap;">
                <span style="color:#71717a; font-size:12px;">Nivel:</span>
                <select class="mv-admin-tier-select" data-email="${safeEmailAttr}">
                    <option value="0" ${user.tier === 0 ? 'selected' : ''}>Demo</option>
                    <option value="1" ${user.tier === 1 ? 'selected' : ''}>Básico</option>
                    <option value="2" ${user.tier === 2 ? 'selected' : ''}>VIP + Básico</option>
                    <option value="3" ${user.tier === 3 ? 'selected' : ''}>VIP + Videos</option>
                    <option value="4" ${user.tier === 4 ? 'selected' : ''}>VIP + Pelis</option>
                    <option value="5" ${user.tier === 5 ? 'selected' : ''}>Full</option>
                </select>
                <button type="button" class="mv-admin-update-btn" style="background:var(--mv-accent); color:white; border:none; padding:6px 12px; border-radius:6px; cursor:pointer; font-size:12px; font-weight:500;">Actualizar</button>
            </div>
            <div style="margin-top:8px; font-size:11px; color:#71717a;">
                ${user.is_customer ? '✓ Cliente' : '📊 Lead'} ·
                Reproducciones: ${user.plays}
                ${user.remaining_plays >= 0 ? ` (${user.remaining_plays} restantes)` : ' (Ilimitadas)'}
            </div>
        </div>
        `;

        // Bind update button click
        container.querySelector('.mv-admin-update-btn')?.addEventListener('click', () => {
            const select = container.querySelector('.mv-admin-tier-select');
            if (select) {
                this.updateUserTier(select.dataset.email, parseInt(select.value));
            }
        });
    }

    renderNewUserCard(email) {
        const container = document.getElementById('mv-admin-user-results');
        const safeEmailHtml = mvEscapeHtml(email);
        const safeEmailAttr = mvEscapeAttr(email);
        container.innerHTML = `
            <div class="mv-admin-user-card">
                <div class="mv-admin-user-email">${safeEmailHtml}</div>
                <p style="color:#71717a; font-size:12px;">Usuario no encontrado. Asigna un nivel para crearlo:</p>
                <div style="display:flex; gap:8px; align-items:center;">
                    <select class="mv-admin-tier-select" data-email="${safeEmailAttr}">
                        <option value="0">Demo</option>
                        <option value="1">Básico</option>
                        <option value="2">VIP + Básico</option>
                        <option value="3">VIP + Videos</option>
                        <option value="4">VIP + Pelis</option>
                        <option value="5">Full</option>
                    </select>
                    <button type="button" class="mv-admin-update-btn" style="background:var(--mv-accent); color:white; border:none; padding:6px 12px; border-radius:6px; cursor:pointer; font-size:12px; font-weight:500;">Crear</button>
                </div>
            </div>
        `;

        container.querySelector('.mv-admin-update-btn')?.addEventListener('click', () => {
            const select = container.querySelector('.mv-admin-tier-select');
            if (select) {
                this.updateUserTier(select.dataset.email, parseInt(select.value));
            }
        });
    }

    async updateUserTier(email, tier) {
        try {
            const formData = new FormData();
            formData.append('action', 'jpsm_update_user_tier');
            formData.append('email', email);
            formData.append('tier', tier);
            if (this.userData.access_nonce) formData.append('nonce', this.userData.access_nonce);
            else if (this.userData.nonce) formData.append('nonce', this.userData.nonce);

            // Use WordPress AJAX URL from global or admin-ajax.php path
            const ajaxUrl = window.MV_USER_DATA?.ajax_url || '/wp-admin/admin-ajax.php';
            const response = await fetch(ajaxUrl, { method: 'POST', body: formData });
            const data = await response.json();

            console.log('[AdminManager] Update response:', data);

            if (data.success) {
                const tierNames = {
                    0: 'Demo',
                    1: 'Básico',
                    2: 'VIP + Básico',
                    3: 'VIP + Videos',
                    4: 'VIP + Pelis',
                    5: 'Full',
                };
                const savedTier = data.data?.new_value ?? tier;
                window.MediaVault?.showToast(`✅ ${email} actualizado a ${tierNames[savedTier]}`, 'success');
            } else {
                window.MediaVault?.showToast('❌ Error: ' + (data.data || 'Desconocido'), 'error');
            }
        } catch (err) {
            console.error('[AdminManager] Error updating tier:', err);
            window.MediaVault?.showToast('❌ Error de conexión', 'error');
        }
    }

    async loadFolders() {
        const container = document.getElementById('mv-admin-folder-list');
        container.innerHTML = '<p style="color:#71717a;">Cargando carpetas...</p>';

        // Get folders from injected server data (sidebar structure)
        const folders = this.userData.sidebarFolders || [];

        if (folders.length === 0) {
            container.innerHTML = '<p style="color:#71717a;">No hay carpetas disponibles para configurar.</p>';
            return;
        }

        // Build folder list
        let html = '';
        folders.forEach(folderPath => {
            // Remove trailing slash for display
            const displayName = folderPath.replace(/\/$/, '');
            const safeDisplayNameHtml = mvEscapeHtml(displayName);
            const safeFolderPathAttr = mvEscapeAttr(folderPath);

            html += `
                <div class="mv-admin-user-card" style="display:flex; justify-content:space-between; align-items:center;">
                    <span style="font-size:13px;">📁 ${safeDisplayNameHtml}</span>
                    <select class="mv-admin-tier-select" data-folder="${safeFolderPathAttr}" style="width:auto;">
                        <option value="1">Básico</option>
                        <option value="2">VIP</option>
                        <option value="3">Full</option>
                    </select>
                </div>
            `;
        });
        container.innerHTML = html;

        // Bind change events
        container.querySelectorAll('.mv-admin-tier-select').forEach(select => {
            select.addEventListener('change', (e) => {
                this.updateFolderTier(e.target.dataset.folder, parseInt(e.target.value));
            });
        });
    }

    async updateFolderTier(folder, tier) {
        try {
            const formData = new FormData();
            formData.append('action', 'mv_update_folder');
            formData.append('folder', folder);
            formData.append('tier', tier);
            if (this.userData.nonce) formData.append('nonce', this.userData.nonce);

            const response = await fetch('', { method: 'POST', body: formData });
            const data = await response.json();

            if (data.success) {
                const tierNames = { 1: 'Básico', 2: 'VIP', 3: 'Full' };
                window.MediaVault?.showToast(`📁 ${folder} → ${tierNames[tier]}`, 'success');
            }
        } catch (err) {
            console.error('[AdminManager] Error updating folder:', err);
        }
    }

    async loadLeads() {
        const container = document.getElementById('mv-admin-leads-list');
        container.innerHTML = '<p style="color:#71717a;">Cargando leads...</p>';

        try {
            const nonce = this.userData.nonce ? `&nonce=${encodeURIComponent(this.userData.nonce)}` : '';
            const response = await fetch(`?action=mv_get_leads${nonce}`);
            const data = await response.json();

            if (data.success && Object.keys(data.data).length > 0) {
                let html = '<p style="color:#71717a; font-size:12px; margin-bottom:12px;">Leads capturados:</p>';
                Object.values(data.data).forEach(lead => {
                    const safeLeadEmail = mvEscapeHtml(lead && lead.email ? lead.email : '');
                    const safeLeadRegistered = mvEscapeHtml(lead && lead.registered ? lead.registered : '');
                    html += `
                        <div class="mv-admin-user-card" style="padding:10px;">
                            <div style="font-size:13px;">${safeLeadEmail}</div>
                            <div style="font-size:11px; color:#71717a;">${safeLeadRegistered || 'Fecha desconocida'}</div>
                        </div>
                    `;
                });
                container.innerHTML = html;
            } else {
                container.innerHTML = '<p style="color:#71717a;">No hay leads registrados aún.</p>';
            }
        } catch (err) {
            console.error('[AdminManager] Error loading leads:', err);
            container.innerHTML = '<p style="color:#ef4444;">Error al cargar leads.</p>';
        }
    }

    openWhatsApp(folderName) {
        const phone = (this.userData.whatsappNumber || '').toString().trim();
        const email = this.userData.email || '';
        const message = `Hola, soy ${email}. Quiero acceso al pack "${folderName}". ¿Cómo procedo con el pago?`;
        if (phone) {
            const url = `https://wa.me/${phone}?text=${encodeURIComponent(message)}`;
            window.open(url, '_blank');
            return;
        }

        alert('Este sitio no tiene configurado el número de WhatsApp para upgrades.');
    }
}

// Mobile Sidebar Toggle Logic
document.addEventListener('DOMContentLoaded', () => {
    const mobileToggle = document.getElementById('mv-mobile-toggle');
    const sidebar = document.getElementById('mv-sidebar');
    const overlay = document.getElementById('mv-sidebar-overlay');
    const navLinks = document.querySelectorAll('.mv-nav-item');

    function toggleSidebar() {
        if (!sidebar || !overlay) return;
        const isActive = sidebar.classList.contains('active');

        if (isActive) {
            sidebar.classList.remove('active');
            overlay.classList.remove('active');
            mobileToggle.textContent = '☰';
        } else {
            sidebar.classList.add('active');
            overlay.classList.add('active');
            mobileToggle.textContent = '×';
        }
    }

    function closeSidebar() {
        if (!sidebar || !overlay) return;
        sidebar.classList.remove('active');
        overlay.classList.remove('active');
        if (mobileToggle) mobileToggle.textContent = '☰';
    }

    if (mobileToggle) {
        mobileToggle.addEventListener('click', (e) => {
            e.stopPropagation();
            toggleSidebar();
        });
    }

    if (overlay) {
        overlay.addEventListener('click', closeSidebar);
    }

    // Close on navigation
    if (navLinks) {
        navLinks.forEach(link => {
            link.addEventListener('click', () => {
                if (window.innerWidth < 768) {
                    closeSidebar();
                }
            });
        });
    }
});
