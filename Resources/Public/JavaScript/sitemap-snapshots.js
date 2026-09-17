import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import Notification from '@typo3/backend/notification.js';

/**
 * Filters the snapshot rows by language and sitemap group, locks snapshots
 * against deletion, and runs the sitemap import: discover the language
 * sitemaps of a site base, then import them one request per language so a
 * large site does not hit a timeout.
 */

const sitemapFilterLanguage = document.querySelector('[data-js="sitemapFilterLanguage"]');
const sitemapFilterGroup = document.querySelector('[data-js="sitemapFilterGroup"]');
const sitemapRow = document.querySelectorAll('[data-js="sitemapRow"]');

function applyFilter() {
  const language = sitemapFilterLanguage?.value ?? '';
  const group = sitemapFilterGroup?.value ?? '';
  sitemapRow.forEach((row) => {
    const languageMatches = language === '' || row.dataset.language === language;
    const groupMatches = group === '' || row.dataset.group === group;
    row.hidden = !(languageMatches && groupMatches);
  });
}

sitemapFilterLanguage?.addEventListener('change', applyFilter);
sitemapFilterGroup?.addEventListener('change', applyFilter);

const snapshotLockToggle = document.querySelectorAll('[data-js="snapshotLockToggle"]');

snapshotLockToggle.forEach((button) => {
  button.addEventListener('click', async () => {
    const labels = button.dataset;
    button.disabled = true;
    try {
      const response = await new AjaxRequest(TYPO3.settings.ajaxUrls.websitecheck_sitemap_toggle_locked).post({ uid: labels.uid });
      const data = await response.resolve();
      showLockState(button, data.locked === true);
    } catch (error) {
      const body = await error.response?.json().catch(() => null);
      Notification.error(labels.labelRequestFailed, body?.error ?? '');
    } finally {
      button.disabled = false;
    }
  });
});

function showLockState(button, locked) {
  const label = locked ? button.dataset.labelLocked : button.dataset.labelUnlocked;
  button.setAttribute('aria-pressed', locked ? 'true' : 'false');
  button.title = label;
  button.querySelector('[data-js="snapshotLockIconLocked"]').hidden = !locked;
  button.querySelector('[data-js="snapshotLockIconUnlocked"]').hidden = locked;
  button.querySelector('[data-js="snapshotLockLabel"]').textContent = label;
  // The server refuses to delete a locked snapshot anyway; this only spares the round trip.
  const snapshotDelete = button.closest('[data-js="snapshotPanel"]')?.querySelector('[data-js="snapshotDelete"]');
  if (snapshotDelete) {
    snapshotDelete.disabled = locked;
  }
}

const sitemapImport = document.querySelector('[data-js="sitemapImport"]');
if (sitemapImport) {
  initializeImport(sitemapImport);
}

