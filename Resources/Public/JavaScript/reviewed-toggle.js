import AjaxRequest from '@typo3/core/ajax/ajax-request.js';

const reviewedToggle = document.querySelectorAll('[data-js="reviewedToggle"]');

reviewedToggle.forEach((button) => {
  button.addEventListener('click', () => {
    const uid = button.dataset.uid;
    // Empty for the status module; the migration module names its table.
    const table = button.dataset.table ?? '';
    const badge = button.querySelector('[data-js="reviewedBadge"]');
    const url = TYPO3.settings.ajaxUrls.websitecheck_toggle_reviewed;

    button.disabled = true;
    new AjaxRequest(url)
      .post({ uid, table })
      .then(async (response) => {
        const data = await response.resolve();
        badge.classList.toggle('badge-success', data.reviewed === true);
        badge.classList.toggle('badge-warning', data.reviewed === false);
        badge.textContent = data.reviewed === true ? badge.dataset.labelYes : badge.dataset.labelNo;
      })
      .finally(() => {
        button.disabled = false;
      });
  });
});
