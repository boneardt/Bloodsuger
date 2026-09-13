(function () {
  var rows = Array.prototype.slice.call(document.querySelectorAll('.measurement-row'));
  if (rows.length === 0) {
    return;
  }

  var fromInput = document.getElementById('filter-from');
  var toInput = document.getElementById('filter-to');
  var searchInput = document.getElementById('filter-search');
  var colorInputs = Array.prototype.slice.call(document.querySelectorAll('.filter-color'));
  var clearButton = document.getElementById('filter-clear');
  var countEl = document.getElementById('filter-count-visible');

  // Tap/click to expand a row's detail panel. A single `click` handler covers
  // both mouse clicks and touch taps identically — no separate touch wiring needed.
  rows.forEach(function (row) {
    var summary = row.querySelector('.measurement-row__summary');
    if (summary) {
      summary.addEventListener('click', function () {
        row.classList.toggle('is-expanded');
      });
    }
  });

  function applyFilters() {
    var from = fromInput.value; // 'YYYY-MM-DD' or ''
    var to = toInput.value;
    var query = searchInput.value.trim().toLowerCase();
    var activeColors = colorInputs.filter(function (c) { return c.checked; }).map(function (c) { return c.value; });

    var visibleCount = 0;

    rows.forEach(function (row) {
      var timestamp = row.getAttribute('data-timestamp'); // 'YYYY-MM-DDTHH:MM:SS'
      var datePart = timestamp.slice(0, 10);
      var color = row.getAttribute('data-color');
      var notes = row.getAttribute('data-notes') || '';

      var visible = true;
      if (from && datePart < from) visible = false;
      if (to && datePart > to) visible = false;
      if (activeColors.indexOf(color) === -1) visible = false;
      if (query && notes.indexOf(query) === -1) visible = false;

      row.classList.toggle('is-hidden-by-filter', !visible);
      if (visible) visibleCount++;
    });

    if (countEl) {
      countEl.textContent = visibleCount;
    }
  }

  [fromInput, toInput, searchInput].forEach(function (el) {
    el.addEventListener('input', applyFilters);
  });
  colorInputs.forEach(function (el) {
    el.addEventListener('change', applyFilters);
  });
  if (clearButton) {
    clearButton.addEventListener('click', function () {
      fromInput.value = '';
      toInput.value = '';
      searchInput.value = '';
      colorInputs.forEach(function (c) { c.checked = true; });
      applyFilters();
    });
  }
})();
