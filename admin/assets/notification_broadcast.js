(() => {
  'use strict';

  const templateSelect = document.querySelector('[data-broadcast-template]');
  const fields = {
    sw: {
      title: document.querySelector('[data-broadcast-title-sw]'),
      body: document.querySelector('[data-broadcast-body-sw]'),
      previewTitle: document.querySelector('[data-broadcast-preview-title-sw]'),
      previewBody: document.querySelector('[data-broadcast-preview-body-sw]'),
      bodyLength: document.querySelector('[data-broadcast-length-sw]'),
    },
    en: {
      title: document.querySelector('[data-broadcast-title-en]'),
      body: document.querySelector('[data-broadcast-body-en]'),
      previewTitle: document.querySelector('[data-broadcast-preview-title-en]'),
      previewBody: document.querySelector('[data-broadcast-preview-body-en]'),
      bodyLength: document.querySelector('[data-broadcast-length-en]'),
    },
  };
  if (!templateSelect || Object.values(fields).some(({ title, body }) => !title || !body)) return;

  const updatePreview = (language) => {
    const field = fields[language];
    field.previewTitle.textContent = field.title.value;
    field.previewBody.textContent = field.body.value;
    field.bodyLength.textContent = String(field.body.value.length);
  };

  templateSelect.addEventListener('change', () => {
    const template = templateSelect.selectedOptions[0];
    for (const language of ['sw', 'en']) {
      const languageSuffix = language[0].toUpperCase() + language.slice(1);
      fields[language].title.value = template?.dataset[`title${languageSuffix}`] ?? '';
      fields[language].body.value = template?.dataset[`body${languageSuffix}`] ?? '';
      updatePreview(language);
    }
    fields.sw.title.focus();
  });
  for (const language of ['sw', 'en']) {
    fields[language].title.addEventListener('input', () => updatePreview(language));
    fields[language].body.addEventListener('input', () => updatePreview(language));
    updatePreview(language);
  }
})();
