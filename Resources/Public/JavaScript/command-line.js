import { copyToClipboard } from '@typo3/backend/copy-to-clipboard.js';

/**
 * The parts every command form shares: the chosen call, the printed command,
 * its warnings and the copy button. Hooks are looked up inside the form, so
 * several forms may use the same data-js values.
 */

const PREFIX_STORAGE_KEY = 'ot-websitecheck-command-prefix';

/**
 * Leaves plain values bare and wraps everything else in single quotes, which
 * a POSIX shell takes literally — default snapshot labels contain spaces.
 */
export function quote(value) {
  return /^[A-Za-z0-9_.\/:@%+=,-]+$/.test(value) ? value : `'${value.replaceAll("'", "'\\''")}'`;
}

/**
 * Reverses quote(): the Scheduler takes every option value as it stands.
 */
function unquote(value) {
  return value.startsWith("'") && value.endsWith("'") ? value.slice(1, -1).replaceAll("'\\''", "'") : value;
}

/**
 * Shows the command as a task of the Scheduler: its name, and every option
 * with its value as the task form asks for it.
 */
function renderScheduler(commandScheduler, parts) {
  if (!commandScheduler) {
    return;
  }
  const [command, ...options] = parts;
  const commandSchedulerText = commandScheduler.querySelector('[data-js="commandSchedulerText"]');
  const commandSchedulerOptions = commandScheduler.querySelector('[data-js="commandSchedulerOptions"]');
  commandSchedulerText.textContent = commandScheduler.dataset.label.replace('__COMMAND__', command);
  commandSchedulerOptions.replaceChildren(...options.map((option) => {
    const [name, ...value] = option.split('=');
    const item = document.createElement('li');
    const code = document.createElement('code');
    code.textContent = value.length > 0 ? `${name}: ${unquote(value.join('='))}` : name;
    item.append(code);
    return item;
  }));
}

/**
 * @param {HTMLElement} form
 * @param {() => {parts: string[], warnings: string[]}} build
 * @returns {() => void} renders the command again
 */
export function initializeCommandOutput(form, build) {
  const commandPrefix = form.querySelector('[data-js="commandPrefix"]');
  const commandOutput = form.querySelector('[data-js="commandOutput"]');
  const commandWarning = form.querySelector('[data-js="commandWarning"]');
  const commandCopy = form.querySelector('[data-js="commandCopy"]');
  const commandScheduler = form.querySelector('[data-js="commandScheduler"]');

  try {
    const storedPrefix = window.localStorage.getItem(PREFIX_STORAGE_KEY);
    if ([...commandPrefix.options].some((option) => option.value === storedPrefix)) {
      commandPrefix.value = storedPrefix;
    }
  } catch {
    // Storage may be unavailable; the first option is a fine default.
  }

  function render() {
    const { parts, warnings } = build();
    commandOutput.textContent = [commandPrefix.value, ...parts].join(' ');
    renderScheduler(commandScheduler, parts);
    commandWarning.replaceChildren(...warnings.map((warning) => {
      const line = document.createElement('div');
      line.textContent = warning;
      return line;
    }));
    commandWarning.hidden = warnings.length === 0;
    commandCopy.disabled = warnings.length > 0;
  }

  commandPrefix.addEventListener('change', () => {
    try {
      window.localStorage.setItem(PREFIX_STORAGE_KEY, commandPrefix.value);
    } catch {
      // Not remembered then; nothing else depends on it.
    }
    render();
  });
  commandCopy.addEventListener('click', () => {
    copyToClipboard(commandOutput.textContent);
  });

  return render;
}

/**
 * Remembers which of the given fields someone typed into; those keep their
 * value, the others follow the suggestions.
 *
 * @param {HTMLInputElement[]} fields
 * @param {() => void} onInput
 * @returns {(field: HTMLInputElement, value: string) => void} sets a suggestion
 */
export function trackEditedFields(fields, onInput) {
  const editedFields = new Set();
  fields.forEach((field) => {
    field.addEventListener('input', () => {
      if (field.value === '') {
        editedFields.delete(field);
      } else {
        editedFields.add(field);
      }
      onInput();
    });
  });

  return (field, value) => {
    if (!editedFields.has(field)) {
      field.value = value;
    }
  };
}
