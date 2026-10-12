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

  var periodCard = document.getElementById('period-average-card');
  var periodValueEl = document.getElementById('period-average-value');
  var periodNEl = document.getElementById('period-average-n');
  var periodBadgeEl = document.getElementById('period-average-badge');
  var periodBadgeIconEl = periodBadgeEl ? periodBadgeEl.querySelector('.badge__icon') : null;
  var periodBadgeLabelEl = periodBadgeEl ? periodBadgeEl.querySelector('.badge__label') : null;

  // Mirrors includes/color_classifier.php's bs_classify_detail() — keep the two in sync
  // if the band logic ever changes.
  function classify(value, t) {
    if (value < t.redLowMax) return { color: 'red', label: 'Low', icon: '▼' };
    if (value < t.greenMin) return { color: 'yellow', label: 'Borderline low', icon: '▽' };
    if (value <= t.greenMax) return { color: 'green', label: 'In range', icon: '✓' };
    if (value <= t.redHighMin) return { color: 'yellow', label: 'Borderline high', icon: '△' };
    return { color: 'red', label: 'High', icon: '▲' };
  }

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
    var visibleSum = 0;

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
      if (visible) {
        visibleCount++;
        visibleSum += parseFloat(row.getAttribute('data-value'));
      }
    });

    if (countEl) {
      countEl.textContent = visibleCount;
    }

    // Show a dedicated average for the selected date range, same treatment
    // (colored border + icon/label badge) as the 7/14/30-day/all-time cards above.
    if (periodCard) {
      if (!from && !to) {
        periodCard.hidden = true;
      } else {
        periodCard.hidden = false;
        periodCard.classList.remove('average-card--red', 'average-card--yellow', 'average-card--green');
        periodNEl.textContent = visibleCount + (visibleCount === 1 ? ' reading' : ' readings');

        if (visibleCount > 0) {
          var avg = visibleSum / visibleCount;
          periodValueEl.textContent = avg.toFixed(1);

          var detail = classify(avg, {
            redLowMax: parseFloat(periodCard.dataset.thresholdRedLowMax),
            greenMin: parseFloat(periodCard.dataset.thresholdGreenMin),
            greenMax: parseFloat(periodCard.dataset.thresholdGreenMax),
            redHighMin: parseFloat(periodCard.dataset.thresholdRedHighMin)
          });
          periodCard.classList.add('average-card--' + detail.color);
          periodBadgeEl.hidden = false;
          periodBadgeEl.className = 'badge average-card__status badge--' + detail.color;
          periodBadgeIconEl.textContent = detail.icon;
          periodBadgeLabelEl.textContent = detail.label;
        } else {
          periodValueEl.textContent = '—';
          periodBadgeEl.hidden = true;
        }
      }
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
