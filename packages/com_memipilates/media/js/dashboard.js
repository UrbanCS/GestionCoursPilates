(() => {
  'use strict';

  const tokenName = () => {
    try {
      const option = window.Joomla && typeof window.Joomla.getOptions === 'function'
        ? window.Joomla.getOptions('csrf.token')
        : '';
      return option || document.querySelector('input[type="hidden"][value="1"][name]')?.name || '';
    } catch (error) {
      return '';
    }
  };

  const createKey = () => window.crypto && window.crypto.randomUUID
    ? window.crypto.randomUUID()
    : String(Date.now()) + '-' + Math.random().toString(16).slice(2);

  const post = async (url, fields) => {
    const body = new FormData();
    Object.entries(fields).forEach(([name, value]) => body.append(name, value));
    const csrf = tokenName();
    if (csrf) body.append(csrf, '1');
    const response = await fetch(url, {
      method: 'POST',
      body,
      credentials: 'same-origin',
      headers: { Accept: 'application/json' }
    });
    const payload = await response.json();
    const data = payload && typeof payload.data === 'object' ? { ...payload, ...payload.data } : payload;
    if (!response.ok || !data?.success) throw new Error(data?.message || 'Request failed');
    return data;
  };

  document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-memi-client-dashboard]').forEach((root) => {
      const result = root.querySelector('[data-memi-dashboard-result]');
      const show = (message, kind = 'status') => {
        if (result) {
          result.textContent = message;
          result.dataset.status = kind;
        }
      };
      root.querySelectorAll('[data-memi-cancel-booking]').forEach((button) => {
        button.addEventListener('click', async () => {
          if (!root.dataset.cancelUrl) return;
          button.disabled = true;
          try {
            const data = await post(root.dataset.cancelUrl, { booking_id: button.dataset.bookingId || '', reason: '' });
            show(data.message || 'Booking cancelled.', 'success');
            button.closest('li')?.remove();
          } catch (error) {
            show(error.message || 'The booking could not be cancelled.', 'error');
          } finally {
            button.disabled = false;
          }
        });
      });
      root.querySelectorAll('[data-memi-leave-waitlist]').forEach((button) => {
        button.addEventListener('click', async () => {
          if (!root.dataset.leaveWaitlistUrl) return;
          button.disabled = true;
          try {
            const data = await post(root.dataset.leaveWaitlistUrl, { waitlist_id: button.dataset.waitlistId || '' });
            show(data.message || 'Waitlist entry removed.', 'success');
            button.closest('li')?.remove();
          } catch (error) {
            show(error.message || 'The waitlist entry could not be removed.', 'error');
          } finally {
            button.disabled = false;
          }
        });
      });
    });

    document.querySelectorAll('[data-memi-loyalty]').forEach((root) => {
      const endpoint = root.dataset.loyaltyEndpoint;
      const result = root.querySelector('[data-memi-loyalty-result]');
      const show = (message, kind = 'status') => {
        if (result) {
          result.textContent = message;
          result.dataset.status = kind;
        }
      };
      root.querySelectorAll('[data-memi-redeem-reward]').forEach((button) => {
        button.addEventListener('click', async () => {
          if (!endpoint || !button.dataset.rewardId) return;
          button.disabled = true;
          try {
            const data = await post(endpoint, {
              reward_id: button.dataset.rewardId,
              idempotency_key: createKey()
            });
            show(data.message || 'Reward redeemed.', 'success');
          } catch (error) {
            show(error.message || 'The reward could not be redeemed.', 'error');
            button.disabled = false;
          }
        });
      });
    });

    document.querySelectorAll('[data-memi-qr-dashboard]').forEach((root) => {
      const target = root.querySelector('[data-memi-qr-image]');
      const result = root.querySelector('[data-memi-qr-result]');
      const button = root.querySelector('[data-memi-qr-generate]');
      const print = root.querySelector('[data-memi-qr-print]');
      const endpoint = root.dataset.qrEndpoint;
      const show = (message, kind = 'status') => {
        if (result) {
          result.textContent = message;
          result.dataset.status = kind;
        }
      };
      const render = (token) => {
        if (!target || !window.QRCode) {
          show('Le générateur de code QR local est indisponible.', 'error');
          return;
        }
        target.replaceChildren();
        new window.QRCode(target, {
          text: token,
          width: 280,
          height: 280,
          colorDark: '#121212',
          colorLight: '#ffffff',
          correctLevel: window.QRCode.CorrectLevel.M
        });
        target.dataset.tokenAvailable = 'true';
        show('Votre code QR est prêt à être présenté ou imprimé.', 'success');
        if (print) print.disabled = false;
      };
      button?.addEventListener('click', async () => {
        if (!endpoint) return;
        button.disabled = true;
        show('Génération sécurisée du code QR…');
        const body = new FormData();
        body.append('idempotency_key', createKey());
        const csrf = tokenName();
        if (csrf) body.append(csrf, '1');
        try {
          const response = await fetch(endpoint, { method: 'POST', body, credentials: 'same-origin', headers: { Accept: 'application/json' } });
          const payload = await response.json();
          const data = payload && typeof payload.data === 'object' ? { ...payload, ...payload.data } : payload;
          if (!response.ok || !data || !data.success || !data.token) {
            throw new Error((data && data.message) || 'QR generation failed');
          }
          render(data.token);
        } catch (error) {
          show('Le code QR n’a pas pu être généré. Réessayez.', 'error');
        } finally {
          button.disabled = false;
        }
      });
      print?.addEventListener('click', () => {
        if (target && target.dataset.tokenAvailable === 'true') window.print();
      });
    });
  });
})();
