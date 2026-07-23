/**
 * Attendance kiosk interaction layer for com_memipilates.
 *
 * The browser only collects a token and renders the response. Attendance,
 * authorization, idempotency and loyalty points remain server-side decisions.
 */
(() => {
  'use strict';

  const DEFAULTS = {
    autoResetMs: 5000,
    cameraIntervalMs: 220,
    cooldownMs: 700,
    defaultMode: 'reader',
    locale: document.documentElement.lang || 'fr-CA',
    maxTokenLength: 256,
    minTokenLength: 8,
    requireSession: false,
    sounds: false,
    timeZone: '',
    tokenPattern: '^[A-Za-z0-9_-]+$'
  };

  const getJoomlaOptions = (name) => {
    try {
      return window.Joomla && typeof window.Joomla.getOptions === 'function'
        ? (window.Joomla.getOptions(name) || {})
        : {};
    } catch (error) {
      return {};
    }
  };

  const toBoolean = (value, fallback = false) => {
    if (value === undefined || value === null || value === '') {
      return fallback;
    }

    return !['0', 'false', 'no', 'off'].includes(String(value).toLowerCase());
  };

  const toNumber = (value, fallback, minimum = 0) => {
    const number = Number(value);
    return Number.isFinite(number) && number >= minimum ? number : fallback;
  };

  const normalizeMode = (mode) => {
    const value = String(mode || '').toLowerCase();
    if (['reader', 'hid', 'external', 'scanner', 'lecteur'].includes(value)) {
      return 'reader';
    }
    if (['camera', 'caméra'].includes(value)) {
      return 'camera';
    }
    if (['manual', 'search', 'recherche'].includes(value)) {
      return 'manual';
    }
    if (value === 'test') {
      return 'test';
    }
    return 'reader';
  };

  const createIdempotencyKey = () => {
    if (window.crypto && typeof window.crypto.randomUUID === 'function') {
      return window.crypto.randomUUID();
    }

    if (window.crypto && typeof window.crypto.getRandomValues === 'function') {
      const values = new Uint32Array(4);
      window.crypto.getRandomValues(values);
      return Array.from(values, (value) => value.toString(16).padStart(8, '0')).join('-');
    }

    return `${Date.now().toString(36)}-${Math.random().toString(36).slice(2)}`;
  };

  const isPrintableKey = (event) => event.key.length === 1 && !event.ctrlKey && !event.metaKey && !event.altKey;

  const asText = (value) => (value === undefined || value === null ? '' : String(value));

  class KioskController {
    constructor(root) {
      this.root = root;
      const options = getJoomlaOptions('com_memipilates.kiosk');
      this.options = {
        ...DEFAULTS,
        ...options,
        autoResetMs: toNumber(root.dataset.autoResetMs, toNumber(options.autoResetMs, DEFAULTS.autoResetMs), 250),
        cameraIntervalMs: toNumber(root.dataset.cameraIntervalMs, toNumber(options.cameraIntervalMs, DEFAULTS.cameraIntervalMs), 80),
        cooldownMs: toNumber(root.dataset.cooldownMs, toNumber(options.cooldownMs, DEFAULTS.cooldownMs), 0),
        defaultMode: root.dataset.defaultMode || options.defaultMode || DEFAULTS.defaultMode,
        locale: root.dataset.locale || options.locale || DEFAULTS.locale,
        maxTokenLength: toNumber(root.dataset.maxTokenLength, toNumber(options.maxTokenLength, DEFAULTS.maxTokenLength), 1),
        minTokenLength: toNumber(root.dataset.minTokenLength, toNumber(options.minTokenLength, DEFAULTS.minTokenLength), 1),
        requireSession: toBoolean(root.dataset.requireSession, toBoolean(options.requireSession, DEFAULTS.requireSession)),
        manualUrl: root.dataset.manualUrl || options.manualUrl || '',
        scanUrl: root.dataset.scanUrl || options.scanUrl || '',
        sounds: toBoolean(root.dataset.sounds, toBoolean(options.sounds, DEFAULTS.sounds)),
        testMode: toBoolean(root.dataset.testMode, toBoolean(options.testMode, false)),
        timeZone: root.dataset.timeZone || options.timeZone || DEFAULTS.timeZone,
        tokenPattern: root.dataset.tokenPattern || options.tokenPattern || DEFAULTS.tokenPattern
      };

      this.input = root.querySelector('[data-memi-scan-input]');
      this.statusNode = root.querySelector('[data-memi-kiosk-status]');
      this.resultNode = root.querySelector('[data-memi-kiosk-result]');
      this.video = root.querySelector('[data-memi-camera-video]');
      this.manualForm = root.querySelector('[data-memi-manual-form]');
      this.manualResults = root.querySelector('[data-memi-manual-results]');
      this.sessionControl = root.querySelector('[data-memi-kiosk-session]');
      this.state = {
        activeMode: normalizeMode(this.options.defaultMode),
        camera: null,
        cameraFrame: null,
        lastCompletedAt: 0,
        manualRecords: [],
        processing: false,
        resetTimer: null,
        scan: {
          characters: 0,
          endAt: null,
          enterDetected: false,
          lastKeyAt: null,
          startedAt: null
        },
        test: {
          last: null
        },
        testMode: this.options.testMode
      };

      this.boundVisibilityHandler = this.handleVisibilityChange.bind(this);
      this.configureElements();
      this.bind();
      this.setMode(this.state.activeMode, { announce: false });
      this.setTestMode(this.state.testMode, { announce: false });
      this.updateTestDiagnostics();
      this.startClock();
      this.setStatus(this.t('COM_MEMIPILATES_KIOSK_READY', 'Prêt à scanner le prochain code.'), 'ready');
    }

    t(key, fallback, replacements = {}) {
      const messages = this.options.messages || {};
      let text = messages[key] || messages[key.toLowerCase()] || '';

      if (!text && window.Joomla && window.Joomla.Text && typeof window.Joomla.Text._ === 'function') {
        try {
          const translated = window.Joomla.Text._(key);
          text = translated && translated !== key ? translated : '';
        } catch (error) {
          text = '';
        }
      }

      text = asText(text || fallback);
      return Object.entries(replacements).reduce(
        (result, [name, value]) => result.replace(new RegExp(`%${name}%`, 'g'), asText(value)),
        text
      );
    }

    configureElements() {
      if (this.input) {
        this.input.autocomplete = 'off';
        this.input.autocapitalize = 'off';
        this.input.spellcheck = false;
        this.input.maxLength = this.options.maxTokenLength;
      }

      if (this.statusNode) {
        this.statusNode.setAttribute('role', 'status');
        this.statusNode.setAttribute('aria-live', 'polite');
      }

      if (this.resultNode) {
        this.resultNode.setAttribute('aria-live', 'polite');
      }

      if (this.video) {
        this.video.muted = true;
        this.video.playsInline = true;
        this.video.setAttribute('playsinline', '');
      }
    }

    bind() {
      if (this.input) {
        this.input.addEventListener('keydown', (event) => this.handleInputKeydown(event));
        this.input.addEventListener('input', () => this.handleInputChange());
        this.input.addEventListener('blur', () => {
          this.updateTestDiagnostics();
          this.requestReaderFocus();
        });

        const form = this.input.closest('form');
        if (form) {
          form.addEventListener('submit', (event) => {
            event.preventDefault();
            this.submitInput();
          });
        }
      }

      this.root.querySelectorAll('[data-memi-kiosk-mode]').forEach((button) => {
        button.addEventListener('click', () => {
          const mode = normalizeMode(button.dataset.memiKioskMode);
          if (mode === 'test') {
            this.setTestMode(!this.state.testMode);
            return;
          }
          this.setMode(mode);
        });
      });

      this.root.querySelectorAll('[data-memi-kiosk-test-toggle]').forEach((button) => {
        button.addEventListener('click', () => this.setTestMode(!this.state.testMode));
      });

      this.root.querySelectorAll('[data-memi-camera-start]').forEach((button) => {
        button.addEventListener('click', () => this.startCamera());
      });
      this.root.querySelectorAll('[data-memi-camera-stop]').forEach((button) => {
        button.addEventListener('click', () => this.stopCamera());
      });

      this.root.querySelectorAll('[data-memi-kiosk-fullscreen]').forEach((button) => {
        button.addEventListener('click', () => this.toggleFullscreen());
      });

      if (this.sessionControl) {
        this.sessionControl.addEventListener('change', () => {
          this.root.dispatchEvent(new CustomEvent('memi:kiosk-session-change', {
            bubbles: true,
            detail: { sessionId: this.getSessionId() }
          }));
        });
      }

      this.bindManualSearch();
      document.addEventListener('visibilitychange', this.boundVisibilityHandler);
      window.addEventListener('focus', () => this.requestReaderFocus());
    }

    handleVisibilityChange() {
      if (document.hidden) {
        this.stopCamera({ announce: false });
        return;
      }
      this.requestReaderFocus();
    }

    handleInputKeydown(event) {
      if (event.key === 'Enter') {
        event.preventDefault();
        this.state.scan.enterDetected = true;
        this.state.scan.endAt = performance.now();
        this.updateTestDiagnostics();
        this.submitInput();
        return;
      }

      if (event.key === 'Escape') {
        this.clearInput();
        return;
      }

      if (!isPrintableKey(event)) {
        return;
      }

      const now = performance.now();
      if (!this.state.scan.startedAt || (this.state.scan.lastKeyAt && now - this.state.scan.lastKeyAt > 650)) {
        this.resetScanMetrics();
        this.state.test.last = null;
        this.state.scan.startedAt = now;
      }
      this.state.scan.lastKeyAt = now;
      this.state.scan.characters += 1;
      this.updateTestDiagnostics();
    }

    handleInputChange() {
      if (!this.input) {
        return;
      }

      if (this.input.value.length > this.options.maxTokenLength) {
        this.input.value = this.input.value.slice(0, this.options.maxTokenLength);
      }
      if (this.input.value && !this.state.scan.startedAt) {
        this.state.test.last = null;
        this.state.scan.startedAt = performance.now();
      }
      this.state.scan.characters = Math.max(this.state.scan.characters, this.input.value.length);
      this.updateTestDiagnostics();
    }

    resetScanMetrics() {
      this.state.scan = {
        characters: 0,
        endAt: null,
        enterDetected: false,
        lastKeyAt: null,
        startedAt: null
      };
    }

    submitInput() {
      const token = this.input ? this.input.value : '';
      const metrics = { ...this.state.scan, endAt: this.state.scan.endAt || performance.now() };

      if (this.state.testMode) {
        this.finishTest(token, metrics);
        return;
      }

      this.submitToken(token, 'hid', metrics);
    }

    validateToken(value) {
      const token = asText(value).trim();
      if (!token) {
        return { valid: false, reason: 'empty', token: '' };
      }
      if (token.length < this.options.minTokenLength) {
        return { valid: false, reason: 'too_short', token: '' };
      }
      if (token.length > this.options.maxTokenLength) {
        return { valid: false, reason: 'too_long', token: '' };
      }

      try {
        const expression = new RegExp(this.options.tokenPattern);
        const match = token.match(expression);
        if (!match || match[0] !== token) {
          return { valid: false, reason: 'format', token: '' };
        }
      } catch (error) {
        if (!/^[A-Za-z0-9_-]+$/.test(token)) {
          return { valid: false, reason: 'format', token: '' };
        }
      }

      return { valid: true, token };
    }

    tokenError(reason) {
      const messages = {
        empty: this.t('COM_MEMIPILATES_KIOSK_SCAN_EMPTY', 'Aucun code n’a été reçu.'),
        too_short: this.t('COM_MEMIPILATES_KIOSK_SCAN_INCOMPLETE', 'Le code reçu est incomplet.'),
        too_long: this.t('COM_MEMIPILATES_KIOSK_SCAN_TOO_LONG', 'Le code reçu est trop long.'),
        format: this.t('COM_MEMIPILATES_KIOSK_SCAN_FORMAT', 'Le format du code n’est pas valide.')
      };
      return messages[reason] || messages.format;
    }

    async submitToken(value, method, metrics = null) {
      const validated = this.validateToken(value);
      if (!validated.valid) {
        this.setStatus(this.tokenError(validated.reason), 'error');
        this.renderResult({ message: this.tokenError(validated.reason) }, 'error');
        this.clearInput();
        this.resetScanMetrics();
        this.requestReaderFocus();
        return;
      }

      if (this.state.testMode) {
        this.finishTest(value, metrics || this.state.scan);
        return;
      }

      if (this.state.processing) {
        this.setStatus(this.t('COM_MEMIPILATES_KIOSK_PROCESSING', 'Traitement en cours…'), 'warning');
        return;
      }

      if ((Date.now() - this.state.lastCompletedAt) < this.options.cooldownMs) {
        this.setStatus(this.t('COM_MEMIPILATES_KIOSK_COOLDOWN', 'Veuillez patienter un instant avant le prochain scan.'), 'warning');
        this.clearInput();
        return;
      }

      const sessionId = this.getSessionId();
      if (this.options.requireSession && !sessionId) {
        const message = this.t('COM_MEMIPILATES_KIOSK_SESSION_REQUIRED', 'Sélectionnez un cours avant de scanner.');
        this.setStatus(message, 'warning');
        this.renderResult({ message }, 'warning');
        this.clearInput();
        return;
      }

      if (!this.options.scanUrl) {
        const message = this.t('COM_MEMIPILATES_KIOSK_SCAN_UNAVAILABLE', 'Le service de présence est indisponible.');
        this.setStatus(message, 'error');
        this.renderResult({ message }, 'error');
        this.clearInput();
        return;
      }

      this.setBusy(true);
      this.setStatus(this.t('COM_MEMIPILATES_KIOSK_PROCESSING', 'Traitement en cours…'), 'processing');
      this.clearResetTimer();

      try {
        const response = await this.post(this.options.scanUrl, {
          idempotency_key: createIdempotencyKey(),
          method,
          session_id: sessionId,
          task: 'kiosk.scan',
          token: validated.token
        });
        const payload = response && typeof response.data === 'object'
          ? { ...response, ...response.data }
          : response;

        if (!payload || !this.isSuccess(payload)) {
          const message = asText(payload && payload.message) || this.t('COM_MEMIPILATES_KIOSK_SCAN_REJECTED', 'Cette présence ne peut pas être confirmée.');
          const kind = this.resultKind(payload && payload.status, 'warning');
          this.setStatus(message, kind);
          this.renderResult(payload || { message }, kind);
          this.playTone(kind);
          return;
        }

        const message = asText(payload.message) || this.t('COM_MEMIPILATES_KIOSK_ATTENDANCE_CONFIRMED', 'Présence confirmée.');
        this.setStatus(message, 'success');
        this.renderResult(payload, 'success');
        this.playTone('success');
      } catch (error) {
        const message = error && error.userMessage
          ? error.userMessage
          : this.t('COM_MEMIPILATES_KIOSK_NETWORK_ERROR', 'La vérification a échoué. Vérifiez la connexion et réessayez.');
        this.setStatus(message, 'error');
        this.renderResult({ message }, 'error');
        this.playTone('error');
      } finally {
        this.setBusy(false);
        this.state.lastCompletedAt = Date.now();
        this.clearInput();
        this.resetScanMetrics();
        this.scheduleReadyState();
      }
    }

    isSuccess(payload) {
      return payload.success === true || payload.success === 1 || payload.success === '1' || payload.success === 'true';
    }

    resultKind(status, fallback) {
      const value = String(status || '').toLowerCase();
      if (['invalid', 'revoked', 'denied', 'error'].includes(value)) {
        return 'error';
      }
      if (['already_attended', 'waitlisted', 'override_required', 'no_reservation', 'inactive_session'].includes(value)) {
        return 'warning';
      }
      return fallback;
    }

    getSessionId() {
      if (this.sessionControl) {
        return this.sessionControl.value || '';
      }
      return this.root.dataset.sessionId || '';
    }

    async post(rawUrl, fields) {
      const url = new URL(rawUrl, window.location.href);
      if (!url.searchParams.has('task')) {
        url.searchParams.set('task', fields.task || 'kiosk.scan');
      }
      if (!url.searchParams.has('format')) {
        url.searchParams.set('format', 'json');
      }

      const body = new FormData();
      Object.entries(fields).forEach(([name, value]) => {
        if (value !== undefined && value !== null) {
          body.append(name, value);
        }
      });
      this.appendCsrfToken(body);

      const response = await fetch(url.toString(), {
        body,
        credentials: 'same-origin',
        headers: {
          Accept: 'application/json',
          'X-Requested-With': 'XMLHttpRequest'
        },
        method: 'POST'
      });

      let payload = null;
      try {
        payload = await response.json();
      } catch (error) {
        const parseError = new Error('Invalid JSON response');
        parseError.userMessage = this.t('COM_MEMIPILATES_KIOSK_SERVICE_ERROR', 'Le service de présence a retourné une réponse invalide.');
        throw parseError;
      }

      if (!response.ok) {
        const requestError = new Error('Kiosk request failed');
        requestError.userMessage = asText(payload && payload.message)
          || this.t('COM_MEMIPILATES_KIOSK_SERVICE_ERROR', 'Le service de présence est temporairement indisponible.');
        throw requestError;
      }

      return payload;
    }

    appendCsrfToken(body) {
      const input = this.root.querySelector('[data-memi-csrf-token]');
      if (input && input.name) {
        body.append(input.name, input.value || '1');
        return;
      }

      const tokenName = this.root.dataset.csrfToken
        || this.root.dataset.csrfTokenName
        || this.options.csrfToken
        || getJoomlaOptions('csrf.token');
      if (typeof tokenName === 'string' && /^[A-Za-z0-9_]+$/.test(tokenName)) {
        body.append(tokenName, '1');
      }
    }

    setBusy(busy) {
      this.state.processing = busy;
      this.root.setAttribute('aria-busy', String(busy));
      if (this.input) {
        this.input.disabled = busy;
      }
    }

    setStatus(message, kind = 'ready') {
      if (!this.statusNode) {
        return;
      }
      this.statusNode.textContent = message;
      this.statusNode.classList.remove('is-success', 'is-warning', 'is-error', 'is-processing');
      if (kind !== 'ready') {
        this.statusNode.classList.add(`is-${kind}`);
      }
    }

    clientName(client) {
      if (typeof client === 'string') {
        return client;
      }
      if (!client || typeof client !== 'object') {
        return '';
      }
      if (client.display_name || client.name || client.full_name) {
        return asText(client.display_name || client.name || client.full_name);
      }
      return [client.first_name, client.last_name].filter(Boolean).join(' ');
    }

    renderResult(payload, kind) {
      if (!this.resultNode) {
        return;
      }

      this.resultNode.hidden = false;
      this.resultNode.classList.remove('is-success', 'is-warning', 'is-error');
      this.resultNode.classList.add(`is-${kind}`);
      this.resultNode.replaceChildren();

      const title = document.createElement('div');
      title.dataset.memiKioskResultTitle = '';
      title.textContent = kind === 'success'
        ? this.t('COM_MEMIPILATES_KIOSK_ATTENDANCE_CONFIRMED', 'Présence confirmée.')
        : asText(payload && payload.message) || this.t('COM_MEMIPILATES_KIOSK_ATTENTION', 'Une intervention est nécessaire.');
      this.resultNode.append(title);

      const name = this.clientName(payload && payload.client);
      if (name) {
        const client = document.createElement('div');
        client.textContent = name;
        this.resultNode.append(client);
      }

      const message = asText(payload && payload.message);
      if (message && message !== title.textContent) {
        const description = document.createElement('div');
        description.textContent = message;
        this.resultNode.append(description);
      }

      if (payload && payload.points !== undefined && payload.points !== null) {
        const points = document.createElement('div');
        points.textContent = this.t('COM_MEMIPILATES_KIOSK_POINTS_ADDED', '%POINTS% points ajoutés.', { POINTS: payload.points });
        this.resultNode.append(points);
      }
      if (payload && payload.new_balance !== undefined && payload.new_balance !== null) {
        const balance = document.createElement('div');
        balance.textContent = this.t('COM_MEMIPILATES_KIOSK_POINTS_BALANCE', 'Nouveau solde : %POINTS% points.', { POINTS: payload.new_balance });
        this.resultNode.append(balance);
      }
    }

    clearResult() {
      if (!this.resultNode) {
        return;
      }
      this.resultNode.replaceChildren();
      this.resultNode.hidden = true;
      this.resultNode.classList.remove('is-success', 'is-warning', 'is-error');
    }

    clearInput() {
      if (this.input) {
        this.input.value = '';
      }
    }

    clearResetTimer() {
      if (this.state.resetTimer) {
        window.clearTimeout(this.state.resetTimer);
        this.state.resetTimer = null;
      }
    }

    scheduleReadyState() {
      this.clearResetTimer();
      this.state.resetTimer = window.setTimeout(() => {
        if (this.state.processing) {
          return;
        }
        this.clearResult();
        this.setStatus(this.t('COM_MEMIPILATES_KIOSK_READY', 'Prêt à scanner le prochain code.'), 'ready');
        this.requestReaderFocus();
      }, this.options.autoResetMs);
    }

    requestReaderFocus() {
      if (!this.input || this.state.activeMode !== 'reader' || this.state.processing || document.hidden) {
        return;
      }
      window.setTimeout(() => {
        const active = document.activeElement;
        const isManualField = this.manualForm && active && this.manualForm.contains(active);
        const isSessionControl = active === this.sessionControl;
        if (!isManualField && !isSessionControl && this.state.activeMode === 'reader' && !this.state.processing) {
          this.input.focus({ preventScroll: true });
          this.updateTestDiagnostics();
        }
      }, 80);
    }

    setMode(mode, { announce = true } = {}) {
      const nextMode = normalizeMode(mode);
      if (nextMode === 'test') {
        this.setTestMode(!this.state.testMode, { announce });
        return;
      }

      if (nextMode !== 'camera') {
        this.stopCamera({ announce: false });
      }
      this.state.activeMode = nextMode;
      this.root.dataset.memiKioskActiveMode = nextMode;

      this.root.querySelectorAll('[data-memi-kiosk-mode]').forEach((button) => {
        const active = normalizeMode(button.dataset.memiKioskMode) === nextMode;
        button.classList.toggle('is-active', active);
        button.setAttribute('aria-selected', String(active));
      });

      this.root.querySelectorAll('[data-memi-kiosk-pane]').forEach((pane) => {
        const paneMode = normalizeMode(pane.dataset.memiKioskPane);
        pane.hidden = paneMode === 'test' ? !this.state.testMode : paneMode !== nextMode;
      });

      if (nextMode === 'reader') {
        this.requestReaderFocus();
      } else if (nextMode === 'manual') {
        const field = this.manualForm && this.manualForm.querySelector('[data-memi-manual-query], input[name="query"], input[type="search"]');
        if (field) {
          field.focus({ preventScroll: true });
        }
      }

      if (announce) {
        const messages = {
          camera: this.t('COM_MEMIPILATES_KIOSK_CAMERA_READY', 'Mode caméra activé.'),
          manual: this.t('COM_MEMIPILATES_KIOSK_MANUAL_READY', 'Mode recherche manuelle activé.'),
          reader: this.t('COM_MEMIPILATES_KIOSK_READER_READY', 'Mode lecteur QR externe activé.')
        };
        this.setStatus(messages[nextMode], 'ready');
      }
    }

    setTestMode(enabled, { announce = true } = {}) {
      this.state.testMode = Boolean(enabled);
      this.root.dataset.memiKioskTestMode = String(this.state.testMode);
      this.root.querySelectorAll('[data-memi-kiosk-test-panel]').forEach((panel) => {
        panel.hidden = !this.state.testMode;
      });
      this.root.querySelectorAll('[data-memi-kiosk-test-toggle]').forEach((button) => {
        button.setAttribute('aria-pressed', String(this.state.testMode));
      });
      this.root.querySelectorAll('[data-memi-kiosk-mode="test"]').forEach((button) => {
        button.setAttribute('aria-pressed', String(this.state.testMode));
      });
      this.root.querySelectorAll('[data-memi-kiosk-pane="test"]').forEach((pane) => {
        pane.hidden = !this.state.testMode;
      });

      if (this.state.testMode) {
        this.stopCamera({ announce: false });
        this.state.activeMode = 'reader';
        this.setMode('reader', { announce: false });
      }
      this.updateTestDiagnostics();
      if (announce) {
        this.setStatus(
          this.state.testMode
            ? this.t('COM_MEMIPILATES_KIOSK_TEST_ENABLED', 'Mode test activé : aucune présence ne sera enregistrée.')
            : this.t('COM_MEMIPILATES_KIOSK_TEST_DISABLED', 'Mode test désactivé.'),
          'ready'
        );
      }
    }

    setTestField(name, value) {
      const selectors = [
        `[data-memi-kiosk-test-field="${name}"]`,
        `[data-memi-test-${name}]`
      ];
      this.root.querySelectorAll(selectors.join(',')).forEach((node) => {
        node.textContent = value;
      });
    }

    buildTestDiagnostics(value = this.input ? this.input.value : '', scan = this.state.scan) {
      const validated = this.validateToken(value);
      const end = scan.endAt || performance.now();
      const duration = scan.startedAt ? Math.max(0, Math.round(end - scan.startedAt)) : 0;
      const hasCharacters = Boolean(value || scan.characters);
      const configuredTransport = this.root.dataset.hidTransport || this.options.hidTransport;

      return {
        characters: Math.max(scan.characters, asText(value).length),
        duration,
        enterDetected: scan.enterDetected,
        focus: document.activeElement === this.input,
        formatValid: validated.valid,
        hasCharacters,
        length: asText(value).length,
        transport: configuredTransport || this.t('COM_MEMIPILATES_KIOSK_TEST_TRANSPORT_UNKNOWN', 'Indéterminé dans le navigateur')
      };
    }

    renderTestDiagnostics(diagnostic) {
      const focus = diagnostic.focus;
      const hasCharacters = diagnostic.hasCharacters;

      this.setTestField('received', hasCharacters
        ? this.t('COM_MEMIPILATES_KIOSK_TEST_YES', 'Oui')
        : this.t('COM_MEMIPILATES_KIOSK_TEST_NO', 'Non'));
      this.setTestField('chars', String(diagnostic.characters));
      this.setTestField('length', String(diagnostic.length));
      this.setTestField('enter', diagnostic.enterDetected
        ? this.t('COM_MEMIPILATES_KIOSK_TEST_DETECTED', 'Détectée')
        : this.t('COM_MEMIPILATES_KIOSK_TEST_NOT_DETECTED', 'Non détectée'));
      this.setTestField('duration', `${diagnostic.duration} ms`);
      this.setTestField('format', hasCharacters
        ? (diagnostic.formatValid ? this.t('COM_MEMIPILATES_KIOSK_TEST_VALID', 'Conforme') : this.t('COM_MEMIPILATES_KIOSK_TEST_INVALID', 'Non conforme'))
        : this.t('COM_MEMIPILATES_KIOSK_TEST_PENDING', 'En attente'));
      this.setTestField('focus', focus
        ? this.t('COM_MEMIPILATES_KIOSK_TEST_FOCUSED', 'Champ actif')
        : this.t('COM_MEMIPILATES_KIOSK_TEST_UNFOCUSED', 'Champ inactif'));
      this.setTestField('transport', diagnostic.transport);
    }

    updateTestDiagnostics(value = this.input ? this.input.value : '', scan = this.state.scan) {
      const hasActiveInput = Boolean(value || scan.startedAt || scan.characters);
      if (!hasActiveInput && this.state.test.last) {
        this.renderTestDiagnostics({
          ...this.state.test.last,
          focus: document.activeElement === this.input
        });
        return;
      }
      this.renderTestDiagnostics(this.buildTestDiagnostics(value, scan));
    }

    finishTest(value, scan) {
      const metrics = { ...scan, endAt: scan.endAt || performance.now() };
      this.state.test.last = this.buildTestDiagnostics(value, metrics);
      this.renderTestDiagnostics(this.state.test.last);
      this.clearInput();
      this.resetScanMetrics();
      this.setStatus(this.t('COM_MEMIPILATES_KIOSK_TEST_COMPLETE', 'Lecture testée. Aucune présence n’a été enregistrée.'), 'ready');
      this.requestReaderFocus();
    }

    async startCamera() {
      if (this.state.testMode) {
        this.setStatus(this.t('COM_MEMIPILATES_KIOSK_CAMERA_TEST_UNAVAILABLE', 'Désactivez le mode test avant d’utiliser la caméra.'), 'warning');
        return;
      }
      if (!this.video || !navigator.mediaDevices || typeof navigator.mediaDevices.getUserMedia !== 'function') {
        this.setStatus(this.t('COM_MEMIPILATES_KIOSK_CAMERA_UNAVAILABLE', 'La caméra n’est pas disponible. Utilisez la recherche manuelle.'), 'warning');
        return;
      }
      if (!window.isSecureContext) {
        this.setStatus(this.t('COM_MEMIPILATES_KIOSK_CAMERA_HTTPS', 'La caméra exige une connexion HTTPS. Utilisez la recherche manuelle.'), 'warning');
        return;
      }

      const detector = await this.resolveCameraDetector();
      if (!detector) {
        this.setStatus(this.t('COM_MEMIPILATES_KIOSK_CAMERA_DECODER_UNAVAILABLE', 'La lecture QR par caméra n’est pas prise en charge par ce navigateur. Utilisez la recherche manuelle.'), 'warning');
        return;
      }

      this.stopCamera({ announce: false });
      this.setMode('camera', { announce: false });
      this.setStatus(this.t('COM_MEMIPILATES_KIOSK_CAMERA_PERMISSION', 'Autorisez l’accès à la caméra pour scanner un code QR.'), 'processing');

      try {
        const stream = await this.requestCameraStream();
        this.video.srcObject = stream;
        await this.video.play();
        this.state.camera = { detector, stream };
        this.setStatus(this.t('COM_MEMIPILATES_KIOSK_CAMERA_SCANNING', 'Caméra active. Cadrez le code QR.'), 'ready');
        this.scanCameraFrame();
      } catch (error) {
        this.stopCamera({ announce: false });
        this.setStatus(this.cameraErrorMessage(error), 'error');
      }
    }

    async requestCameraStream() {
      const constraints = [
        { audio: false, video: { facingMode: { ideal: 'environment' } } },
        { audio: false, video: true }
      ];
      let lastError;
      for (const constraint of constraints) {
        try {
          return await navigator.mediaDevices.getUserMedia(constraint);
        } catch (error) {
          lastError = error;
          if (['NotAllowedError', 'SecurityError'].includes(error && error.name)) {
            throw error;
          }
        }
      }
      throw lastError || new Error('Camera unavailable');
    }

    async resolveCameraDetector() {
      if ('BarcodeDetector' in window) {
        try {
          const detector = new window.BarcodeDetector({ formats: ['qr_code'] });
          return {
            detect: async (source) => (await detector.detect(source)).map((code) => code.rawValue).filter(Boolean)
          };
        } catch (error) {
          // Fall through to an optional, locally bundled decoder adapter.
        }
      }

      const localDecoder = window.MemiPilatesQrDecoder || window.MemiQrDecoder;
      if (localDecoder && typeof localDecoder.detect === 'function') {
        return {
          detect: async (source) => {
            const detected = await localDecoder.detect(source);
            return (Array.isArray(detected) ? detected : [detected])
              .map((code) => (typeof code === 'string' ? code : code && (code.rawValue || code.value)))
              .filter(Boolean);
          }
        };
      }
      if (localDecoder && typeof localDecoder.create === 'function') {
        try {
          const decoder = await localDecoder.create();
          if (decoder && typeof decoder.detect === 'function') {
            return {
              detect: async (source) => {
                const detected = await decoder.detect(source);
                return (Array.isArray(detected) ? detected : [detected])
                  .map((code) => (typeof code === 'string' ? code : code && (code.rawValue || code.value)))
                  .filter(Boolean);
              }
            };
          }
        } catch (error) {
          return null;
        }
      }
      return null;
    }

    async scanCameraFrame() {
      if (!this.state.camera || !this.video || this.state.processing) {
        return;
      }

      try {
        if (this.video.readyState >= HTMLMediaElement.HAVE_CURRENT_DATA) {
          const values = await this.state.camera.detector.detect(this.video);
          const token = values.find((value) => this.validateToken(value).valid);
          if (token) {
            await this.stopCamera({ announce: false });
            await this.submitToken(token, 'camera');
            return;
          }
        }
      } catch (error) {
        // A transient frame-decoding error should not end a camera session.
      }

      if (this.state.camera) {
        window.setTimeout(() => {
          this.state.cameraFrame = window.requestAnimationFrame(() => this.scanCameraFrame());
        }, this.options.cameraIntervalMs);
      }
    }

    stopCamera({ announce = true } = {}) {
      if (this.state.cameraFrame) {
        window.cancelAnimationFrame(this.state.cameraFrame);
        this.state.cameraFrame = null;
      }
      if (this.state.camera && this.state.camera.stream) {
        this.state.camera.stream.getTracks().forEach((track) => track.stop());
      }
      this.state.camera = null;
      if (this.video) {
        this.video.pause();
        this.video.srcObject = null;
      }
      if (announce) {
        this.setStatus(this.t('COM_MEMIPILATES_KIOSK_CAMERA_STOPPED', 'Caméra arrêtée.'), 'ready');
      }
    }

    cameraErrorMessage(error) {
      const name = error && error.name;
      if (['NotAllowedError', 'SecurityError'].includes(name)) {
        return this.t('COM_MEMIPILATES_KIOSK_CAMERA_DENIED', 'L’accès à la caméra a été refusé. Utilisez la recherche manuelle.');
      }
      if (name === 'NotFoundError') {
        return this.t('COM_MEMIPILATES_KIOSK_CAMERA_NOT_FOUND', 'Aucune caméra n’a été trouvée. Utilisez la recherche manuelle.');
      }
      return this.t('COM_MEMIPILATES_KIOSK_CAMERA_ERROR', 'La caméra ne peut pas démarrer. Utilisez la recherche manuelle.');
    }

    bindManualSearch() {
      if (this.manualForm) {
        this.manualForm.addEventListener('submit', (event) => {
          event.preventDefault();
          this.searchManual();
        });
      }

      if (this.manualResults) {
        this.manualResults.addEventListener('click', (event) => {
          const button = event.target.closest('[data-memi-manual-result]');
          if (!button) {
            return;
          }
          const index = Number(button.dataset.memiManualResult);
          const client = this.state.manualRecords[index];
          if (!client) {
            return;
          }
          this.root.dispatchEvent(new CustomEvent('memi:kiosk-manual-select', {
            bubbles: true,
            detail: {
              client,
              sessionId: this.getSessionId(),
              source: 'manual'
            }
          }));
          this.confirmManual(client);
        });
      }
    }

    async searchManual() {
      if (!this.manualForm) {
        return;
      }
      const field = this.manualForm.querySelector('[data-memi-manual-query], input[name="query"], input[type="search"]');
      const query = asText(field && field.value).trim();
      if (!query) {
        this.setStatus(this.t('COM_MEMIPILATES_KIOSK_MANUAL_QUERY_REQUIRED', 'Saisissez un nom, un courriel ou un numéro de client.'), 'warning');
        return;
      }

      const searchEvent = new CustomEvent('memi:kiosk-manual-search', {
        bubbles: true,
        cancelable: true,
        detail: {
          controller: this,
          query,
          sessionId: this.getSessionId(),
          source: 'manual'
        }
      });
      this.root.dispatchEvent(searchEvent);
      if (searchEvent.defaultPrevented) {
        return;
      }

      const endpoint = this.manualForm.dataset.searchUrl || this.root.dataset.manualSearchUrl;
      if (!endpoint) {
        this.setStatus(this.t('COM_MEMIPILATES_KIOSK_MANUAL_HOOK', 'La recherche manuelle est prête pour la sélection d’un client.'), 'ready');
        return;
      }

      try {
        this.setStatus(this.t('COM_MEMIPILATES_KIOSK_MANUAL_SEARCHING', 'Recherche en cours…'), 'processing');
        const payload = await this.post(endpoint, {
          query,
          session_id: this.getSessionId(),
          task: 'kiosk.search'
        });
        const records = Array.isArray(payload && payload.results)
          ? payload.results
          : (Array.isArray(payload && payload.data) ? payload.data : []);
        this.renderManualResults(records);
        this.setStatus(records.length
          ? this.t('COM_MEMIPILATES_KIOSK_MANUAL_RESULTS', '%COUNT% résultat(s) trouvé(s).', { COUNT: records.length })
          : this.t('COM_MEMIPILATES_KIOSK_MANUAL_EMPTY', 'Aucun client trouvé.'), records.length ? 'ready' : 'warning');
      } catch (error) {
        this.setStatus(this.t('COM_MEMIPILATES_KIOSK_MANUAL_ERROR', 'La recherche manuelle a échoué. Réessayez.'), 'error');
      }
    }

    renderManualResults(records) {
      if (!this.manualResults) {
        return;
      }
      this.state.manualRecords = records;
      this.manualResults.replaceChildren();
      records.forEach((record, index) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.dataset.memiManualResult = String(index);
        button.textContent = this.clientName(record) || this.t('COM_MEMIPILATES_KIOSK_MANUAL_CLIENT', 'Client');
        this.manualResults.append(button);
      });
    }

    async confirmManual(client) {
      const sessionId = this.getSessionId();
      const userId = client && (client.id || client.user_id);
      if (!sessionId) {
        this.setStatus(this.t('COM_MEMIPILATES_KIOSK_SESSION_REQUIRED', 'Sélectionnez un cours avant de confirmer une présence.'), 'warning');
        return;
      }
      if (!userId || !this.options.manualUrl) {
        this.setStatus(this.t('COM_MEMIPILATES_KIOSK_MANUAL_ERROR', 'La présence manuelle ne peut pas être confirmée.'), 'error');
        return;
      }
      try {
        this.setBusy(true);
        this.setStatus(this.t('COM_MEMIPILATES_KIOSK_PROCESSING', 'Traitement de la présence…'), 'processing');
        const response = await this.post(this.options.manualUrl, {
          idempotency_key: createIdempotencyKey(),
          session_id: sessionId,
          task: 'kiosk.manual',
          user_id: userId
        });
        const payload = response && typeof response.data === 'object' ? { ...response, ...response.data } : response;
        if (!payload || !this.isSuccess(payload)) {
          throw new Error(asText(payload && payload.message) || 'Manual attendance rejected');
        }
        this.setStatus(asText(payload.message) || this.t('COM_MEMIPILATES_KIOSK_ATTENDANCE_CONFIRMED', 'Présence confirmée.'), 'success');
        this.renderResult(payload, 'success');
        this.playTone('success');
      } catch (error) {
        this.setStatus(this.t('COM_MEMIPILATES_KIOSK_MANUAL_ERROR', 'La présence manuelle n’a pas pu être confirmée.'), 'error');
      } finally {
        this.setBusy(false);
        this.requestReaderFocus();
      }
    }

    async toggleFullscreen() {
      try {
        if (document.fullscreenElement) {
          if (document.exitFullscreen) {
            await document.exitFullscreen();
          }
          return;
        }
        const request = this.root.requestFullscreen || this.root.webkitRequestFullscreen;
        if (request) {
          await request.call(this.root);
        }
      } catch (error) {
        this.setStatus(this.t('COM_MEMIPILATES_KIOSK_FULLSCREEN_ERROR', 'Le plein écran n’est pas disponible dans ce navigateur.'), 'warning');
      }
    }

    playTone(kind) {
      if (!this.options.sounds || !window.AudioContext && !window.webkitAudioContext) {
        return;
      }
      try {
        const Context = window.AudioContext || window.webkitAudioContext;
        const context = new Context();
        const oscillator = context.createOscillator();
        const gain = context.createGain();
        oscillator.frequency.value = kind === 'success' ? 880 : 220;
        gain.gain.setValueAtTime(0.0001, context.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.08, context.currentTime + 0.01);
        gain.gain.exponentialRampToValueAtTime(0.0001, context.currentTime + 0.15);
        oscillator.connect(gain).connect(context.destination);
        oscillator.start();
        oscillator.stop(context.currentTime + 0.16);
        oscillator.addEventListener('ended', () => context.close());
      } catch (error) {
        // Audio feedback is optional and must never interrupt scanning.
      }
    }

    startClock() {
      const clock = this.root.querySelector('[data-memi-kiosk-clock]');
      if (!clock) {
        return;
      }
      const formatOptions = {
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit'
      };
      if (this.options.timeZone) {
        formatOptions.timeZone = this.options.timeZone;
      }

      let formatter;
      try {
        formatter = new Intl.DateTimeFormat(this.options.locale, formatOptions);
      } catch (error) {
        delete formatOptions.timeZone;
        formatter = new Intl.DateTimeFormat(this.options.locale, formatOptions);
      }

      const format = () => {
        clock.textContent = formatter.format(new Date());
      };
      format();
      window.setInterval(format, 1000);
    }
  }

  const initialise = (root) => {
    if (!root || root.dataset.memiKioskInitialised === 'true') {
      return root && root.memiKiosk;
    }
    const controller = new KioskController(root);
    root.dataset.memiKioskInitialised = 'true';
    root.memiKiosk = controller;
    return controller;
  };

  const initialiseAll = () => document.querySelectorAll('[data-memi-kiosk]').forEach(initialise);

  window.MemiPilatesKiosk = { initialise, initialiseAll };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initialiseAll, { once: true });
  } else {
    initialiseAll();
  }
})();
