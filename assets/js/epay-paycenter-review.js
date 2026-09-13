(function () {
  'use strict';
  if (!window.epayPaycenterReview) return;
  const config = window.epayPaycenterReview;
  const strings = config.strings;
  document.documentElement.classList.add('epay-review-js');
  let saving = false;

  /** @param {unknown} value @returns {value is EpayReviewResponse} */
  function isReviewResponse(value) {
    if (!value || typeof value !== 'object' || !('success' in value) || value.success !== true || !('data' in value)) return false;
    const data = value.data;
    if (!data || typeof data !== 'object' || !('saved' in data) || !Array.isArray(data.saved) || !data.saved.every(key => typeof key === 'string')) return false;
    if (!('errors' in data) || !data.errors || typeof data.errors !== 'object' || !Object.values(data.errors).every(message => typeof message === 'string')) return false;
    if (!('counts' in data) || (data.counts !== null && (typeof data.counts !== 'object' || Array.isArray(data.counts) || !Object.values(data.counts).every(count => Number.isInteger(count) && count >= 0)))) return false;
    return 'total' in data && (data.total === null || (typeof data.total === 'number' && Number.isInteger(data.total) && data.total >= 0));
  }

  /** @param {HTMLElement} scope @param {string} message */
  function announce(scope, message) {
    const feedback = scope.querySelector('.epay-review-feedback');
    if (feedback) feedback.textContent = message;
  }

  /** @param {HTMLElement} scope @param {string[]} keys @param {boolean} bulk @param {HTMLButtonElement} trigger */
  async function review(scope, keys, bulk, trigger) {
    if (saving) return;
    if (!keys.length) { announce(scope, strings.select); return; }
    saving = true;
    const body = new URLSearchParams({ action: 'epay_paycenter_review', _wpnonce: config.nonce });
    if (bulk) {
      body.set('bulk_action', 'review');
      keys.forEach(key => body.append('review_keys[]', key));
    } else body.set('review_key', keys[0]);
    for (const name of ['review_group', 's']) {
      const input = scope.querySelector(`input[name="${name}"]`);
      if (input instanceof HTMLInputElement) body.set(name, input.value);
    }
    const controls = Array.from(scope.querySelectorAll('button, input[type="checkbox"], select'))
      .filter(element => element instanceof HTMLButtonElement || element instanceof HTMLInputElement || element instanceof HTMLSelectElement);
    const disabled = controls.map(control => control.disabled);
    const label = trigger.textContent;
    const scroll = window.scrollY;
    const reviewButtons = Array.from(scope.querySelectorAll('.epay-mark-reviewed'));
    const position = reviewButtons.indexOf(trigger);
    const focusCandidates = reviewButtons.slice(position + 1).concat(reviewButtons.slice(0, position).reverse());
    controls.forEach(control => { control.disabled = true; });
    trigger.textContent = strings.saving;
    scope.setAttribute('aria-busy', 'true');
    announce(scope, strings.saving);
    scope.querySelectorAll('.epay-review-error').forEach(error => { error.textContent = ''; });
    const controller = new AbortController();
    const timeout = window.setTimeout(() => controller.abort(), 20000);
    try {
      const response = await fetch(config.ajaxUrl, { method: 'POST', body, credentials: 'same-origin', signal: controller.signal });
      if (!response.ok) throw new Error('Review request failed');
      const result = await response.json();
      if (!isReviewResponse(result)) throw new Error('Invalid review response');
      const data = result.data;
      scope.querySelectorAll('.epay-review-row').forEach(row => {
        if (!(row instanceof HTMLElement)) return;
        const key = row.dataset.reviewKey || '';
        if (data.saved.includes(key)) row.remove();
        else if (data.errors[key]) {
          const error = row.querySelector('.epay-review-error');
          if (error) error.textContent = data.errors[key];
        }
      });
      document.querySelectorAll('[data-epay-count]').forEach(counter => {
        if (counter instanceof HTMLElement) counter.textContent = data.counts === null ? strings.unavailable : String(data.counts[counter.dataset.epayCount || 'all']);
      });
      document.querySelectorAll('.epay-review-count').forEach(counter => {
        counter.textContent = data.counts === null ? strings.countsFailed : data.counts.all ? strings.unreviewed.replace('%d', String(data.counts.all)) : strings.none;
      });
      document.querySelectorAll('[data-review-option]').forEach(option => {
        if (option instanceof HTMLOptionElement) option.textContent = `${option.dataset.label} (${data.counts === null ? strings.unavailable : data.counts[option.value]})`;
      });
      if (data.total !== null) {
        scope.querySelectorAll('[data-review-total]').forEach(counter => { counter.textContent = String(data.total); });
        const pages = Math.max(1, Math.ceil(data.total / Number(scope.dataset.pageSize || 20)));
        scope.querySelectorAll('[data-review-pages]').forEach(counter => { counter.textContent = String(pages); });
        const pageNumber = scope.querySelector('.paging-input');
        if (pageNumber instanceof HTMLElement) pageNumber.hidden = Number(scope.dataset.reviewPage) > pages;
        const next = scope.querySelector('.epay-review-next');
        if (next instanceof HTMLElement) next.hidden = Number(scope.dataset.reviewPage) >= pages;
      }
      const empty = scope.querySelector('.epay-review-empty');
      if (empty instanceof HTMLElement) empty.hidden = !!scope.querySelector('.epay-review-row');
      announce(scope, data.counts === null ? strings.countsFailed : Object.keys(data.errors).length ? strings.partial : strings.saved);
    } catch {
      // A network, session or malformed-response failure cannot acknowledge a row locally.
      announce(scope, strings.failed);
      keys.forEach(key => {
        scope.querySelectorAll('.epay-review-row').forEach(row => {
          if (!(row instanceof HTMLElement) || row.dataset.reviewKey !== key) return;
          const error = row.querySelector('.epay-review-error');
          if (error) error.textContent = strings.failed;
        });
      });
    } finally {
      window.clearTimeout(timeout);
      controls.forEach((control, index) => { control.disabled = disabled[index]; });
      trigger.textContent = label;
      scope.removeAttribute('aria-busy');
      saving = false;
      const selectAll = scope.querySelector('.epay-review-select-all');
      if (selectAll instanceof HTMLInputElement) selectAll.checked = false;
      const focus = trigger.isConnected ? trigger : focusCandidates.find(button => button.isConnected) || scope.querySelector('.epay-review-feedback');
      if (focus instanceof HTMLElement) focus.focus({ preventScroll: true });
      window.scrollTo({ top: scroll, behavior: 'instant' });
    }
  }

  document.querySelectorAll('.epay-review-form').forEach(form => {
    if (!(form instanceof HTMLFormElement)) return;
    form.addEventListener('submit', event => {
      event.preventDefault();
      const trigger = event.submitter;
      if (!(trigger instanceof HTMLButtonElement)) return;
      const individual = trigger.name === 'review_key';
      const action = form.querySelector('[name="bulk_action"]');
      const selected = Array.from(form.querySelectorAll('input[name="review_keys[]"]:checked'))
        .filter(input => input instanceof HTMLInputElement).map(input => input.value);
      if (!individual && (!(action instanceof HTMLSelectElement) || action.value !== 'review')) { announce(form, strings.select); return; }
      void review(form, individual ? [trigger.value] : selected, !individual, trigger);
    });
    form.querySelector('.epay-review-select-all')?.addEventListener('change', event => {
      if (!(event.target instanceof HTMLInputElement)) return;
      const checked = event.target.checked;
      form.querySelectorAll('input[name="review_keys[]"]').forEach(input => { if (input instanceof HTMLInputElement) input.checked = checked; });
    });
  });

  const category = document.querySelector('#epay-review-category');
  if (category instanceof HTMLSelectElement) category.addEventListener('change', () => category.form?.requestSubmit());

  document.querySelectorAll('.epay-order-reviews .epay-mark-reviewed').forEach(button => {
    if (!(button instanceof HTMLButtonElement)) return;
    button.addEventListener('click', () => {
      const scope = button.closest('.epay-order-reviews');
      if (scope instanceof HTMLElement) void review(scope, [button.value], false, button);
    });
  });

  document.querySelectorAll('.epay-copy-reference').forEach(button => {
    if (!(button instanceof HTMLButtonElement)) return;
    button.addEventListener('click', async () => {
      const feedback = button.parentElement?.querySelector('.epay-copy-feedback');
      try {
        await navigator.clipboard.writeText(button.dataset.reference || '');
        if (feedback) feedback.textContent = strings.copied;
      } catch {
        if (feedback) feedback.textContent = strings.copyFailed;
      }
    });
  });
}());
