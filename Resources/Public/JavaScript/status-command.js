import { initializeCommandOutput, quote, trackEditedFields } from '@oliverthiele/ot-websitecheck/command-line.js';

/**
 * Composes the websitecheck:checksitemap or websitecheck:crawllinks call for
 * the chosen snapshot, with an environment label taken from the snapshot until
 * someone types their own.
 */

const DEFAULT_SAMPLES_PER_SHAPE = 2;
const DEFAULT_MAX_LINKS = 2000;

const statusCommand = document.querySelector('[data-js="statusCommand"]');
if (statusCommand?.querySelector('[data-js="commandOutput"]')) {
  initializeStatusCommand(statusCommand);
}

function initializeStatusCommand(statusCommand) {
  const labels = statusCommand.dataset;
  const statusCommandType = statusCommand.querySelector('[data-js="statusCommandType"]');
  const statusCommandSnapshot = statusCommand.querySelector('[data-js="statusCommandSnapshot"]');
  const statusCommandEnvironment = statusCommand.querySelector('[data-js="statusCommandEnvironment"]');
  const statusCommandHostField = statusCommand.querySelector('[data-js="statusCommandHostField"]');
  const statusCommandHost = statusCommand.querySelector('[data-js="statusCommandHost"]');
  const statusCommandGroups = statusCommand.querySelector('[data-js="statusCommandGroups"]');
  const statusCommandLimit = statusCommand.querySelector('[data-js="statusCommandLimit"]');
  const statusCommandLinkFields = statusCommand.querySelector('[data-js="statusCommandLinkFields"]');
  const statusCommandSamples = statusCommand.querySelector('[data-js="statusCommandSamples"]');
  const statusCommandMaxLinks = statusCommand.querySelector('[data-js="statusCommandMaxLinks"]');
  const statusCommandAllLinks = statusCommand.querySelector('[data-js="statusCommandAllLinks"]');

  const setSuggestion = trackEditedFields([statusCommandEnvironment], () => render());

  function isLinkCheck() {
    return statusCommandType.value === 'crawllinks';
  }

  // Link results get their own label, so they do not replace those of a status check.
  function suggestEnvironment() {
    const snapshot = statusCommandSnapshot.selectedOptions[0]?.dataset ?? {};
    const host = !isLinkCheck() ? statusCommandHost.value.trim() : '';
    const name = host || snapshot.environment || snapshot.host || '';
    setSuggestion(statusCommandEnvironment, name !== '' && isLinkCheck() ? `${name}-links` : name);
  }

  function positiveNumber(field) {
    const value = Number.parseInt(field.value, 10);
    return value > 0 ? value : 0;
  }

  function build() {
    const linkCheck = isLinkCheck();
    statusCommandHostField.hidden = linkCheck;
    statusCommandLinkFields.hidden = !linkCheck;

    const environment = statusCommandEnvironment.value.trim();
    const parts = [
      linkCheck ? 'websitecheck:crawllinks' : 'websitecheck:checksitemap',
      `--snapshot=${quote(statusCommandSnapshot.value)}`,
      `--environment=${quote(environment)}`,
    ];
    const host = statusCommandHost.value.trim();
    if (!linkCheck && host !== '') {
      parts.push(`--host=${quote(host)}`);
    }
    statusCommandGroups.value
      .split(',')
      .map((group) => group.trim())
      .filter((group) => group !== '')
      .forEach((group) => parts.push(`--group=${quote(group)}`));
    const limit = positiveNumber(statusCommandLimit);
    if (limit > 0) {
      parts.push(`${linkCheck ? '--pages-limit' : '--limit'}=${limit}`);
    }
    if (linkCheck) {
      const samples = positiveNumber(statusCommandSamples);
      if (samples > 0 && samples !== DEFAULT_SAMPLES_PER_SHAPE) {
        parts.push(`--samples-per-shape=${samples}`);
      }
      const maxLinks = positiveNumber(statusCommandMaxLinks);
      if (maxLinks > 0 && maxLinks !== DEFAULT_MAX_LINKS) {
        parts.push(`--max-links=${maxLinks}`);
      }
      if (statusCommandAllLinks.checked) {
        parts.push('--all-links');
      }
    }

    return { parts, warnings: environment === '' ? [labels.labelMissingEnvironment] : [] };
  }

  const render = initializeCommandOutput(statusCommand, build);

  [statusCommandType, statusCommandSnapshot].forEach((field) => {
    field.addEventListener('change', () => {
      suggestEnvironment();
      render();
    });
  });
  statusCommandHost.addEventListener('input', () => {
    suggestEnvironment();
    render();
  });
  [statusCommandGroups, statusCommandLimit, statusCommandSamples, statusCommandMaxLinks].forEach((field) => {
    field.addEventListener('input', render);
  });
  statusCommandAllLinks.addEventListener('change', render);

  suggestEnvironment();
  render();
}
