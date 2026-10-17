(function () {
  'use strict';

  var MONTH_NAMES = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
  var WEEKDAY_NAMES = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

  // Only one popover open at a time, tracked here so opening a second one
  // (or clicking outside) can cleanly tear down the first.
  var openPicker = null;

  function pad2(n) { return n < 10 ? '0' + n : String(n); }

  function isoToDate(iso) {
    if (!iso) return null;
    var parts = iso.split('-');
    if (parts.length !== 3) return null;
    var d = new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2]));
    return isNaN(d.getTime()) ? null : d;
  }

  function dateToIso(d) {
    return d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate());
  }

  function formatDisplay(iso) {
    var d = isoToDate(iso);
    if (!d) return 'Any date';
    return d.getDate() + ' ' + MONTH_NAMES[d.getMonth()].slice(0, 3) + ' ' + d.getFullYear();
  }

  function closeOpenPicker() {
    if (openPicker) {
      openPicker.close();
      openPicker = null;
    }
  }

  function open(input, display, sync) {
    closeOpenPicker();

    var viewDate = isoToDate(input.value) || new Date();
    viewDate = new Date(viewDate.getFullYear(), viewDate.getMonth(), 1);

    var popover = document.createElement('div');
    popover.className = 'datepicker-popover';
    popover.setAttribute('role', 'dialog');
    popover.setAttribute('aria-label', 'Choose date');

    function selectDate(iso) {
      input.value = iso;
      input.dispatchEvent(new Event('input', { bubbles: true }));
      sync();
      closeOpenPicker();
      display.focus();
    }

    function render() {
      popover.innerHTML = '';

      var header = document.createElement('div');
      header.className = 'datepicker-popover__header';

      var prevBtn = document.createElement('button');
      prevBtn.type = 'button';
      prevBtn.className = 'datepicker-popover__nav';
      prevBtn.setAttribute('aria-label', 'Previous month');
      prevBtn.textContent = '‹';
      prevBtn.addEventListener('click', function () {
        viewDate.setMonth(viewDate.getMonth() - 1);
        render();
      });

      var label = document.createElement('span');
      label.className = 'datepicker-popover__label';
      label.textContent = MONTH_NAMES[viewDate.getMonth()] + ' ' + viewDate.getFullYear();

      var nextBtn = document.createElement('button');
      nextBtn.type = 'button';
      nextBtn.className = 'datepicker-popover__nav';
      nextBtn.setAttribute('aria-label', 'Next month');
      nextBtn.textContent = '›';
      nextBtn.addEventListener('click', function () {
        viewDate.setMonth(viewDate.getMonth() + 1);
        render();
      });

      header.appendChild(prevBtn);
      header.appendChild(label);
      header.appendChild(nextBtn);
      popover.appendChild(header);

      var weekdays = document.createElement('div');
      weekdays.className = 'datepicker-popover__weekdays';
      WEEKDAY_NAMES.forEach(function (w) {
        var el = document.createElement('span');
        el.textContent = w;
        weekdays.appendChild(el);
      });
      popover.appendChild(weekdays);

      var grid = document.createElement('div');
      grid.className = 'datepicker-popover__grid';

      var firstOfMonth = new Date(viewDate.getFullYear(), viewDate.getMonth(), 1);
      // Monday-first grid: convert JS's Sunday=0 to a Monday=0 offset.
      var startOffset = (firstOfMonth.getDay() + 6) % 7;
      var gridStart = new Date(firstOfMonth);
      gridStart.setDate(gridStart.getDate() - startOffset);

      var todayIso = dateToIso(new Date());
      var selectedIso = input.value;

      for (var i = 0; i < 42; i++) {
        var cellDate = new Date(gridStart);
        cellDate.setDate(gridStart.getDate() + i);
        var cellIso = dateToIso(cellDate);

        var cell = document.createElement('button');
        cell.type = 'button';
        cell.className = 'datepicker-popover__day';
        if (cellDate.getMonth() !== viewDate.getMonth()) {
          cell.classList.add('datepicker-popover__day--outside');
        }
        if (cellIso === todayIso) {
          cell.classList.add('datepicker-popover__day--today');
        }
        if (cellIso === selectedIso) {
          cell.classList.add('datepicker-popover__day--selected');
          cell.setAttribute('aria-current', 'date');
        }
        cell.textContent = String(cellDate.getDate());
        cell.addEventListener('click', (function (iso) {
          return function () { selectDate(iso); };
        })(cellIso));
        grid.appendChild(cell);
      }
      popover.appendChild(grid);

      var footer = document.createElement('div');
      footer.className = 'datepicker-popover__footer';

      var todayBtn = document.createElement('button');
      todayBtn.type = 'button';
      todayBtn.className = 'button button--ghost button--small';
      todayBtn.textContent = 'Today';
      todayBtn.addEventListener('click', function () { selectDate(todayIso); });

      var resetBtn = document.createElement('button');
      resetBtn.type = 'button';
      resetBtn.className = 'button button--ghost button--small';
      resetBtn.textContent = 'Reset';
      resetBtn.addEventListener('click', function () {
        input.value = '';
        input.dispatchEvent(new Event('input', { bubbles: true }));
        sync();
        closeOpenPicker();
        display.focus();
      });

      footer.appendChild(todayBtn);
      footer.appendChild(resetBtn);
      popover.appendChild(footer);
    }

    render();
    document.body.appendChild(popover);
    display.setAttribute('aria-expanded', 'true');

    function position() {
      var rect = display.getBoundingClientRect();
      var popRect = popover.getBoundingClientRect();
      var left = rect.left;
      if (left + popRect.width > window.innerWidth - 8) {
        left = Math.max(8, window.innerWidth - popRect.width - 8);
      }
      popover.style.top = (rect.bottom + window.scrollY + 6) + 'px';
      popover.style.left = (left + window.scrollX) + 'px';
    }
    position();

    function onDocClick(e) {
      if (!popover.contains(e.target) && e.target !== display) {
        closeOpenPicker();
      }
    }
    function onKeydown(e) {
      if (e.key === 'Escape') {
        closeOpenPicker();
        display.focus();
      }
    }

    document.addEventListener('click', onDocClick, true);
    document.addEventListener('keydown', onKeydown, true);
    window.addEventListener('resize', position);
    window.addEventListener('scroll', position, true);

    openPicker = {
      input: input,
      close: function () {
        document.removeEventListener('click', onDocClick, true);
        document.removeEventListener('keydown', onKeydown, true);
        window.removeEventListener('resize', position);
        window.removeEventListener('scroll', position, true);
        display.setAttribute('aria-expanded', 'false');
        if (popover.parentNode) {
          popover.parentNode.removeChild(popover);
        }
      }
    };
  }

  function enhance(input) {
    // The native input stays in the DOM as the source of truth (hidden via
    // CSS on [data-datepicker]) so existing code reading/writing its .value
    // and listening for its 'input' event keeps working unmodified.
    var display = document.createElement('button');
    display.type = 'button';
    display.className = 'datepicker__display';
    display.setAttribute('aria-haspopup', 'dialog');
    display.setAttribute('aria-expanded', 'false');
    display.textContent = formatDisplay(input.value);
    input.insertAdjacentElement('afterend', display);

    function sync() {
      display.textContent = formatDisplay(input.value);
    }
    // Other code (e.g. "Clear filters") may set input.value directly and
    // dispatch its own 'input' event — keep the display button in sync with that too.
    input.addEventListener('input', sync);

    display.addEventListener('click', function (e) {
      e.stopPropagation();
      if (openPicker && openPicker.input === input) {
        closeOpenPicker();
      } else {
        open(input, display, sync);
      }
    });
  }

  var inputs = Array.prototype.slice.call(document.querySelectorAll('input[type="date"][data-datepicker]'));
  inputs.forEach(enhance);
})();
