/**
 * Opens a checked page in a modal with an iframe instead of a new tab. A
 * click with a modifier key or the middle button keeps the browser's own
 * behaviour, so the link still opens in a new tab or window on request.
 *
 * The modal is created in this module's document, not in the backend's top
 * frame like TYPO3.Modal does: the frame sources the module allows (see
 * AllowPagePreviewFrames) apply to this document only.
 */
import { html } from 'lit';
import { Sizes, Types } from '@typo3/backend/modal.js';

const pagePreviewSelector = '[data-js="pagePreview"]';

function openPreview(url, title, labels) {
  const pagePreview = document.createElement('typo3-backend-modal');
  pagePreview.type = Types.template;
  pagePreview.templateResultContent = html`<iframe src=${url} class="modal-iframe" title=${title} referrerpolicy="no-referrer"></iframe>`;
  // The iframe type's layout: no padding, the frame fills the body.
  pagePreview.additionalCssClasses = ['modal-type-iframe'];
  pagePreview.size = Sizes.full;
  pagePreview.modalTitle = title;
  pagePreview.buttons = [
    {
      text: labels.newWindow,
      icon: 'actions-window-open',
      btnClass: 'btn-default',
      trigger: () => {
        window.open(url, '_blank', 'noopener');
      },
    },
    {
      text: labels.close,
      active: true,
      btnClass: 'btn-primary',
      trigger: (event, modal) => {
        modal.hideModal();
      },
    },
  ];
  pagePreview.addEventListener('typo3-modal-hidden', () => {
    pagePreview.remove();
  });
  document.body.appendChild(pagePreview);
}

document.addEventListener('click', (event) => {
  const pagePreview = event.target instanceof Element ? event.target.closest(pagePreviewSelector) : null;
  if (!pagePreview || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) {
    return;
  }
  event.preventDefault();
  openPreview(pagePreview.href, pagePreview.dataset.previewTitle || pagePreview.href, {
    newWindow: pagePreview.dataset.labelNewWindow,
    close: pagePreview.dataset.labelClose,
  });
});
