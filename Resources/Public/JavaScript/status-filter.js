/**
 * Applies the result filter as soon as a field changes; the submit button
 * stays for keyboard use and for browsers without JavaScript.
 */

const statusFilterAutoSubmit = document.querySelectorAll('[data-js="statusFilterAutoSubmit"]');

statusFilterAutoSubmit.forEach((field) => {
  field.addEventListener('change', () => {
    field.form?.requestSubmit();
  });
});
