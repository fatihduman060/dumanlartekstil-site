// Explicit opt-in only: quantities, rates and document numbers are not money.
(function () {
  'use strict';
  var selector = 'input[data-money-input]';

  // Same separator rules as decimal_from_input; also accepts stored dot decimals.
  function decimalText(value) {
    var text = String(value == null ? '' : value).replace(/\s/g, '');
    var comma = text.lastIndexOf(',');
    var dot = text.lastIndexOf('.');
    if (comma !== -1 && dot !== -1) {
      return comma > dot ? text.replace(/\./g, '').replace(',', '.') : text.replace(/,/g, '');
    }
    if (comma !== -1) return text.replace(',', '.');
    if (dot !== -1 && (text.split('.').length > 2 || /^-?\d{1,3}\.\d{3}$/.test(text))) {
      return text.replace(/\./g, '');
    }
    return text;
  }

  function format(value, editing) {
    var text = editing ? String(value).replace(/\./g, '') : decimalText(value).replace('.', ',');
    // Do not silently turn malformed pasted data into a different amount.
    if (!/^-?\d*(,\d*)?$/.test(text)) return String(value);
    var parts = text.split(',');
    var whole = parts[0];
    if (parts.length > 1 && (whole === '' || whole === '-')) whole += '0';
    whole = whole.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    return whole + (parts.length > 1 ? ',' + parts[1] : '');
  }

  function eligible(input) {
    return input && input.matches && input.matches(selector) && input.type === 'text' && !input.readOnly && !input.disabled;
  }
  function position(text, count) {
    if (!count) return 0;
    for (var i = 0; i < text.length; i++) {
      if (text[i] !== '.' && --count === 0) return i + 1;
    }
    return text.length;
  }
  function update(input, editing) {
    if (!eligible(input)) return;
    var old = input.value;
    var next = format(old, editing);
    if (old === next) return;
    var start = input.selectionStart;
    var end = input.selectionEnd;
    var direction = input.selectionDirection;
    input.value = next;
    if (document.activeElement === input && start !== null) {
      input.setSelectionRange(position(next, old.slice(0, start).replace(/\./g, '').length),
        position(next, old.slice(0, end).replace(/\./g, '').length), direction);
    }
  }

  window.BitkeMoney = {
    parse: function (value) { var n = parseFloat(decimalText(value)); return isFinite(n) ? n : 0; },
    format: function (value) { return format(value, false); }
  };

  // Capture phase makes the formatted value available to existing total handlers.
  document.addEventListener('input', function (event) {
    if (event.isComposing) return;
    update(event.target, event.isTrusted && event.inputType !== 'insertFromPaste' && event.inputType !== 'insertFromDrop' && !(event.data && event.data.length > 1));
  }, true);
  document.addEventListener('compositionend', function (event) { update(event.target, true); }, true);
  document.addEventListener('focus', function (event) { update(event.target, false); }, true);
  document.addEventListener('blur', function (event) { update(event.target, false); }, true);

  document.addEventListener('beforeinput', function (event) {
    var input = event.target;
    if (!eligible(input) || event.isComposing || !event.cancelable) return;
    var start = input.selectionStart;
    var end = input.selectionEnd;
    // A decimal keypad's dot is treated as the Turkish decimal comma.
    if (event.inputType === 'insertText' && event.data === '.') {
      event.preventDefault();
      if (input.value.slice(0, start).indexOf(',') !== -1 || input.value.slice(end).indexOf(',') !== -1) return;
      input.setRangeText(',', start, end, 'end');
    } else if (start === end && event.inputType === 'deleteContentBackward' && input.value[start - 1] === '.') {
      event.preventDefault();
      input.setRangeText('', Math.max(0, start - 2), start, 'end');
    } else if (start === end && event.inputType === 'deleteContentForward' && input.value[start] === '.') {
      event.preventDefault();
      input.setRangeText('', start, start + 2, 'end');
    } else return;
    update(input, true);
    input.dispatchEvent(new Event('input', {bubbles: true}));
  }, true);

  function init(root) {
    if (eligible(root)) update(root, false);
    if (root.querySelectorAll) root.querySelectorAll(selector).forEach(function (input) { update(input, false); });
  }
  init(document);
  // Newly added check rows and financial modals share the same behavior.
  new MutationObserver(function (records) {
    records.forEach(function (record) { record.addedNodes.forEach(init); });
  }).observe(document.body, {childList: true, subtree: true});
})();
