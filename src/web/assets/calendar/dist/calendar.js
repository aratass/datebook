/**
 * Datebook control panel scripts: the calendar page, the feed link and the
 * scheduling panel in the draft editor sidebar.
 */
(function (window, document) {
  'use strict';

  const Datebook = (window.Datebook = window.Datebook || {});

  function t(message, params) {
    return Craft.t('datebook', message, params);
  }

  function post(action, data) {
    return Craft.sendActionRequest('POST', action, { data: data });
  }

  function errorMessage(error, fallback) {
    const data = error && error.response && error.response.data;
    return (data && (data.message || data.error)) || fallback;
  }

  function el(tag, attrs, children) {
    const node = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (key) {
      if (key === 'text') {
        node.textContent = attrs[key];
      } else if (key === 'className') {
        node.className = attrs[key];
      } else {
        node.setAttribute(key, attrs[key]);
      }
    });
    (children || []).forEach(function (child) {
      if (child) {
        node.appendChild(child);
      }
    });
    return node;
  }

  function htmlToElement(html) {
    const template = document.createElement('template');
    template.innerHTML = String(html || '').trim();
    return template.content.firstElementChild;
  }

  /**
   * The current date and time in the time zone as `Y-m-d\TH:i`, the format of
   * datetime-local inputs, so the two compare as text. Null if the browser cannot tell.
   */
  function nowIn(timeZone) {
    try {
      const parts = {};
      new Intl.DateTimeFormat('en-US', {
        timeZone: timeZone,
        hourCycle: 'h23',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
      }).formatToParts(new Date()).forEach((part) => {
        parts[part.type] = part.value;
      });
      return parts.year + '-' + parts.month + '-' + parts.day + 'T' + parts.hour + ':' + parts.minute;
    } catch (e) {
      return null;
    }
  }

  function copyText(input) {
    const value = input.value;
    if (navigator.clipboard && window.isSecureContext) {
      return navigator.clipboard.writeText(value);
    }
    input.focus();
    input.select();
    document.execCommand('copy');
    return Promise.resolve();
  }

  /**
   * The calendar page.
   */
  Datebook.Calendar = class {
    constructor(config) {
      this.config = config || {};
      this.root = document.querySelector('.datebook');
      this.dragged = null;

      if (!this.root) {
        return;
      }

      this.initFilters();
      this.initMore();
      this.initDragAndDrop();
      this.initDetails();
      this.initSubscribe();
      this.root.querySelectorAll('.datebook-day').forEach((cell) => this.updateMore(cell));
    }

    initFilters() {
      const form = document.querySelector('.datebook-filters');
      if (!form) {
        return;
      }
      form.addEventListener('change', () => form.submit());
      const submit = form.querySelector('.datebook-filters__submit');
      if (submit) {
        submit.classList.add('hidden');
      }
    }

    initMore() {
      this.root.addEventListener('click', (event) => {
        const button = event.target.closest('.datebook-more');
        if (!button) {
          return;
        }
        const cell = button.closest('.datebook-day');
        cell.classList.toggle('is-expanded');
        this.updateMore(cell);
      });
    }

    updateMore(cell) {
      const button = cell.querySelector('.datebook-more');
      if (!button) {
        return;
      }
      const perDay = parseInt(this.root.dataset.perDay || '4', 10);
      const count = cell.querySelectorAll('.datebook-item').length;
      const expanded = cell.classList.contains('is-expanded');
      button.classList.toggle('hidden', count <= perDay);
      button.setAttribute('aria-expanded', expanded ? 'true' : 'false');
      button.textContent = expanded
        ? t('Show less')
        : t('{num} more', { num: count - perDay });
    }

    initDragAndDrop() {
      this.root.addEventListener('dragstart', (event) => {
        const item = event.target.closest && event.target.closest('.datebook-item.is-movable');
        if (!item) {
          return;
        }
        this.dragged = item;
        item.classList.add('is-dragging');
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', item.dataset.key);
      });

      this.root.addEventListener('dragend', () => {
        if (this.dragged) {
          this.dragged.classList.remove('is-dragging');
        }
        this.dragged = null;
        this.clearDropTargets();
      });

      this.root.addEventListener('dragover', (event) => {
        if (!this.dragged) {
          return;
        }
        const cell = event.target.closest('[data-day]');
        if (!cell) {
          return;
        }
        event.preventDefault();
        event.dataTransfer.dropEffect = 'move';
        if (!cell.classList.contains('is-drop-target')) {
          this.clearDropTargets();
          cell.classList.add('is-drop-target');
        }
      });

      this.root.addEventListener('drop', (event) => {
        if (!this.dragged) {
          return;
        }
        const cell = event.target.closest('[data-day]');
        if (!cell) {
          return;
        }
        event.preventDefault();
        this.clearDropTargets();

        const item = this.dragged;
        this.dragged = null;
        item.classList.remove('is-dragging');

        // The item keeps its time of day on the new day.
        const day = cell.dataset.day;
        if (day === item.dataset.day || !this.confirmMove(item, day + item.dataset.localDateTime.slice(10))) {
          return;
        }

        // Errors are shown to the user in reschedule(), so nothing is left to handle here.
        this.reschedule(item, { day: day }, cell).catch(() => {});
      });
    }

    clearDropTargets() {
      this.root.querySelectorAll('.is-drop-target').forEach((cell) => cell.classList.remove('is-drop-target'));
    }

    /**
     * Asks before a move hides a live entry or makes an entry live right away.
     * `target` is the new date and time as `Y-m-d\TH:i` in the calendar's time zone.
     */
    confirmMove(item, target) {
      const now = nowIn(this.config.timeZone) || this.config.now;
      const kind = item.dataset.kind;
      const status = item.dataset.status;

      if (kind === 'post' && status === 'live' && target > now) {
        return window.confirm(t('This entry is live. Moving its post date into the future hides it until then. Continue?'));
      }
      if (kind === 'post' && status === 'pending' && target <= now) {
        return window.confirm(t('This moves the post date into the past, so the entry goes live now if it is enabled. Continue?'));
      }
      if (kind === 'expiry' && status === 'live' && target <= now) {
        return window.confirm(t('This moves the expiry date into the past, so the entry is hidden from your site right away. Continue?'));
      }
      if (kind === 'expiry' && status === 'expired' && target > now) {
        return window.confirm(t('This moves the expiry date into the future, so the entry goes live again if it is enabled. Continue?'));
      }
      return true;
    }

    /**
     * Moves an item. `change` is either {day: 'Y-m-d'} or {dateTime: 'Y-m-d\\TH:i'}.
     */
    reschedule(item, change, targetCell) {
      const originalParent = item.parentNode;
      const originalNext = item.nextSibling;
      const originalCell = item.closest('.datebook-day');

      if (targetCell) {
        const list = targetCell.querySelector('.datebook-day__items');
        if (list) {
          list.appendChild(item);
          this.updateMore(targetCell);
        }
      }
      item.classList.add('is-saving');

      const data = Object.assign({
        kind: item.dataset.kind,
        elementId: item.dataset.elementId,
        siteId: item.dataset.siteId,
        layout: this.root.dataset.view,
      }, change);

      return post('datebook/calendar/reschedule', data)
        .then((response) => {
          const result = response.data;
          const replacement = htmlToElement(result.html);
          const cell = this.root.querySelector('[data-day="' + result.item.localDate + '"]');
          item.remove();

          if (cell && replacement) {
            this.insertSorted(cell.querySelector('.datebook-day__items'), replacement);
            this.updateMore(cell);
          }
          if (originalCell) {
            this.updateMore(originalCell);
          }
          if (targetCell) {
            this.updateMore(targetCell);
          }
          Craft.cp.displaySuccess(result.message);
          return result;
        })
        .catch((error) => {
          item.classList.remove('is-saving');
          if (originalParent) {
            originalParent.insertBefore(item, originalNext);
          }
          if (originalCell) {
            this.updateMore(originalCell);
          }
          if (targetCell) {
            this.updateMore(targetCell);
          }
          Craft.cp.displayError(errorMessage(error, t('Could not reschedule this item.')));
          throw error;
        });
    }

    insertSorted(list, element) {
      if (!list) {
        return;
      }
      const sortKey = element.dataset.sort || '';
      const next = Array.prototype.find.call(list.children, (child) => (child.dataset.sort || '') > sortKey);
      list.insertBefore(element, next || null);
    }

    initDetails() {
      this.root.addEventListener('click', (event) => {
        const button = event.target.closest('.datebook-item__menu');
        if (!button) {
          return;
        }
        event.preventDefault();
        this.openDetails(button.closest('.datebook-item'), button);
      });
    }

    openDetails(item, trigger) {
      const data = item.dataset;
      const rows = [
        [t('Status'), data.statusLabel],
        [t('Section'), data.section],
        [t('Author'), data.author],
      ].filter((row) => row[1]);

      const list = el('dl', { className: 'datebook-hud__meta' });
      rows.forEach((row) => {
        list.appendChild(el('dt', { text: row[0] }));
        list.appendChild(el('dd', { text: row[1] }));
      });

      const body = el('div', { className: 'datebook-hud' }, [
        el('p', { className: 'datebook-hud__kind', text: data.kindLabel }),
        el('h2', { className: 'datebook-hud__title', text: data.title }),
        list,
      ]);

      if (data.error) {
        body.appendChild(el('p', { className: 'error', text: data.error }));
      }

      let input = null;
      let save = null;
      if (item.classList.contains('is-movable')) {
        const inputId = 'datebook-hud-input-' + Date.now();
        input = el('input', { type: 'datetime-local', id: inputId, className: 'text fullwidth', value: data.localDateTime });
        save = el('button', { type: 'button', className: 'btn submit', text: t('Save') });
        body.appendChild(el('div', { className: 'field' }, [
          el('div', { className: 'heading' }, [el('label', { for: inputId, text: t('Date and time') })]),
          el('div', { className: 'input' }, [input]),
          el('p', { className: 'datebook-hud__tz', text: t('Time zone: {timeZone}', { timeZone: this.config.timeZone }) }),
        ]));
      }

      const buttons = el('div', { className: 'flex datebook-hud__buttons' }, [
        save,
        data.url ? el('a', { className: 'btn', href: data.url, text: t('Open') }) : null,
      ]);
      body.appendChild(buttons);

      const hud = new Garnish.HUD(jQuery(trigger), jQuery(body), { hudClass: 'hud datebook-hud-container' });

      if (save && input) {
        const submit = () => {
          if (!input.value || !this.confirmMove(item, input.value.slice(0, 16))) {
            return;
          }
          save.classList.add('loading');
          this.reschedule(item, { dateTime: input.value }, null)
            .then(() => hud.hide())
            .catch(() => save.classList.remove('loading'));
        };
        save.addEventListener('click', submit);
        input.addEventListener('keydown', (event) => {
          if (event.key === 'Enter') {
            event.preventDefault();
            submit();
          }
        });
      }
    }

    initSubscribe() {
      const button = document.getElementById('datebook-subscribe');
      if (!button) {
        return;
      }
      button.addEventListener('click', () => {
        button.classList.add('loading');
        post('datebook/feed/url', {})
          .then((response) => this.showFeed(button, response.data.url))
          .catch((error) => Craft.cp.displayError(errorMessage(error, t('Could not load your calendar link.'))))
          .finally(() => button.classList.remove('loading'));
      });
    }

    showFeed(button, url) {
      const input = el('input', { type: 'text', className: 'text fullwidth code', readonly: 'readonly', value: url, 'aria-label': t('Your private calendar link') });
      const copy = el('button', { type: 'button', className: 'btn submit', text: t('Copy') });
      const reset = el('button', { type: 'button', className: 'btn', text: t('Reset link') });

      const body = el('div', { className: 'datebook-hud datebook-hud--feed' }, [
        el('h2', { className: 'datebook-hud__title', text: t('Your private calendar link') }),
        el('p', { text: t('Paste this link into Google Calendar, Outlook or Apple Calendar as a calendar subscription.') }),
        input,
        el('p', { className: 'light datebook-hud__note', text: t('Anyone with this link can see your calendar. Reset it if you shared it by mistake.') }),
        el('div', { className: 'flex datebook-hud__buttons' }, [copy, reset]),
      ]);

      new Garnish.HUD(jQuery(button), jQuery(body), { hudClass: 'hud datebook-hud-container' });

      copy.addEventListener('click', () => {
        copyText(input).then(() => Craft.cp.displaySuccess(t('Copied.')));
      });

      reset.addEventListener('click', () => {
        if (!window.confirm(t('Reset your calendar link? The old link stops working right away.'))) {
          return;
        }
        post('datebook/feed/reset', {})
          .then((response) => {
            input.value = response.data.url;
            Craft.cp.displaySuccess(response.data.message);
          })
          .catch((error) => Craft.cp.displayError(errorMessage(error, t('Could not reset your calendar link.'))));
      });
    }
  };

  /**
   * The "Publish this draft later" panel in the draft editor sidebar.
   */
  Datebook.SchedulePanel = class {
    constructor(id) {
      this.root = document.getElementById(id);
      if (!this.root) {
        return;
      }

      this.input = this.root.querySelector('.datebook-schedule__input');
      this.status = this.root.querySelector('.datebook-schedule__status');
      this.saveButton = this.root.querySelector('.datebook-schedule__save');
      this.cancelButton = this.root.querySelector('.datebook-schedule__cancel');

      if (this.saveButton) {
        this.saveButton.addEventListener('click', (event) => {
          event.preventDefault();
          this.save();
        });
      }
      if (this.cancelButton) {
        this.cancelButton.addEventListener('click', (event) => {
          event.preventDefault();
          this.cancel();
        });
      }
      if (this.input) {
        // Keep Enter from submitting the entry form.
        this.input.addEventListener('keydown', (event) => {
          if (event.key === 'Enter') {
            event.preventDefault();
            this.save();
          }
        });
      }
    }

    payload(extra) {
      return Object.assign({
        draftId: this.root.dataset.draftId,
        siteId: this.root.dataset.siteId,
      }, extra || {});
    }

    setStatus(text, isError) {
      if (!this.status) {
        return;
      }
      this.status.textContent = text;
      this.status.classList.toggle('error', !!isError);
    }

    busy(on) {
      [this.saveButton, this.cancelButton].forEach((button) => {
        if (button) {
          button.classList.toggle('loading', on && button === this.activeButton);
          button.disabled = on;
        }
      });
    }

    save() {
      if (!this.input || !this.input.value) {
        Craft.cp.displayError(t('Enter a date and time.'));
        return;
      }
      this.activeButton = this.saveButton;
      this.busy(true);
      post('datebook/schedules/save', this.payload({ publishAt: this.input.value }))
        .then((response) => {
          this.setStatus(response.data.statusText, false);
          this.saveButton.textContent = t('Update schedule');
          if (this.cancelButton) {
            this.cancelButton.classList.remove('hidden');
          }
          Craft.cp.displaySuccess(response.data.message);
        })
        .catch((error) => Craft.cp.displayError(errorMessage(error, t('Could not save the schedule.'))))
        .finally(() => this.busy(false));
    }

    cancel() {
      this.activeButton = this.cancelButton;
      this.busy(true);
      post('datebook/schedules/cancel', this.payload())
        .then((response) => {
          this.setStatus(response.data.statusText, false);
          if (this.saveButton) {
            this.saveButton.textContent = t('Schedule');
          }
          this.cancelButton.classList.add('hidden');
          if (this.input) {
            this.input.value = '';
          }
          Craft.cp.displaySuccess(response.data.message);
        })
        .catch((error) => Craft.cp.displayError(errorMessage(error, t('Could not save the schedule.'))))
        .finally(() => this.busy(false));
    }
  };
})(window, document);
