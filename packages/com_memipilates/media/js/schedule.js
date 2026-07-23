/**
 * Interactive schedule controls for com_memipilates.
 *
 * This enhancement deliberately works without an API: server-rendered cards
 * remain usable when JavaScript is disabled, and filtering only toggles cards
 * that are already present in the current Joomla view.
 */
(() => {
  'use strict';

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

  const addDays = (date, amount) => {
    const result = new Date(date.getFullYear(), date.getMonth(), date.getDate());
    result.setDate(result.getDate() + amount);
    return result;
  };

  const addMonths = (date, amount) => {
    const targetMonth = new Date(date.getFullYear(), date.getMonth() + amount, 1);
    const lastDay = new Date(targetMonth.getFullYear(), targetMonth.getMonth() + 1, 0).getDate();
    targetMonth.setDate(Math.min(date.getDate(), lastDay));
    return targetMonth;
  };

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
      this.filterForm = root.querySelector('[data-memi-schedule-filters]');
      this.dateChoices = Array.from(root.querySelectorAll('[data-memi-schedule-date-choice]'));
      this.dateLabel = root.querySelector('[data-memi-schedule-date-label]');
      this.monthLabel = root.querySelector('[data-memi-schedule-month-label]');
      this.calendarWrap = root.querySelector('[data-memi-schedule-calendar-wrap]');
      this.calendarToggle = root.querySelector('[data-memi-schedule-calendar-toggle]');
      this.calendarPanel = root.querySelector('[data-memi-schedule-calendar]');
      this.calendarTitle = root.querySelector('[data-memi-schedule-calendar-title]');
      this.calendarGrid = root.querySelector('[data-memi-schedule-calendar-grid]');
      this.calendarPrevious = root.querySelector('[data-memi-schedule-calendar-prev]');
      this.calendarNext = root.querySelector('[data-memi-schedule-calendar-next]');
      this.calendarToday = root.querySelector('[data-memi-schedule-calendar-today]');
      this.calendarClose = root.querySelector('[data-memi-schedule-calendar-close]');
      this.emptyState = root.querySelector('[data-memi-schedule-empty]');
      this.countNode = root.querySelector('[data-memi-schedule-count]');
      this.liveRegion = root.querySelector('[data-memi-schedule-live]');
      this.state = {
        date: fromIsoDate(root.dataset.date) || new Date(),
        filters: {},
        view: ['day', 'week'].includes(this.options.defaultView) ? this.options.defaultView : 'day'
      };
      this.today = fromIsoDate(root.dataset.today) || new Date();
      this.calendarCursor = new Date(this.state.date.getFullYear(), this.state.date.getMonth(), 1);
      this.calendarFocusDate = new Date(
        this.state.date.getFullYear(),
        this.state.date.getMonth(),
        this.state.date.getDate()
      );

      this.hydrateControlsFromUrl();
      this.bind();
      this.readControls();
      this.apply();
    }

    hydrateControlsFromUrl() {
      const params = new URL(window.location.href).searchParams;

      this.filterControls.forEach((control) => {
        const name = control.dataset.memiScheduleFilter || control.name;
        const value = name ? params.get(name) : null;
        if (value === null) {
          return;
        }

        const selected = value.split(',').map((item) => item.trim()).filter(Boolean);
        if (control.multiple) {
          Array.from(control.options).forEach((option) => {
            option.selected = selected.includes(option.value);
          });
          return;
        }

        const matchingOption = Array.from(control.options).find((option) => selected.includes(option.value));
        if (matchingOption) {
          control.value = matchingOption.value;
        }
      });
    }

    bind() {
      this.filterControls.forEach((control) => {
        control.addEventListener('change', () => {
          this.readControls();
          this.apply();
        });
      });

      if (this.filterForm) {
        this.filterForm.addEventListener('reset', () => {
          window.setTimeout(() => {
            this.readControls();
            this.apply();
          }, 0);
        });
      }

      this.dateChoices.forEach((control) => {
        control.addEventListener('click', (event) => {
          const selected = fromIsoDate(control.dataset.memiScheduleDateChoice);
          if (!selected) {
            return;
          }

          event.preventDefault();
          this.state.date = selected;
          this.state.view = 'day';
          this.apply();
        });
      });

      if (this.calendarToggle && this.calendarPanel && this.calendarGrid) {
        this.calendarToggle.addEventListener('click', () => {
          this.toggleCalendar(this.calendarPanel.hidden);
        });

        this.calendarPrevious && this.calendarPrevious.addEventListener('click', () => {
          this.changeCalendarMonth(-1);
        });

        this.calendarNext && this.calendarNext.addEventListener('click', () => {
          this.changeCalendarMonth(1);
        });

        this.calendarToday && this.calendarToday.addEventListener('click', () => {
          this.selectCalendarDate(this.today);
        });

        this.calendarClose && this.calendarClose.addEventListener('click', () => {
          this.closeCalendar(true);
        });

        this.calendarGrid.addEventListener('click', (event) => {
          const day = event.target instanceof Element
            ? event.target.closest('[data-memi-schedule-calendar-date]')
            : null;
          if (!day || !this.calendarGrid.contains(day)) {
            return;
          }

          const selected = fromIsoDate(day.dataset.memiScheduleCalendarDate);
          if (selected) {
            this.selectCalendarDate(selected);
          }
        });

        this.calendarGrid.addEventListener('keydown', (event) => this.handleCalendarKeydown(event));
        this.calendarPanel.addEventListener('keydown', (event) => {
          if (event.key === 'Escape') {
            event.preventDefault();
            this.closeCalendar(true);
          }
        });

        document.addEventListener('click', (event) => {
          if (!this.calendarPanel.hidden && this.calendarWrap && !this.calendarWrap.contains(event.target)) {
            this.closeCalendar(false);
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

      this.root.querySelectorAll('[data-memi-schedule-range-date]').forEach((control) => {
        control.addEventListener('click', (event) => {
          const date = control.dataset.memiScheduleRangeDate;
          if (!fromIsoDate(date)) {
            return;
          }

          event.preventDefault();
          this.navigateToDate(date);
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

    selectCalendarDate(date) {
      const isoDate = toIsoDate(date);
      const isLoaded = this.dateChoices.some(
        (choice) => choice.dataset.memiScheduleDateChoice === isoDate
      );

      this.closeCalendar(false);
      if (!isLoaded) {
        this.navigateToDate(isoDate);
        return;
      }

      this.state.date = date;
      this.state.view = 'day';
      this.apply();
      this.calendarToggle && this.calendarToggle.focus();
    }

    toggleCalendar(open) {
      if (open) {
        this.openCalendar();
      } else {
        this.closeCalendar(true);
      }
    }

    openCalendar() {
      if (!this.calendarPanel || !this.calendarToggle) {
        return;
      }

      this.calendarCursor = new Date(this.state.date.getFullYear(), this.state.date.getMonth(), 1);
      this.calendarFocusDate = new Date(
        this.state.date.getFullYear(),
        this.state.date.getMonth(),
        this.state.date.getDate()
      );
      this.calendarPanel.hidden = false;
      this.calendarToggle.setAttribute('aria-expanded', 'true');
      this.renderCalendar();
      window.requestAnimationFrame(() => this.focusCalendarDate(this.calendarFocusDate));
    }

    closeCalendar(restoreFocus) {
      if (!this.calendarPanel || !this.calendarToggle) {
        return;
      }

      this.calendarPanel.hidden = true;
      this.calendarToggle.setAttribute('aria-expanded', 'false');
      if (restoreFocus) {
        this.calendarToggle.focus();
      }
    }

    changeCalendarMonth(amount) {
      this.calendarFocusDate = addMonths(this.calendarFocusDate, amount);
      this.calendarCursor = new Date(
        this.calendarFocusDate.getFullYear(),
        this.calendarFocusDate.getMonth(),
        1
      );
      this.renderCalendar();
      window.requestAnimationFrame(() => this.focusCalendarDate(this.calendarFocusDate));
    }

    focusCalendarDate(date) {
      if (!this.calendarGrid) {
        return;
      }

      const isoDate = toIsoDate(date);
      const target = Array.from(this.calendarGrid.querySelectorAll('[data-memi-schedule-calendar-date]'))
        .find((day) => day.dataset.memiScheduleCalendarDate === isoDate);
      if (target) {
        target.focus();
      }
    }

    moveCalendarFocus(date) {
      this.calendarFocusDate = date;
      if (
        date.getFullYear() !== this.calendarCursor.getFullYear()
        || date.getMonth() !== this.calendarCursor.getMonth()
      ) {
        this.calendarCursor = new Date(date.getFullYear(), date.getMonth(), 1);
        this.renderCalendar();
      }
      window.requestAnimationFrame(() => this.focusCalendarDate(date));
    }

    handleCalendarKeydown(event) {
      const day = event.target instanceof Element
        ? event.target.closest('[data-memi-schedule-calendar-date]')
        : null;
      if (!day) {
        return;
      }

      const focused = fromIsoDate(day.dataset.memiScheduleCalendarDate);
      if (!focused) {
        return;
      }

      let target = null;
      if (event.key === 'ArrowLeft') {
        target = addDays(focused, -1);
      } else if (event.key === 'ArrowRight') {
        target = addDays(focused, 1);
      } else if (event.key === 'ArrowUp') {
        target = addDays(focused, -7);
      } else if (event.key === 'ArrowDown') {
        target = addDays(focused, 7);
      } else if (event.key === 'Home') {
        target = addDays(focused, -((focused.getDay() + 6) % 7));
      } else if (event.key === 'End') {
        target = addDays(focused, 6 - ((focused.getDay() + 6) % 7));
      } else if (event.key === 'PageUp') {
        target = addMonths(focused, event.shiftKey ? -12 : -1);
      } else if (event.key === 'PageDown') {
        target = addMonths(focused, event.shiftKey ? 12 : 1);
      } else if (event.key === 'Escape') {
        event.preventDefault();
        this.closeCalendar(true);
        return;
      }

      if (target) {
        event.preventDefault();
        this.moveCalendarFocus(target);
      }
    }

    renderCalendar() {
      if (!this.calendarGrid || !this.calendarTitle) {
        return;
      }

      const titleFormatter = new Intl.DateTimeFormat(this.options.locale, {
        month: 'long',
        year: 'numeric'
      });
      const dayFormatter = new Intl.DateTimeFormat(this.options.locale, {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        year: 'numeric'
      });
      const monthStart = new Date(
        this.calendarCursor.getFullYear(),
        this.calendarCursor.getMonth(),
        1
      );
      const gridStart = addDays(monthStart, -((monthStart.getDay() + 6) % 7));
      const selectedIso = toIsoDate(this.state.date);
      const todayIso = toIsoDate(this.today);
      const focusIso = toIsoDate(this.calendarFocusDate);
      const days = [];

      this.calendarTitle.textContent = titleFormatter.format(monthStart);
      for (let offset = 0; offset < 42; offset += 1) {
        const date = addDays(gridStart, offset);
        const isoDate = toIsoDate(date);
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'memi-schedule__calendar-day';
        button.dataset.memiScheduleCalendarDate = isoDate;
        button.textContent = String(date.getDate());
        button.setAttribute('role', 'gridcell');
        button.setAttribute('aria-label', dayFormatter.format(date));
        button.setAttribute('aria-selected', String(isoDate === selectedIso));
        button.tabIndex = isoDate === focusIso ? 0 : -1;
        button.classList.toggle('is-outside', date.getMonth() !== monthStart.getMonth());
        button.classList.toggle('is-selected', isoDate === selectedIso);
        button.classList.toggle('is-today', isoDate === todayIso);
        if (isoDate === todayIso) {
          button.setAttribute('aria-current', 'date');
        }
        days.push(button);
      }

      this.calendarGrid.replaceChildren(...days);
    }

    navigateToDate(date) {
      const url = new URL(window.location.href);
      url.searchParams.set('date', date);
      url.searchParams.set('mode', 'week');
      url.searchParams.delete('view');
      Object.entries(this.state.filters).forEach(([name, values]) => {
        if (values.length) {
          url.searchParams.set(name, values.join(','));
        } else {
          url.searchParams.delete(name);
        }
      });
      window.location.assign(url.toString());
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
      if (this.monthLabel) {
        const monthFormatter = new Intl.DateTimeFormat(this.options.locale, { month: 'long' });
        this.monthLabel.textContent = monthFormatter.format(this.state.date);
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

      const selectedDate = toIsoDate(this.state.date);
      this.dateChoices.forEach((control) => {
        const active = control.dataset.memiScheduleDateChoice === selectedDate;
        control.classList.toggle('is-active', active);
        if (active) {
          control.setAttribute('aria-current', 'date');
        } else {
          control.removeAttribute('aria-current');
        }
      });

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
      url.searchParams.set('mode', 'week');
      url.searchParams.delete('view');
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
      const visibleMessage = translate(
        this.options,
        'COM_MEMIPILATES_SCHEDULE_VISIBLE_COUNT',
        '%COUNT% cours affiché(s)',
        { COUNT: visibleCount }
      ).replace('%s', String(visibleCount));
      if (this.countNode) {
        this.countNode.textContent = visibleMessage;
      }
      if (this.liveRegion) {
        this.liveRegion.textContent = visibleMessage;
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
