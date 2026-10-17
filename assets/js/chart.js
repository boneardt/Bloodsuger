(function () {
  'use strict';

  var NS = 'http://www.w3.org/2000/svg';
  var VIEW_W = 640;
  var VIEW_H = 320;
  var MARGIN = { top: 16, right: 16, bottom: 36, left: 48 };
  var MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

  function el(name, attrs) {
    var node = document.createElementNS(NS, name);
    for (var key in attrs) {
      node.setAttribute(key, attrs[key]);
    }
    return node;
  }

  function formatShortDate(isoDate) {
    var parts = isoDate.split('-');
    return Number(parts[2]) + ' ' + MONTHS[Number(parts[1]) - 1];
  }

  // Renders a line chart of {timestamp, value} points (ascending) into
  // mountEl, with yellow/red threshold bands (see includes/color_classifier.php's
  // bs_classify_detail() for the server-side mirror of this same band model).
  function render(mountEl, points, thresholds) {
    mountEl.innerHTML = '';

    var plotW = VIEW_W - MARGIN.left - MARGIN.right;
    var plotH = VIEW_H - MARGIN.top - MARGIN.bottom;

    var values = points.map(function (p) { return p.value; });
    var dataMin = Math.min.apply(null, values);
    var dataMax = Math.max.apply(null, values);
    var allMin = Math.min(dataMin, thresholds.redLowMax);
    var allMax = Math.max(dataMax, thresholds.redHighMin);
    var pad = (allMax - allMin) * 0.1 || 1;
    var yMin = allMin - pad;
    var yMax = allMax + pad;

    function yScale(v) {
      return MARGIN.top + plotH * (1 - (v - yMin) / (yMax - yMin));
    }
    function xScale(i) {
      return points.length <= 1
        ? MARGIN.left + plotW / 2
        : MARGIN.left + plotW * i / (points.length - 1);
    }

    var svg = el('svg', {
      viewBox: '0 0 ' + VIEW_W + ' ' + VIEW_H,
      role: 'img',
      'aria-label': 'Glucose readings over the selected period'
    });

    // Background threshold bands — only the yellow/red zones are shaded; the
    // green "in range" zone is left as the plain chart background, matching
    // the request's "showing the upper and lower (yellow and red area)".
    var bands = [
      { from: yMin, to: thresholds.redLowMax, color: 'var(--status-red)' },
      { from: thresholds.redLowMax, to: thresholds.greenMin, color: 'var(--status-yellow)' },
      { from: thresholds.greenMax, to: thresholds.redHighMin, color: 'var(--status-yellow)' },
      { from: thresholds.redHighMin, to: yMax, color: 'var(--status-red)' }
    ];
    bands.forEach(function (band) {
      var top = yScale(Math.min(band.to, yMax));
      var bottom = yScale(Math.max(band.from, yMin));
      if (bottom <= top) return;
      svg.appendChild(el('rect', {
        x: MARGIN.left, y: top, width: plotW, height: bottom - top,
        fill: band.color, 'fill-opacity': '0.15'
      }));
    });

    svg.appendChild(el('rect', {
      x: MARGIN.left, y: MARGIN.top, width: plotW, height: plotH,
      fill: 'none', stroke: 'var(--border)', 'stroke-width': '1'
    }));

    // Dotted boundary lines at each of the 4 threshold values, with their value
    // labelled on the Y axis — SVG's native stroke-dasharray needs no hand-rolled
    // dash logic, unlike the PDF path (vendored tFPDF has no dash support).
    [thresholds.redLowMax, thresholds.greenMin, thresholds.greenMax, thresholds.redHighMin].forEach(function (t) {
      var y = yScale(t);
      svg.appendChild(el('line', {
        x1: MARGIN.left, x2: MARGIN.left + plotW, y1: y, y2: y,
        stroke: 'var(--text-muted)', 'stroke-width': '1', 'stroke-dasharray': '4 4'
      }));
      var label = el('text', {
        x: MARGIN.left - 6, y: y + 3, 'text-anchor': 'end', 'font-size': '10', fill: 'var(--text-muted)'
      });
      label.textContent = t.toFixed(1);
      svg.appendChild(label);
    });

    // The glucose line itself, plus a small marker + native tooltip per reading.
    var pointsAttr = points.map(function (p, i) {
      return xScale(i) + ',' + yScale(p.value);
    }).join(' ');
    svg.appendChild(el('polyline', {
      points: pointsAttr, fill: 'none', stroke: 'var(--accent)', 'stroke-width': '2'
    }));
    points.forEach(function (p, i) {
      var circle = el('circle', { cx: xScale(i), cy: yScale(p.value), r: '3', fill: 'var(--accent)' });
      var title = el('title', {});
      title.textContent = p.value.toFixed(1) + ' mmol/L — ' + p.timestamp.replace('T', ' ');
      circle.appendChild(title);
      svg.appendChild(circle);
    });

    // X-axis: first/last reading dates only, to keep this simple.
    if (points.length > 0) {
      var firstLabel = el('text', {
        x: xScale(0), y: VIEW_H - MARGIN.bottom + 16, 'text-anchor': 'start', 'font-size': '10', fill: 'var(--text-muted)'
      });
      firstLabel.textContent = formatShortDate(points[0].timestamp.slice(0, 10));
      svg.appendChild(firstLabel);

      var lastLabel = el('text', {
        x: xScale(points.length - 1), y: VIEW_H - MARGIN.bottom + 16, 'text-anchor': 'end', 'font-size': '10', fill: 'var(--text-muted)'
      });
      lastLabel.textContent = formatShortDate(points[points.length - 1].timestamp.slice(0, 10));
      svg.appendChild(lastLabel);
    }

    mountEl.appendChild(svg);

    // Colorblind-safe legend: the bands/line are never color-only, matching
    // the rest of the app's icon+label convention.
    var legend = document.createElement('div');
    legend.className = 'chart-legend';
    legend.innerHTML =
      '<span class="chart-legend__item"><span class="chart-legend__swatch chart-legend__swatch--line"></span>Reading</span>' +
      '<span class="chart-legend__item"><span class="chart-legend__swatch chart-legend__swatch--yellow"></span>△▽ Borderline zone</span>' +
      '<span class="chart-legend__item"><span class="chart-legend__swatch chart-legend__swatch--red"></span>▼▲ Out-of-range zone</span>' +
      '<span class="chart-legend__item">┄┄ Threshold boundary</span>';
    mountEl.appendChild(legend);
  }

  window.BsChart = { render: render };
})();
