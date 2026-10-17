(function () {
  'use strict';

  var viewChartBtn = document.getElementById('view-chart');
  var dialog = document.getElementById('chart-lightbox');
  if (!viewChartBtn || !dialog || !window.BsChart) {
    return;
  }

  var fromInput = document.getElementById('filter-from');
  var toInput = document.getElementById('filter-to');
  var mountEl = document.getElementById('chart-mount');
  var emptyEl = document.getElementById('chart-empty');
  var pdfLink = document.getElementById('chart-pdf-link');
  var closeBtn = dialog.querySelector('.lightbox__close');
  var periodCard = document.getElementById('period-average-card');

  // Reuses the exact rows already rendered into the page by index.php — no new
  // AJAX call, same data-timestamp/data-value attributes dashboard.js reads.
  function collectPoints(from, to) {
    var rows = Array.prototype.slice.call(document.querySelectorAll('.measurement-row'));
    var points = rows
      .map(function (row) {
        return {
          timestamp: row.getAttribute('data-timestamp'),
          value: parseFloat(row.getAttribute('data-value'))
        };
      })
      .filter(function (p) {
        var datePart = p.timestamp.slice(0, 10);
        if (from && datePart < from) return false;
        if (to && datePart > to) return false;
        return true;
      });
    points.sort(function (a, b) {
      return a.timestamp < b.timestamp ? -1 : (a.timestamp > b.timestamp ? 1 : 0);
    });
    return points;
  }

  viewChartBtn.addEventListener('click', function () {
    var from = fromInput.value;
    var to = toInput.value;
    var points = collectPoints(from, to);

    if (points.length === 0) {
      mountEl.hidden = true;
      emptyEl.hidden = false;
      pdfLink.hidden = true;
    } else {
      var thresholds = {
        redLowMax: parseFloat(periodCard.dataset.thresholdRedLowMax),
        greenMin: parseFloat(periodCard.dataset.thresholdGreenMin),
        greenMax: parseFloat(periodCard.dataset.thresholdGreenMax),
        redHighMin: parseFloat(periodCard.dataset.thresholdRedHighMin)
      };

      mountEl.hidden = false;
      emptyEl.hidden = true;
      pdfLink.hidden = false;
      window.BsChart.render(mountEl, points, thresholds);

      // The PDF export endpoint requires both from/to — default to the actual
      // span of plotted readings when either filter field is empty, so the
      // exported PDF always matches exactly what's on screen.
      var pdfFrom = from || points[0].timestamp.slice(0, 10);
      var pdfTo = to || points[points.length - 1].timestamp.slice(0, 10);
      var baseUrl = pdfLink.getAttribute('data-base-url');
      pdfLink.href = baseUrl + '?from=' + encodeURIComponent(pdfFrom) + '&to=' + encodeURIComponent(pdfTo);
    }

    dialog.showModal();
  });

  if (closeBtn) {
    closeBtn.addEventListener('click', function () {
      dialog.close();
    });
  }

  // A click landing on the <dialog> element itself (not inside its content)
  // means the backdrop was hit, since <dialog> has no padding of its own.
  dialog.addEventListener('click', function (e) {
    if (e.target === dialog) {
      dialog.close();
    }
  });

  // Don't leave a stale floating chart tooltip visible after the lightbox closes.
  dialog.addEventListener('close', function () {
    if (window.BsChart && window.BsChart.hideTooltip) {
      window.BsChart.hideTooltip();
    }
  });
})();
