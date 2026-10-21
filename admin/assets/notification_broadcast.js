(() => {
  'use strict';

  const templateSelect = document.querySelector('[data-broadcast-template]');
  const titleField = document.querySelector('[data-broadcast-title]');
  const bodyField = document.querySelector('[data-broadcast-body]');
  const previewTitle = document.querySelector('[data-broadcast-preview-title]');
  const previewBody = document.querySelector('[data-broadcast-preview-body]');
  const bodyLength = document.querySelector('[data-broadcast-length]');
  if (!templateSelect || !titleField || !bodyField) return;

  const updatePreview = () => {
    if (previewTitle) previewTitle.textContent = titleField.value;
    if (previewBody) previewBody.textContent = bodyField.value;
    if (bodyLength) bodyLength.textContent = String(bodyField.value.length);
  };

  templateSelect.addEventListener('change', () => {
    const template = templateSelect.selectedOptions[0];
    titleField.value = template?.dataset.title ?? '';
    bodyField.value = template?.dataset.body ?? '';
    updatePreview();
    titleField.focus();
  });
  titleField.addEventListener('input', updatePreview);
  bodyField.addEventListener('input', updatePreview);
  updatePreview();
})();
