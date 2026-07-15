(() => {
  'use strict';
  const csrf = () => {
    try {
      return window.Joomla?.getOptions?.('csrf.token')
        || document.querySelector('input[type="hidden"][value="1"][name]')?.name
        || '';
    } catch { return ''; }
  };
  const request = async (url, fields) => {
    const body = new FormData();
    Object.entries(fields).forEach(([key, value]) => body.append(key, value));
    const token = csrf();
    if (token) body.append(token, '1');
    const response = await fetch(url, { method: 'POST', body, credentials: 'same-origin', headers: { Accept: 'application/json' } });
    const payload = await response.json();
    const data = payload && typeof payload.data === 'object' ? { ...payload, ...payload.data } : payload;
    if (!response.ok || !data?.success) throw new Error(data?.message || 'Request failed');
    return data;
  };
  document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-memi-booking-form]').forEach((form) => {
      const result = form.querySelector('[data-memi-booking-result]');
      form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const submit = form.querySelector('[type="submit"]');
        if (submit) submit.disabled = true;
        if (result) result.textContent = 'Traitement de votre demande…';
        try {
          const data = await request(form.action, Object.fromEntries(new FormData(form).entries()));
          if (result) result.textContent = data.message || 'Votre demande a été confirmée.';
          form.dataset.completed = 'true';
        } catch (error) {
          if (result) result.textContent = error.message || 'La demande n’a pas pu être traitée.';
        } finally {
          if (submit) submit.disabled = false;
        }
      });
    });
  });
})();
