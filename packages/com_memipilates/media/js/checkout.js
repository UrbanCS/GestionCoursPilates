(() => {
  'use strict';

  const csrf = () => {
    try {
      return window.Joomla?.getOptions?.('csrf.token')
        || document.querySelector('input[type="hidden"][value="1"][name]')?.name
        || '';
    } catch { return ''; }
  };
  const idempotencyKey = () => window.crypto?.randomUUID?.() || String(Date.now()) + '-' + Math.random().toString(16).slice(2);
  const post = async (url, fields) => {
    const body = new FormData();
    Object.entries(fields).forEach(([key, value]) => body.append(key, value));
    const token = csrf();
    if (token) body.append(token, '1');
    const response = await fetch(url, { method: 'POST', body, credentials: 'same-origin', headers: { Accept: 'application/json' } });
    const payload = await response.json();
    const data = payload && typeof payload.data === 'object' ? { ...payload, ...payload.data } : payload;
    if (!response.ok || !data?.success) throw new Error(data?.message || 'Paiement indisponible');
    return data;
  };
  const loadSquare = (environment) => new Promise((resolve, reject) => {
    if (window.Square) return resolve(window.Square);
    const script = document.createElement('script');
    script.src = environment === 'sandbox'
      ? 'https://sandbox.web.squarecdn.com/v1/square.js'
      : 'https://web.squarecdn.com/v1/square.js';
    script.async = true;
    script.onload = () => window.Square ? resolve(window.Square) : reject(new Error('Square unavailable'));
    script.onerror = () => reject(new Error('Square failed to load'));
    document.head.appendChild(script);
  });

  document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-memi-checkout]').forEach((root) => {
      const status = root.querySelector('[data-memi-checkout-status]');
      const packageSelect = root.querySelector('[data-memi-package-select]');
      const start = root.querySelector('[data-memi-payment-start]');
      const pay = root.querySelector('[data-memi-payment-submit]');
      const cardTarget = root.querySelector('[data-memi-square-card]');
      let order = null;
      let card = null;
      const setStatus = (text, kind = 'status') => { if (status) { status.textContent = text; status.dataset.status = kind; } };

      start?.addEventListener('click', async () => {
        const packageId = packageSelect?.value;
        if (!packageId) { setStatus('Choisissez un forfait.', 'error'); return; }
        start.disabled = true;
        setStatus('Préparation du paiement sécurisé…');
        try {
          order = await post(root.dataset.createUrl, { package_id: packageId, promotion_code: root.querySelector('[name="promotion_code"]')?.value || '' });
          if (Number(order.total_cents) === 0) {
            const result = await post(root.dataset.payUrl, {
              order_id: order.id,
              source_id: 'zero-total-order',
              idempotency_key: idempotencyKey()
            });
            setStatus(result.message || 'Paiement reçu. Vos crédits sont maintenant disponibles.', 'success');
            root.dataset.paid = 'true';
            return;
          }
          if (!order.square_application_id || !order.square_location_id) {
            throw new Error('Le paiement Square n’est pas encore configuré.');
          }
          const Square = await loadSquare(root.dataset.squareEnvironment || order.environment || 'sandbox');
          const payments = Square.payments(order.square_application_id, order.square_location_id);
          card = await payments.card();
          cardTarget.replaceChildren();
          await card.attach(cardTarget);
          pay.hidden = false;
          setStatus('Carte sécurisée prête. Total : ' + (Number(order.total_cents) / 100).toFixed(2) + ' ' + order.currency + '.');
        } catch (error) {
          setStatus(error.message || 'Le paiement n’a pas pu être préparé.', 'error');
        } finally {
          start.disabled = false;
        }
      });

      pay?.addEventListener('click', async () => {
        if (!order || !card) return;
        pay.disabled = true;
        setStatus('Traitement du paiement…');
        try {
          const tokenResult = await card.tokenize();
          if (tokenResult.status !== 'OK') throw new Error('Les informations de paiement sont invalides.');
          const result = await post(root.dataset.payUrl, {
            order_id: order.id,
            source_id: tokenResult.token,
            idempotency_key: idempotencyKey()
          });
          setStatus(result.message || 'Paiement reçu. Vos crédits sont maintenant disponibles.', 'success');
          root.dataset.paid = 'true';
        } catch (error) {
          setStatus(error.message || 'Le paiement a échoué. Aucun crédit n’a été ajouté.', 'error');
        } finally {
          pay.disabled = false;
        }
      });
    });
  });
})();
