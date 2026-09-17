import { Collapse } from 'bootstrap';

/**
 * Remembers per viewer whether a module guide was opened or closed, and
 * restores that on the next visit. Without a stored choice the template's
 * default applies: open while the module has nothing to show.
 */

const STORAGE_PREFIX = 'ot-websitecheck-guide-';

const moduleGuide = document.querySelectorAll('[data-js="moduleGuide"]');

moduleGuide.forEach((guide) => {
  const moduleGuideBody = guide.querySelector('[data-js="moduleGuideBody"]');
  const storageKey = STORAGE_PREFIX + guide.dataset.guide;

  try {
    const stored = window.localStorage.getItem(storageKey);
    if (stored === 'open' || stored === 'closed') {
      const collapse = Collapse.getOrCreateInstance(moduleGuideBody, { toggle: false });
      if (stored === 'open') {
        collapse.show();
      } else {
        collapse.hide();
      }
    }
  } catch {
    // Without storage the default of the template stays.
  }

  const remember = (state) => {
    try {
      window.localStorage.setItem(storageKey, state);
    } catch {
      // Not remembered then; the default applies next time.
    }
  };
  moduleGuideBody.addEventListener('shown.bs.collapse', () => remember('open'));
  moduleGuideBody.addEventListener('hidden.bs.collapse', () => remember('closed'));
});
