(() => {
  const formatInput = (input) => {
    const old_value = input.value;
    const cursor = input.selectionStart ?? old_value.length;
    const digits_before_cursor = old_value.slice(0, cursor).replace(/\D/g, '').length;
    const digits = old_value.replace(/\D/g, '');
    const formatted = digits.replace(/\B(?=(\d{3})+(?!\d))/g, ',');

    input.value = formatted;
    if (document.activeElement !== input) return;

    let digits_seen = 0;
    let next_cursor = formatted.length;
    for (let index = 0; index < formatted.length; index += 1) {
      if (/\d/.test(formatted[index])) digits_seen += 1;
      if (digits_seen >= digits_before_cursor) {
        next_cursor = index + 1;
        break;
      }
    }
    if (digits_before_cursor === 0) next_cursor = 0;
    input.setSelectionRange(next_cursor, next_cursor);
  };

  document.querySelectorAll('.js-number-format').forEach((input) => {
    input.addEventListener('input', () => formatInput(input));
  });
})();
