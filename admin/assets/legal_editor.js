(() => {
  'use strict';

  const allowedTags = new Set([
    'P', 'BR', 'STRONG', 'B', 'EM', 'I', 'U', 'S', 'STRIKE', 'DEL',
    'H2', 'H3', 'UL', 'OL', 'LI', 'BLOCKQUOTE', 'A',
  ]);
  const dangerousTags = new Set(['SCRIPT', 'STYLE', 'IFRAME', 'OBJECT']);

  const safeLink = (value) => {
    let trimmed = value.trim();
    if (!trimmed || trimmed.startsWith('//')) return null;
    if (/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(trimmed)) trimmed = `mailto:${trimmed}`;
    if (/^\+?[\d\s().-]{7,}$/.test(trimmed)) trimmed = `tel:${trimmed.replace(/[\s().-]/g, '')}`;
    if (trimmed.startsWith('/') || trimmed.startsWith('#')) return trimmed;
    try {
      const url = new URL(trimmed, window.location.origin);
      return ['http:', 'https:', 'mailto:', 'tel:'].includes(url.protocol) ? url.href : null;
    } catch {
      return null;
    }
  };

  const cleanNode = (node) => {
    if (node.nodeType === Node.TEXT_NODE) {
      return document.createTextNode(node.textContent ?? '');
    }
    if (!(node instanceof HTMLElement)) return document.createDocumentFragment();
    if (dangerousTags.has(node.tagName)) return document.createDocumentFragment();

    const outputTag = node.tagName === 'DIV' ? 'p' : node.tagName.toLowerCase();
    const isAllowed = allowedTags.has(node.tagName) || node.tagName === 'DIV';
    const container = isAllowed ? document.createElement(outputTag) : document.createDocumentFragment();
    if (node.tagName === 'A' && isAllowed) {
      const href = safeLink(node.getAttribute('href') ?? '');
      if (href) container.setAttribute('href', href);
    }
    [...node.childNodes].forEach((child) => container.append(cleanNode(child)));
    return container;
  };

  const escapeHtml = (value) => {
    const node = document.createElement('span');
    node.textContent = value;
    return node.innerHTML;
  };

  const safeEditorHtml = (value) => {
    const parsed = new DOMParser().parseFromString(value, 'text/html');
    const hasFormatting = parsed.body.querySelector([...allowedTags, 'DIV'].map((tag) => tag.toLowerCase()).join(','));
    if (hasFormatting) {
      const fragment = document.createDocumentFragment();
      [...parsed.body.childNodes].forEach((node) => fragment.append(cleanNode(node)));
      const holder = document.createElement('div');
      holder.append(fragment);
      return holder.innerHTML;
    }

    return value.split(/\r?\n\s*\r?\n/)
      .map((paragraph) => paragraph.trim())
      .filter(Boolean)
      .map((paragraph) => `<p>${paragraph.split(/\r?\n/).map((line) => escapeHtml(line)).join('<br>')}</p>`)
      .join('');
  };

  document.querySelectorAll('[data-legal-editor]').forEach((editor) => {
    const content = editor.querySelector('[data-legal-content]');
    const valueField = editor.querySelector('[data-legal-value]');
    const form = editor.closest('form');
    if (!content || !valueField || !form) return;

    let savedRange = null;
    content.innerHTML = safeEditorHtml(valueField.value);
    const synchronizeValue = () => { valueField.value = content.innerHTML; };
    const restoreSelection = () => {
      content.focus();
      if (!savedRange) return;
      const selection = window.getSelection();
      selection?.removeAllRanges();
      selection?.addRange(savedRange);
    };
    const rememberSelection = () => {
      const selection = window.getSelection();
      if (selection?.rangeCount && content.contains(selection.anchorNode)) {
        savedRange = selection.getRangeAt(0).cloneRange();
      }
    };
    const updateToolbar = () => {
      editor.querySelectorAll('[data-legal-command]').forEach((button) => {
        const command = button.dataset.legalCommand;
        if (!['bold', 'italic', 'underline', 'strikeThrough', 'insertOrderedList', 'insertUnorderedList'].includes(command)) return;
        button.classList.toggle('is-active', document.queryCommandState(command));
      });
      const styleSelect = editor.querySelector('[data-legal-style]');
      const selection = window.getSelection();
      const selectedNode = selection?.anchorNode;
      const block = selectedNode instanceof Element ? selectedNode.closest('p,h2,h3,blockquote') : selectedNode?.parentElement?.closest('p,h2,h3,blockquote');
      if (styleSelect && block && content.contains(block)) {
        styleSelect.value = ['h2', 'h3'].includes(block.tagName.toLowerCase()) ? block.tagName.toLowerCase() : 'p';
      }
    };

    document.addEventListener('selectionchange', () => {
      rememberSelection();
      updateToolbar();
    });
    content.addEventListener('input', synchronizeValue);
    form.addEventListener('submit', synchronizeValue);
    content.addEventListener('paste', (event) => {
      const clipboard = event.clipboardData;
      const pasted = clipboard?.getData('text/html') || clipboard?.getData('text/plain');
      if (!pasted) return;
      event.preventDefault();
      restoreSelection();
      document.execCommand('insertHTML', false, safeEditorHtml(pasted));
      synchronizeValue();
    });

    editor.querySelectorAll('[data-legal-command]').forEach((button) => {
      button.addEventListener('mousedown', (event) => event.preventDefault());
      button.addEventListener('click', () => {
        restoreSelection();
        document.execCommand(button.dataset.legalCommand, false);
        if (button.hasAttribute('data-legal-clear')) {
          document.execCommand('unlink', false);
          document.execCommand('formatBlock', false, '<p>');
        }
        synchronizeValue();
        rememberSelection();
        updateToolbar();
      });
    });

    editor.querySelector('[data-legal-style]')?.addEventListener('change', (event) => {
      restoreSelection();
      document.execCommand('formatBlock', false, `<${event.currentTarget.value}>`);
      synchronizeValue();
    });

    editor.querySelector('[data-legal-link]')?.addEventListener('mousedown', (event) => event.preventDefault());
    editor.querySelector('[data-legal-link]')?.addEventListener('click', () => {
      restoreSelection();
      const selection = window.getSelection();
      if (!selection || selection.isCollapsed) {
        window.alert('Select the text you want to turn into a link first.');
        return;
      }
      const enteredUrl = window.prompt('Enter a web address, email address, or phone link:');
      if (enteredUrl === null) return;
      const href = safeLink(enteredUrl);
      if (!href) {
        window.alert('Enter a valid http, https, email, or phone link.');
        return;
      }
      document.execCommand('createLink', false, href);
      synchronizeValue();
    });
  });
})();
