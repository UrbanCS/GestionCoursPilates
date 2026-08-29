(() => {
  'use strict';

  const els = {};
  let state = null;
  let csrf = '';
  let deferredInstall = null;
  let saveTimer = 0;

  const $ = (selector) => document.querySelector(selector);
  const $$ = (selector) => [...document.querySelectorAll(selector)];
  const esc = (value = '') => String(value).replace(/[&<>'"]/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' }[char]));
  const safePath = (value, fallback = '/app/') => {
    const path = String(value || '');
    return path.startsWith('/') && !path.startsWith('//') ? path : fallback;
  };
  const categoryLabel = (category) => ({ courses: 'Cours', promotions: 'Promotion', other: 'Nouveauté' }[category] || 'Memi');
  const date = (value, options = {}) => value ? new Intl.DateTimeFormat('fr-CA', { timeZone: 'America/Toronto', ...options }).format(new Date(value)) : '';
  const relativeDate = (value) => {
    if (!value) return '';
    const delta = new Date(value).getTime() - Date.now();
    const days = Math.round(delta / 86400000);
    if (Math.abs(days) <= 1) return new Intl.RelativeTimeFormat('fr-CA', { numeric: 'auto' }).format(days, 'day');
    return date(value, { day: 'numeric', month: 'short' });
  };

  async function api(path, options = {}) {
    const headers = { Accept: 'application/json', ...(options.headers || {}) };
    if (options.body && !(options.body instanceof FormData)) headers['Content-Type'] = 'application/json';
    if (options.method && options.method !== 'GET') headers['X-Memi-CSRF'] = csrf;
    const response = await fetch(`/app/api/${path}`, { credentials: 'same-origin', ...options, headers });
    let payload;
    try { payload = await response.json(); } catch { payload = { ok: false, message: 'Réponse du serveur invalide.' }; }
    if (!response.ok || !payload.ok) {
      const error = new Error(payload.message || 'Une erreur est survenue.');
      error.code = payload.error || 'request_failed';
      error.status = response.status;
      throw error;
    }
    return payload.data;
  }

  function toast(message) {
    els.toast.textContent = message;
    els.toast.classList.add('visible');
    clearTimeout(toast.timer);
    toast.timer = setTimeout(() => els.toast.classList.remove('visible'), 3600);
  }

  function cacheElements() {
    Object.assign(els, {
      authPanel: $('#auth-panel'), dashboard: $('#app-dashboard'), loginForm: $('#login-form'), loginError: $('#login-error'),
      logout: $('#logout-button'), heroAction: $('#hero-action'), userName: $('#user-name'), mobileNav: $('#mobile-nav'),
      preferencesForm: $('#preferences-form'), preferencesStatus: $('#preferences-status'), pushButton: $('#push-button'),
      testPush: $('#test-push-button'), pushDot: $('#push-dot'), pushTitle: $('#push-title'), pushDescription: $('#push-description'),
      notificationList: $('#notification-list'), notificationCount: $('#notification-count'), courseList: $('#course-list'),
      promotionList: $('#promotion-list'), adminPanel: $('#admin-panel'), adminStats: $('#admin-stats'),
      announcementForm: $('#announcement-form'), announcementStatus: $('#announcement-status'),
      installButton: $('#install-button'), installHelp: $('#install-help'), toast: $('#toast'),
    });
  }

  async function loadState({ quiet = false } = {}) {
    try {
      state = await api('state.php');
      csrf = state.csrf || '';
      render();
    } catch (error) {
      if (!quiet) toast(error.message);
      els.authPanel.hidden = false;
    }
  }

  function render() {
    const authenticated = Boolean(state?.authenticated);
    els.authPanel.hidden = authenticated;
    els.dashboard.hidden = !authenticated;
    els.mobileNav.hidden = !authenticated;
    els.heroAction.textContent = authenticated ? 'Voir mes nouvelles' : 'Ouvrir mon espace';
    els.heroAction.dataset.target = authenticated ? '#preferences-section' : '#auth-panel';
    if (!authenticated) return;

    els.userName.textContent = (state.user?.name || 'Memi').split(/\s+/)[0];
    $('#metric-credits').textContent = state.metrics?.credits ?? 0;
    $('#metric-points').textContent = state.metrics?.points ?? 0;
    $('#metric-bookings').textContent = state.metrics?.upcomingBookings ?? 0;
    ['courses', 'promotions', 'other'].forEach((key) => {
      const input = els.preferencesForm.elements[key];
      if (input) input.checked = Boolean(state.preferences?.[key]);
    });
    renderPushStatus();
    renderNotifications();
    renderCourses();
    renderPromotions();
    renderAdmin();
  }

  function renderNotifications() {
    const items = state.notifications || [];
    els.notificationCount.textContent = String(items.length);
    if (!items.length) {
      els.notificationList.innerHTML = '<div class="empty-state">Vos nouvelles apparaîtront ici dès qu’elles seront publiées.</div>';
      return;
    }
    els.notificationList.innerHTML = items.map((item) => `
      <a class="feed-item" href="${esc(safePath(item.url))}">
        <span class="feed-category">${esc(categoryLabel(item.category))}</span>
        <span class="feed-copy"><strong>${esc(item.title)}</strong><small>${esc(item.body)}</small></span>
        <span class="feed-time">${esc(relativeDate(item.availableAt))}</span>
      </a>`).join('');
  }

  function renderCourses() {
    const items = state.courses || [];
    if (!items.length) {
      els.courseList.innerHTML = '<div class="empty-state">Aucun nouveau cours publié pour le moment.</div>';
      $('#hero-course').textContent = 'Votre moment pour bouger';
      $('#hero-course-time').textContent = 'Consultez l’horaire Memi';
      return;
    }
    const next = items[0];
    $('#hero-course').textContent = next.title;
    $('#hero-course-time').textContent = date(next.startsAt, { weekday: 'long', day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit' });
    els.courseList.innerHTML = items.slice(0, 5).map((item) => `
      <a class="course-card" href="${esc(safePath(item.url))}">
        <span class="course-date"><strong>${esc(date(item.startsAt, { day: 'numeric' }))}</strong>${esc(date(item.startsAt, { month: 'short' }))}</span>
        <span class="course-info"><strong>${esc(item.title)}</strong><small>${esc(date(item.startsAt, { weekday: 'long', hour: '2-digit', minute: '2-digit' }))}${item.instructor ? ` · ${esc(item.instructor)}` : ''}</small></span>
        <span class="spots">${item.remaining > 0 ? `${item.remaining} place${item.remaining > 1 ? 's' : ''}` : 'Liste d’attente'}</span>
      </a>`).join('');
  }

  function renderPromotions() {
    const items = state.promotions || [];
    if (!items.length) {
      els.promotionList.innerHTML = '<div class="offer"><strong>Vos avantages Memi</strong><p>Les prochaines offres apparaîtront ici.</p></div>';
      return;
    }
    els.promotionList.innerHTML = items.slice(0, 3).map((item) => `
      <div class="offer"><strong>${esc(item.title)}</strong><p>${esc(item.description || 'Offre en cours chez Memi Studio.')}</p>${item.code ? `<code>${esc(item.code)}</code>` : ''}</div>`).join('');
  }

  function renderAdmin() {
    const admin = state.user?.administrator && state.admin;
    els.adminPanel.hidden = !admin;
    if (!admin) return;
    els.adminStats.innerHTML = [
      `${state.admin.activeSubscriptions || 0} appareil(s) actif(s)`,
      `${state.admin.optedInUsers || 0} client(s) abonné(s)`,
      `${state.admin.queuedDeliveries || 0} envoi(s) en attente`,
    ].map((label) => `<span>${esc(label)}</span>`).join('');
  }

  async function currentSubscription() {
    if (!('serviceWorker' in navigator) || !('PushManager' in window)) return null;
    const registration = await navigator.serviceWorker.ready;
    return registration.pushManager.getSubscription();
  }

  async function renderPushStatus() {
    els.pushDot.className = 'status-dot';
    els.testPush.hidden = true;
    const unsupported = !state.pushAvailable || !('Notification' in window) || !('serviceWorker' in navigator) || !('PushManager' in window);
    if (unsupported) {
      els.pushTitle.textContent = 'Notifications non disponibles';
      els.pushDescription.textContent = 'Cet appareil ou ce navigateur ne prend pas en charge les notifications Web.';
      els.pushButton.textContent = 'Non disponible';
      els.pushButton.disabled = true;
      return;
    }
    if (Notification.permission === 'denied') {
      els.pushDot.classList.add('blocked');
      els.pushTitle.textContent = 'Notifications bloquées';
      els.pushDescription.textContent = 'Autorisez les notifications de memistudio.ca dans les réglages de votre appareil.';
      els.pushButton.textContent = 'Voir les instructions';
      els.pushButton.disabled = false;
      return;
    }
    const subscription = await currentSubscription();
    if (subscription) {
      els.pushDot.classList.add('active');
      els.pushTitle.textContent = 'Notifications activées';
      els.pushDescription.textContent = 'Cet appareil recevra les catégories cochées ci-dessus.';
      els.pushButton.textContent = 'Désactiver sur cet appareil';
      els.pushButton.disabled = false;
      els.testPush.hidden = false;
      return;
    }
    els.pushTitle.textContent = 'Notifications sur cet appareil';
    els.pushDescription.textContent = 'Activez-les pour recevoir vos choix même lorsque l’app est fermée.';
    els.pushButton.textContent = 'Activer sur cet appareil';
    els.pushButton.disabled = false;
  }

  async function login(event) {
    event.preventDefault();
    const submit = els.loginForm.querySelector('[type="submit"]');
    submit.disabled = true;
    els.loginError.textContent = '';
    const form = new FormData(els.loginForm);
    try {
      state = await api('login.php', { method: 'POST', body: JSON.stringify({ username: form.get('username'), password: form.get('password'), remember: form.get('remember') === '1' }) });
      csrf = state.csrf || csrf;
      els.loginForm.reset();
      render();
      toast('Bienvenue dans votre espace Memi.');
      window.scrollTo({ top: $('#app-dashboard').offsetTop - 60, behavior: 'smooth' });
    } catch (error) {
      els.loginError.textContent = error.message;
    } finally {
      submit.disabled = false;
    }
  }

  async function logout() {
    try {
      await api('logout.php', { method: 'POST', body: '{}' });
      state = { authenticated: false, csrf };
      render();
      window.scrollTo({ top: 0, behavior: 'smooth' });
      toast('Vous êtes maintenant déconnecté.');
      await loadState({ quiet: true });
    } catch (error) { toast(error.message); }
  }

  function schedulePreferencesSave() {
    clearTimeout(saveTimer);
    els.preferencesStatus.textContent = 'Enregistrement…';
    saveTimer = setTimeout(savePreferences, 450);
  }

  async function savePreferences() {
    const preferences = Object.fromEntries(['courses', 'promotions', 'other'].map((key) => [key, Boolean(els.preferencesForm.elements[key].checked)]));
    try {
      state.preferences = await api('preferences.php', { method: 'POST', body: JSON.stringify(preferences) });
      els.preferencesStatus.textContent = 'Choix enregistrés.';
      setTimeout(() => { els.preferencesStatus.textContent = ''; }, 2200);
    } catch (error) { els.preferencesStatus.textContent = error.message; }
  }

  function isIos() { return /iphone|ipad|ipod/i.test(navigator.userAgent); }
  function isStandalone() { return matchMedia('(display-mode: standalone)').matches || navigator.standalone === true; }
  function base64ToUint8Array(value) {
    const padding = '='.repeat((4 - (value.length % 4)) % 4);
    const raw = atob((value + padding).replace(/-/g, '+').replace(/_/g, '/'));
    return Uint8Array.from([...raw].map((char) => char.charCodeAt(0)));
  }

  async function togglePush() {
    if (Notification.permission === 'denied') {
      if (isIos()) els.installHelp.showModal();
      else toast('Ouvrez les réglages du navigateur et autorisez les notifications pour memistudio.ca.');
      return;
    }
    if (isIos() && !isStandalone()) {
      els.installHelp.showModal();
      return;
    }
    els.pushButton.disabled = true;
    try {
      const registration = await navigator.serviceWorker.ready;
      const existing = await registration.pushManager.getSubscription();
      if (existing) {
        await api('subscription.php', { method: 'DELETE', body: JSON.stringify({ endpoint: existing.endpoint }) });
        await existing.unsubscribe();
        state.subscribed = false;
        toast('Notifications désactivées sur cet appareil.');
      } else {
        const permission = await Notification.requestPermission();
        if (permission !== 'granted') throw new Error('Les notifications n’ont pas été autorisées.');
        const subscription = await registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: base64ToUint8Array(state.vapidPublicKey) });
        const json = subscription.toJSON();
        json.contentEncoding = 'aes128gcm';
        await api('subscription.php', { method: 'POST', body: JSON.stringify({ subscription: json }) });
        state.subscribed = true;
        toast('Notifications activées sur cet appareil.');
      }
      await renderPushStatus();
    } catch (error) {
      toast(error.message);
      await renderPushStatus();
    }
  }

  async function testPush() {
    els.testPush.disabled = true;
    try {
      await api('test-push.php', { method: 'POST', body: '{}' });
      toast('Notification test envoyée.');
    } catch (error) { toast(error.message); }
    finally { els.testPush.disabled = false; }
  }

  async function publishAnnouncement(event) {
    event.preventDefault();
    const submit = els.announcementForm.querySelector('[type="submit"]');
    const form = new FormData(els.announcementForm);
    const data = Object.fromEntries(form.entries());
    submit.disabled = true;
    els.announcementStatus.textContent = 'Publication…';
    try {
      await api('announcement.php', { method: 'POST', body: JSON.stringify(data) });
      els.announcementForm.reset();
      els.announcementStatus.textContent = 'Nouvelle publiée. Le cron notifiera les abonnés dans les 5 minutes.';
      toast('La nouvelle a été publiée.');
    } catch (error) { els.announcementStatus.textContent = error.message; }
    finally { submit.disabled = false; }
  }

  function setupInstall() {
    window.addEventListener('beforeinstallprompt', (event) => {
      event.preventDefault();
      deferredInstall = event;
      els.installButton.hidden = false;
    });
    window.addEventListener('appinstalled', () => { els.installButton.hidden = true; deferredInstall = null; toast('Memi a été ajoutée à votre appareil.'); });
    els.installButton.addEventListener('click', async () => {
      if (deferredInstall) {
        deferredInstall.prompt();
        await deferredInstall.userChoice;
        deferredInstall = null;
      } else if (isIos()) {
        els.installHelp.showModal();
      }
    });
    if (isIos() && !isStandalone()) els.installButton.hidden = false;
  }

  async function registerServiceWorker() {
    if (!('serviceWorker' in navigator)) return;
    try { await navigator.serviceWorker.register('/app/sw.js', { scope: '/app/' }); }
    catch (error) { console.warn('Service worker non enregistré:', error); }
  }

  function bindEvents() {
    els.loginForm.addEventListener('submit', login);
    els.logout.addEventListener('click', logout);
    els.heroAction.addEventListener('click', () => $(els.heroAction.dataset.target || '#auth-panel')?.scrollIntoView({ behavior: 'smooth', block: 'center' }));
    els.preferencesForm.addEventListener('change', schedulePreferencesSave);
    els.pushButton.addEventListener('click', togglePush);
    els.testPush.addEventListener('click', testPush);
    els.announcementForm.addEventListener('submit', publishAnnouncement);
    els.installHelp.querySelector('.dialog-close').addEventListener('click', () => els.installHelp.close());
  }

  async function init() {
    cacheElements();
    bindEvents();
    setupInstall();
    await registerServiceWorker();
    await loadState();
    setInterval(() => { if (document.visibilityState === 'visible') loadState({ quiet: true }); }, 300000);
  }

  document.addEventListener('DOMContentLoaded', init);
})();