function initializeImport(sitemapImport) {
  const labels = sitemapImport.dataset;
  const sitemapImportBase = sitemapImport.querySelector('[data-js="sitemapImportBase"]');
  const sitemapImportBasicAuthUser = sitemapImport.querySelector('[data-js="sitemapImportBasicAuthUser"]');
  const sitemapImportBasicAuthPassword = sitemapImport.querySelector('[data-js="sitemapImportBasicAuthPassword"]');
  const sitemapImportDiscover = sitemapImport.querySelector('[data-js="sitemapImportDiscover"]');
  const sitemapImportResult = sitemapImport.querySelector('[data-js="sitemapImportResult"]');
  const sitemapImportLanguages = sitemapImport.querySelector('[data-js="sitemapImportLanguages"]');
  const sitemapImportLanguageTemplate = sitemapImport.querySelector('[data-js="sitemapImportLanguageTemplate"]');
  const sitemapImportLabel = sitemapImport.querySelector('[data-js="sitemapImportLabel"]');
  const sitemapImportNote = sitemapImport.querySelector('[data-js="sitemapImportNote"]');
  const sitemapImportStart = sitemapImport.querySelector('[data-js="sitemapImportStart"]');
  const sitemapImportError = sitemapImport.querySelector('[data-js="sitemapImportError"]');
  if (!sitemapImportDiscover || !sitemapImportStart) {
    return;
  }

  let discoveredBase = '';

  function credentials() {
    return {
      basicAuthUser: sitemapImportBasicAuthUser.value,
      basicAuthPassword: sitemapImportBasicAuthPassword.value,
    };
  }

  function showError(message) {
    sitemapImportError.textContent = message;
    sitemapImportError.hidden = false;
  }

  async function post(routeName, data) {
    try {
      const response = await new AjaxRequest(TYPO3.settings.ajaxUrls[routeName]).post(data);
      return await response.resolve();
    } catch (error) {
      const body = await error.response?.json().catch(() => null);
      throw new Error(body?.error ?? labels.labelRequestFailed);
    }
  }

  function setBusy(busy) {
    sitemapImportDiscover.disabled = busy;
    sitemapImportStart.disabled = busy;
    sitemapImportBase.disabled = busy;
  }

  sitemapImportDiscover.addEventListener('click', async () => {
    sitemapImportError.hidden = true;
    sitemapImportResult.hidden = true;
    setBusy(true);
    try {
      const data = await post('websitecheck_sitemap_discover', { base: sitemapImportBase.value, ...credentials() });
      discoveredBase = sitemapImportBase.value;
      sitemapImportLanguages.replaceChildren(...data.sitemaps.map(renderLanguage));
      sitemapImportLabel.value = '';
      sitemapImportLabel.placeholder = data.defaultLabel;
      sitemapImportResult.hidden = false;
    } catch (error) {
      showError(error.message);
    } finally {
      setBusy(false);
    }
  });

  function renderLanguage(sitemap) {
    const item = sitemapImportLanguageTemplate.content.firstElementChild.cloneNode(true);
    const sitemapImportLanguageCheckbox = item.querySelector('[data-js="sitemapImportLanguageCheckbox"]');
    item.querySelector('[data-js="sitemapImportLanguageName"]').textContent = sitemap.language !== '' ? sitemap.language : labels.labelLanguageNone;
    item.querySelector('[data-js="sitemapImportLanguageUrl"]').textContent = sitemap.sitemapUrl;
    const sitemapImportLanguageStatus = item.querySelector('[data-js="sitemapImportLanguageStatus"]');
    sitemapImportLanguageStatus.hidden = sitemap.allowed;
    sitemapImportLanguageStatus.textContent = sitemap.allowed ? '' : labels.labelNotAllowed;
    sitemapImportLanguageCheckbox.checked = sitemap.allowed;
    sitemapImportLanguageCheckbox.disabled = !sitemap.allowed;
    item.dataset.language = sitemap.language;
    item.dataset.sitemapUrl = sitemap.sitemapUrl;
    return item;
  }

  function setStatus(item, badgeClass, text) {
    const sitemapImportLanguageStatus = item.querySelector('[data-js="sitemapImportLanguageStatus"]');
    sitemapImportLanguageStatus.className = `badge ${badgeClass}`;
    sitemapImportLanguageStatus.textContent = text;
    sitemapImportLanguageStatus.hidden = false;
  }

  sitemapImportStart.addEventListener('click', async () => {
    sitemapImportError.hidden = true;
    const selectedItems = [...sitemapImportLanguages.children].filter(
      (item) => item.querySelector('[data-js="sitemapImportLanguageCheckbox"]').checked,
    );
    if (selectedItems.length === 0) {
      showError(labels.labelNoSelection);
      return;
    }

    setBusy(true);
    selectedItems.forEach((item) => setStatus(item, 'badge-default', labels.labelWaiting));
    try {
      const start = await post('websitecheck_sitemap_import_start', {
        base: discoveredBase,
        label: sitemapImportLabel.value,
        note: sitemapImportNote.value,
      });
      for (const item of selectedItems) {
        setStatus(item, 'badge-info', labels.labelRunning);
        const result = await post('websitecheck_sitemap_import_language', {
          snapshotUid: start.snapshotUid,
          language: item.dataset.language,
          sitemapUrl: item.dataset.sitemapUrl,
          ...credentials(),
        });
        const text = labels.labelDone.replace('%1$s', result.urlCount).replace('%2$s', result.fileCount);
        if (result.failedCount > 0) {
          setStatus(item, 'badge-warning', `${text} — ${labels.labelFailedFiles.replace('%1$s', result.failedCount)}`);
        } else {
          setStatus(item, 'badge-success', text);
        }
      }
      await post('websitecheck_sitemap_import_finish', { snapshotUid: start.snapshotUid });
      sitemapImportStart.textContent = labels.labelFinished;
      window.location.reload();
    } catch (error) {
      showError(error.message);
      // Languages the import never reached must not keep saying "waiting".
      selectedItems
        .map((item) => item.querySelector('[data-js="sitemapImportLanguageStatus"]'))
        .filter((status) => status.textContent === labels.labelWaiting)
        .forEach((status) => {
          status.hidden = true;
        });
      setBusy(false);
    }
  });
}
