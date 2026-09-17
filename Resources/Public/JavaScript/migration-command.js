import { initializeCommandOutput, quote, trackEditedFields } from '@oliverthiele/ot-websitecheck/command-line.js';

/**
 * Composes the websitecheck:migrationcheck call from the chosen snapshots and
 * keeps the suggested labels in step with them until someone types their own.
 */

const migrationCommand = document.querySelector('[data-js="migrationCommand"]');
if (migrationCommand?.querySelector('[data-js="commandOutput"]')) {
  initializeMigrationCommand(migrationCommand);
}

function initializeMigrationCommand(migrationCommand) {
  const labels = migrationCommand.dataset;
  const migrationCommandReference = migrationCommand.querySelector('[data-js="migrationCommandReference"]');
  const migrationCommandTarget = migrationCommand.querySelector('[data-js="migrationCommandTarget"]');
  const migrationCommandReferenceRun = migrationCommand.querySelector('[data-js="migrationCommandReferenceRun"]');
  const migrationCommandRun = migrationCommand.querySelector('[data-js="migrationCommandRun"]');
  const migrationCommandReferenceLabelField = migrationCommand.querySelector('[data-js="migrationCommandReferenceLabelField"]');
  const migrationCommandReferenceLabel = migrationCommand.querySelector('[data-js="migrationCommandReferenceLabel"]');
  const migrationCommandTargetLabel = migrationCommand.querySelector('[data-js="migrationCommandTargetLabel"]');
  const migrationCommandLimit = migrationCommand.querySelector('[data-js="migrationCommandLimit"]');

  const setSuggestion = trackEditedFields(
    [migrationCommandRun, migrationCommandReferenceLabel, migrationCommandTargetLabel],
    () => render(),
  );

  function selectedData(select) {
    return select.selectedOptions[0]?.dataset ?? {};
  }

  function reusedEnvironments() {
    const environments = migrationCommandReferenceRun.selectedOptions[0]?.dataset.referenceEnvironments ?? '';
    return environments === '' ? [] : environments.split(',');
  }

  // Only runs that compared the chosen reference snapshot can lend their rows.
  function filterReferenceRuns() {
    const referenceUid = selectedData(migrationCommandReference).uid;
    [...migrationCommandReferenceRun.options].forEach((option) => {
      if (option.value !== '') {
        option.hidden = option.dataset.referenceSnapshot !== referenceUid;
        option.disabled = option.hidden;
      }
    });
  }

  // A live target newer than a live reference means the relaunch is live: the reference no longer answers.
  function suggestReferenceRun() {
    const reference = selectedData(migrationCommandReference);
    const target = selectedData(migrationCommandTarget);
    const afterSwitch = reference.environment === 'live'
      && target.environment === 'live'
      && Number(target.fetchedAt) > Number(reference.fetchedAt);
    const firstRun = [...migrationCommandReferenceRun.options].find((option) => option.value !== '' && !option.disabled);
    migrationCommandReferenceRun.value = afterSwitch && firstRun ? firstRun.value : '';
  }

  function suggestLabels() {
    const reference = selectedData(migrationCommandReference);
    const target = selectedData(migrationCommandTarget);
    let referenceName = reference.environment || reference.host || 'reference';
    let targetName = target.environment || target.host || 'target';
    if (referenceName === targetName) {
      referenceName += '-before';
      targetName += '-after';
    }
    if (reusedEnvironments().includes(targetName)) {
      targetName += '-after';
    }
    setSuggestion(migrationCommandReferenceLabel, referenceName);
    setSuggestion(migrationCommandTargetLabel, targetName);
    setSuggestion(
      migrationCommandRun,
      `${reference.environment || 'reference'}-to-${target.environment || 'target'}-${labels.today}`,
    );
  }

  function collectWarnings(reuse) {
    const warnings = [];
    const referenceLabel = migrationCommandReferenceLabel.value.trim();
    const targetLabel = migrationCommandTargetLabel.value.trim();
    if (migrationCommandRun.value.trim() === '' || targetLabel === '' || (!reuse && referenceLabel === '')) {
      warnings.push(labels.labelMissingValue);
    }
    if (migrationCommandReference.value === migrationCommandTarget.value) {
      warnings.push(labels.labelSameSnapshot);
    }
    if (!reuse && referenceLabel !== '' && referenceLabel === targetLabel) {
      warnings.push(labels.labelSameLabels);
    }
    if (reuse && reusedEnvironments().includes(targetLabel)) {
      warnings.push(labels.labelLabelClash.replace('%1$s', targetLabel));
    }
    return warnings;
  }

  function build() {
    const reuse = migrationCommandReferenceRun.value !== '';
    migrationCommandReferenceLabelField.hidden = reuse;

    const parts = [
      'websitecheck:migrationcheck',
      `--run=${quote(migrationCommandRun.value.trim())}`,
      `--reference-snapshot=${quote(migrationCommandReference.value)}`,
      `--target-snapshot=${quote(migrationCommandTarget.value)}`,
    ];
    if (reuse) {
      parts.push(`--reference-run=${quote(migrationCommandReferenceRun.value)}`);
    } else {
      parts.push(`--reference-label=${quote(migrationCommandReferenceLabel.value.trim())}`);
    }
    parts.push(`--target-label=${quote(migrationCommandTargetLabel.value.trim())}`);
    const limit = Number.parseInt(migrationCommandLimit.value, 10);
    if (limit > 0) {
      parts.push(`--limit=${limit}`);
    }

    return { parts, warnings: collectWarnings(reuse) };
  }

  const render = initializeCommandOutput(migrationCommand, build);

  migrationCommandReference.addEventListener('change', () => {
    filterReferenceRuns();
    suggestReferenceRun();
    suggestLabels();
    render();
  });
  migrationCommandTarget.addEventListener('change', () => {
    suggestReferenceRun();
    suggestLabels();
    render();
  });
  migrationCommandReferenceRun.addEventListener('change', () => {
    suggestLabels();
    render();
  });
  migrationCommandLimit.addEventListener('input', render);

  filterReferenceRuns();
  suggestReferenceRun();
  suggestLabels();
  render();
}
