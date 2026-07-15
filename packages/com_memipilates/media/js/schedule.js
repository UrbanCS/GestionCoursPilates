/**
 * Interactive schedule controls for com_memipilates.
 *
 * This enhancement deliberately works without an API: server-rendered cards
 * remain usable when JavaScript is disabled, and filtering only toggles cards
 * that are already present in the current Joomla view.
 */
(() => {
  'use strict';

  const DAY_MS = 24 * 60 * 60 * 1000;
  const filterAliases = {
    type: 'courseType',
    course: 'courseType',
    course_type: 'courseType',
    instructor: 'instructor',
    level: 'level',
    location: 'location',
    room: 'room',
    period: 'period'
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

  const bool = (value, fallback = false) => {
    if (value === undefined || value === null || value === '') {
      return fallback;
    }

    return !['0', 'false', 'no', 'off'].includes(String(value).toLowerCase());
  };

  const normalize = (value) => String(value || '')
    .trim()
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLocaleLowerCase();

  const toIsoDate = (date) => [
    date.getFullYear(),
    String(date.getMonth() + 1).padStart(2, '0'),
    String(date.getDate()).padStart(2, '0')
  ].join('-');

  const fromIsoDate = (value) => {
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(value || ''));
    if (!match) {
      return null;
    }

    const date = new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]));
    return Number.isNaN(date.getTime()) ? null : date;
  };

  const addDays = (date, amount) => new Date(date.getTime() + (amount * DAY_MS));

  const startOfWeek = (date) => {
    const result = new Date(date.getFullYear(), date.getMonth(), date.getDate());
    result.setDate(result.getDate() - ((result.getDay() + 6) % 7));
    return result;
  };

  const kebabCase = (value) => String(value).replace(/[A-Z]/g, (letter) => `-${letter.toLowerCase()}`);

  const translate = (options, key, fallback, replacements = {}) => {
    const messages = options.messages || {};
    let text = messages[key] || messages[key.toLowerCase()] || '';
    if (!text && window.Joomla && window.Joomla.Text && typeof window.Joomla.Text._ === 'function') {
      try {
        const translated = window.Joomla.Text._(key);
        text = translated && translated !== key ? translated : '';
      } catch (error) {
        text = '';
      }
    }
    return Object.entries(replacements).reduce(
      (result, [name, value]) => result.replace(new RegExp(`%${name}%`, 'g'), String(value)),
      String(text || fallback)
    );
  };

  class ScheduleController {
    constructor(root) {
      this.root = root;
      this.options = {
        ...getJoomlaOptions('com_memipilates.schedule'),
        locale: root.dataset.locale || document.documentElement.lang || 'fr-CA',
        defaultView: root.dataset.defaultView || 'day',
        urlSync: bool(root.dataset.urlSync, false)
      };

      this.cards = Array.from(root.querySelectorAll('[data-memi-schedule-card], [data-memi-session-card]'));
      this.filterControls = Array.from(root.querySelectorAll('[data-memi-schedule-filter]'));
      this.dateControl = root.querySelector('[data-memi-schedule-date]');
      this.dateLabel = root.querySelector('[data-memi-schedule-date-label]');
      this.emptyState = root.querySelector('[data-memi-schedule-empty]');
      this.countNode = root.querySelector('[data-memi-schedule-count]');
      this.liveRegion = root.querySelector('[data-memi-schedule-live]');
      this.state = {
        date: fromIsoDate(root.dataset.date) || fromIsoDate(this.dateControl && this.dateControl.value) || new Date(),
        filters: {},
        view: ['day', 'week'].includes(this.options.defaultView) ? this.options.defaultView : 'day'
      };

      this.bind();
      this.readControls();
      this.apply();
    }

    bind() {
      this.filterControls.forEach((control) => {
        control.addEventListener('change', () => {
          this.readControls();
          this.apply();
        });
      });

      if (this.dateControl) {
        this.dateControl.addEventListener('change', () => {
          const selected = fromIsoDate(this.dateControl.value);
          if (selected) {
            this.state.date = selected;
            this.apply();
          }
        });
      }

      this.root.querySelectorAll('[data-memi-schedule-date-prev]').forEach((control) => {
        control.addEventListener('click', () => this.shiftDate(-1));
      });

      this.root.querySelectorAll('[data-memi-schedule-date-next]').forEach((control) => {
        control.addEventListener('click', () => this.shiftDate(1));
      });

      this.root.querySelectorAll('[data-memi-schedule-date-today]').forEach((control) => {
        control.addEventListener('click', () => {
          this.state.date = new Date();
          this.apply();
        });
      });

      this.root.querySelectorAll('[data-memi-schedule-view]').forEach((control) => {
        control.addEventListener('click', () => {
          const view = control.dataset.memiScheduleView;
          if (!['day', 'week'].includes(view)) {
            return;
          }

          this.state.view = view;
          this.apply();
        });
      });
    }

    readControls() {
      this.filterControls.forEach((control) => {
        const name = control.dataset.memiScheduleFilter || control.name;
        if (!name) {
          return;
        }

        const values = control.multiple
          ? Array.from(control.selectedOptions).map((option) => normalize(option.value)).filter(Boolean)
          : [normalize(control.value)].filter(Boolean);

        this.state.filters[name] = values;
      });
    }

    shiftDate(direction) {
      this.state.date = addDays(this.state.date, direction * (this.state.view === 'week' ? 7 : 1));
      this.apply();
    }

    cardValue(card, filterName) {
      const camelName = filterAliases[filterName] || filterName.replace(/[-_](.)/g, (_, letter) => letter.toUpperCase());
      const attributeNames = [
        `data-${kebabCase(camelName)}`,
        `data-memi-${kebabCase(camelName)}`
      ];

      for (const name of attributeNames) {
        const value = card.getAttribute(name);
        if (value !== null) {
          return value;
        }
      }

      return '';
    }

    cardDate(card) {
      return this.cardValue(card, 'sessionDate')
        || this.cardValue(card, 'start')
        || this.cardValue(card, 'date')
        || '';
    }

    matchesDate(card) {
      const value = this.cardDate(card).slice(0, 10);
      if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) {
        return true;
      }

      const current = toIsoDate(this.state.date);
      if (this.state.view === 'day') {
        return value === current;
      }

      const start = toIsoDate(startOfWeek(this.state.date));
      const end = toIsoDate(addDays(startOfWeek(this.state.date), 6));
      return value >= start && value <= end;
    }

    matchesFilters(card) {
      return Object.entries(this.state.filters).every(([name, selected]) => {
        if (!selected.length) {
          return true;
        }

        const values = normalize(this.cardValue(card, name)).split(',').map((value) => value.trim()).filter(Boolean);
        return selected.some((value) => values.includes(value));
      });
    }

    updateDateUi() {
      if (this.dateControl) {
        this.dateControl.value = toIsoDate(this.state.date);
      }

      if (this.dateLabel) {
        const formatter = new Intl.DateTimeFormat(this.options.locale, this.state.view === 'week'
          ? { day: 'numeric', month: 'long', year: 'numeric' }
          : { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });

        if (this.state.view === 'week') {
          const weekStart = startOfWeek(this.state.date);
          const weekEnd = addDays(weekStart, 6);
          this.dateLabel.textContent = `${formatter.format(weekStart)} – ${formatter.format(weekEnd)}`;
        } else {
          this.dateLabel.textContent = formatter.format(this.state.date);
        }
      }

      this.root.querySelectorAll('[data-memi-schedule-view]').forEach((control) => {
        const active = control.dataset.memiScheduleView === this.state.view;
        control.classList.toggle('is-active', active);
        control.setAttribute('aria-pressed', String(active));
      });
    }

    syncUrl() {
      if (!this.options.urlSync || !window.history || !window.history.replaceState) {
        return;
      }

      const url = new URL(window.location.href);
      url.searchParams.set('date', toIsoDate(this.state.date));
      url.searchParams.set('view', this.state.view);
      Object.entries(this.state.filters).forEach(([name, values]) => {
        if (values.length) {
          url.searchParams.set(name, values.join(','));
        } else {
          url.searchParams.delete(name);
        }
      });
      window.history.replaceState({}, '', url);
    }

    apply() {
      this.updateDateUi();
      let visibleCount = 0;

      this.cards.forEach((card) => {
        const visible = this.matchesDate(card) && this.matchesFilters(card);
        card.hidden = !visible;
        card.setAttribute('aria-hidden', String(!visible));
        if (visible) {
          visibleCount += 1;
        }
      });

      if (this.emptyState) {
        this.emptyState.hidden = visibleCount !== 0;
      }
      if (this.countNode) {
        this.countNode.textContent = String(visibleCount);
      }
      if (this.liveRegion) {
        this.liveRegion.textContent = translate(
          this.options,
          'COM_MEMIPILATES_SCHEDULE_VISIBLE_COUNT',
          '%COUNT% cours affiché(s)',
          { COUNT: visibleCount }
        );
      }

      this.syncUrl();
      this.root.dispatchEvent(new CustomEvent('memi:schedule-change', {
        bubbles: true,
        detail: {
          date: toIsoDate(this.state.date),
          filters: { ...this.state.filters },
          view: this.state.view,
          visibleCount
        }
      }));
    }
  }

  const initialise = (root) => {
    if (!root || root.dataset.memiScheduleInitialised === 'true') {
      return root && root.memiSchedule;
    }

    const controller = new ScheduleController(root);
    root.dataset.memiScheduleInitialised = 'true';
    root.memiSchedule = controller;
    return controller;
  };

  const initialiseAll = () => document.querySelectorAll('[data-memi-schedule]').forEach(initialise);

  window.MemiPilatesSchedule = { initialise, initialiseAll };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initialiseAll, { once: true });
  } else {
    initialiseAll();
  }
})();
