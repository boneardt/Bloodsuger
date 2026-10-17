(function () {
  'use strict';

  var NS = 'http://www.w3.org/2000/svg';
  var VIEW_W = 720;
  var VIEW_H = 400;
  var MARGIN = { top: 20, right: 20, bottom: 40, left: 54 };
  var CORNER_R = 12; // plot-frame corner radius, in the same spirit as --radius elsewhere
  var MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

  var currentTooltipEl = null;
  var clipInstanceCounter = 0;

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

  function formatDateTime(timestamp) {
    var datePart = timestamp.slice(0, 10);
    var time = timestamp.slice(11, 16);
    var parts = datePart.split('-');
    return Number(parts[2]) + ' ' + MONTHS[Number(parts[1]) - 1] + ' ' + parts[0] + ', ' + time;
  }

  // Catmull-Rom-to-cubic-Bezier: a smooth curve that still passes through every
  // real point exactly (unlike an approximating spline) — only changes how the
  // line *looks* between readings, never implies a value that wasn't recorded.
  function smoothPath(pts) {
    if (pts.length === 0) return '';
    if (pts.length === 1) return 'M' + pts[0][0] + ',' + pts[0][1];
    var d = 'M' + pts[0][0] + ',' + pts[0][1];
    for (var i = 0; i < pts.length - 1; i++) {
      var p0 = pts[i === 0 ? i : i - 1];
      var p1 = pts[i];
      var p2 = pts[i + 1];
      var p3 = pts[i + 2 < pts.length ? i + 2 : i + 1];
      var c1x = p1[0] + (p2[0] - p0[0]) / 6;
      var c1y = p1[1] + (p2[1] - p0[1]) / 6;
      var c2x = p2[0] - (p3[0] - p1[0]) / 6;
      var c2y = p2[1] - (p3[1] - p1[1]) / 6;
      d += ' C' + c1x + ',' + c1y + ' ' + c2x + ',' + c2y + ' ' + p2[0] + ',' + p2[1];
    }
    return d;
  }

  // The tooltip must live inside the <dialog> (as a sibling of the chart, not
  // appended to document.body) — a modal <dialog> renders in the browser's top
  // layer, which always paints above anything that isn't also promoted to that
  // layer, regardless of z-index. A tooltip appended to document.body would be
  // invisible, hidden behind the dialog's own backdrop.
  function showTooltip(targetEl, text) {
    if (!currentTooltipEl) return;
    currentTooltipEl.textContent = text;
    currentTooltipEl.classList.add('is-visible');
    var rect = targetEl.getBoundingClientRect();
    var tipRect = currentTooltipEl.getBoundingClientRect();
    var left = rect.left + rect.width / 2 - tipRect.width / 2;
    left = Math.max(8, Math.min(left, window.innerWidth - tipRect.width - 8));
    var top = rect.top - tipRect.height - 8;
    if (top < 8) {
      top = rect.bottom + 8;
    }
    currentTooltipEl.style.left = left + 'px';
    currentTooltipEl.style.top = top + 'px';
  }

  function hideTooltip() {
    if (currentTooltipEl) {
      currentTooltipEl.classList.remove('is-visible');
    }
  }

  // Renders a line chart of {timestamp, value} points (ascending) into
  // mountEl, with yellow/red threshold bands (see includes/color_classifier.php's
  // bs_classify_detail() for the server-side mirror of this same band model).
  function render(mountEl, points, thresholds) {
    mountEl.innerHTML = '';

    // mountEl.parentElement (.lightbox__body) isn't cleared by the line above,
    // unlike mountEl itself, so a tooltip from a previous render can linger —
    // remove it before mounting a fresh one.
    var tooltipContainer = mountEl.parentElement || document.body;
    var staleTooltip = tooltipContainer.querySelector('.chart-tooltip');
    if (staleTooltip) {
      staleTooltip.parentNode.removeChild(staleTooltip);
    }
    currentTooltipEl = document.createElement('div');
    currentTooltipEl.className = 'chart-tooltip';
    currentTooltipEl.setAttribute('role', 'tooltip');
    tooltipContainer.appendChild(currentTooltipEl);

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
    svg.addEventListener('mouseleave', hideTooltip);

    // Everything inside the plot frame (background, bands, dashed lines, the
    // curve, and the points) is clipped to one rounded rect, so nothing pokes
    // out past the frame's rounded corners — matching the site's generally
    // soft, rounded design language instead of a sharp clinical frame.
    clipInstanceCounter += 1;
    var clipId = 'chart-clip-' + clipInstanceCounter;
    var defs = el('defs', {});
    var clipRect = el('rect', { x: MARGIN.left, y: MARGIN.top, width: plotW, height: plotH, rx: CORNER_R, ry: CORNER_R });
    var clipPath = el('clipPath', { id: clipId });
    clipPath.appendChild(clipRect);
    defs.appendChild(clipPath);
    svg.appendChild(defs);

    var plotGroup = el('g', { 'clip-path': 'url(#' + clipId + ')' });
    svg.appendChild(plotGroup);

    // Explicit theme-matched background — without this the plot area defaults
    // to a light/transparent surface that clashes starkly with the dialog's
    // own (possibly dark) background, and the band tints below render as
    // bright pastels instead of muted ones against the wrong backdrop.
    plotGroup.appendChild(el('rect', {
      x: MARGIN.left, y: MARGIN.top, width: plotW, height: plotH, fill: 'var(--bg-elevated)'
    }));

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
      plotGroup.appendChild(el('rect', {
        x: MARGIN.left, y: top, width: plotW, height: bottom - top,
        fill: band.color, 'fill-opacity': '0.15'
      }));
    });

    // Dotted boundary lines at each of the 4 threshold values, with their value
    // labelled on the Y axis — SVG's native stroke-dasharray needs no hand-rolled
    // dash logic, unlike the PDF path (vendored tFPDF has no dash support).
    [thresholds.redLowMax, thresholds.greenMin, thresholds.greenMax, thresholds.redHighMin].forEach(function (t) {
      var y = yScale(t);
      plotGroup.appendChild(el('line', {
        x1: MARGIN.left, x2: MARGIN.left + plotW, y1: y, y2: y,
        stroke: 'var(--text-muted)', 'stroke-width': '1', 'stroke-dasharray': '4 4'
      }));
      var label = el('text', {
        x: MARGIN.left - 6, y: y + 3, 'text-anchor': 'end', 'font-size': '11', fill: 'var(--text-muted)'
      });
      label.textContent = t.toFixed(1);
      svg.appendChild(label);
    });

    // The glucose line, as a smooth curve through the exact same points.
    var coords = points.map(function (p, i) { return [xScale(i), yScale(p.value)]; });
    plotGroup.appendChild(el('path', {
      d: smoothPath(coords), fill: 'none', stroke: 'var(--accent)', 'stroke-width': '2.5',
      'stroke-linecap': 'round', 'stroke-linejoin': 'round'
    }));

    // Each reading gets a small visible marker plus a larger invisible hit
    // target (easier to hover/tap precisely) that shows a styled tooltip with
    // the reading's date/time and value — hover, keyboard focus, and tap all
    // trigger it, since plain CSS :hover doesn't fire reliably on touch.
    points.forEach(function (p, i) {
      var cx = xScale(i);
      var cy = yScale(p.value);
      var label = formatDateTime(p.timestamp) + ' — ' + p.value.toFixed(1) + ' mmol/L';

      var dot = el('circle', { cx: cx, cy: cy, r: '5', fill: 'var(--accent)' });
      var title = el('title', {});
      title.textContent = label;
      dot.appendChild(title);

      var hit = el('circle', {
        cx: cx, cy: cy, r: '11', fill: 'transparent', tabindex: '0',
        role: 'img', 'aria-label': label, style: 'cursor: pointer; outline: none;'
      });
      hit.addEventListener('mouseenter', function () { showTooltip(hit, label); });
      hit.addEventListener('mouseleave', hideTooltip);
      hit.addEventListener('focus', function () { showTooltip(hit, label); });
      hit.addEventListener('blur', hideTooltip);
      hit.addEventListener('click', function () { showTooltip(hit, label); });

      plotGroup.appendChild(dot);
      plotGroup.appendChild(hit);
    });

    // Rounded frame, drawn on top of the clipped group (unclipped itself —
    // it's just the stroked outline of the exact same rounded rect).
    svg.appendChild(el('rect', {
      x: MARGIN.left, y: MARGIN.top, width: plotW, height: plotH, rx: CORNER_R, ry: CORNER_R,
      fill: 'none', stroke: 'var(--border)', 'stroke-width': '1'
    }));

    // X-axis: first/last reading dates only, to keep this simple.
    if (points.length > 0) {
      var firstLabel = el('text', {
        x: xScale(0), y: VIEW_H - MARGIN.bottom + 18, 'text-anchor': 'start', 'font-size': '11', fill: 'var(--text-muted)'
      });
      firstLabel.textContent = formatShortDate(points[0].timestamp.slice(0, 10));
      svg.appendChild(firstLabel);

      var lastLabel = el('text', {
        x: xScale(points.length - 1), y: VIEW_H - MARGIN.bottom + 18, 'text-anchor': 'end', 'font-size': '11', fill: 'var(--text-muted)'
      });
      lastLabel.textContent = formatShortDate(points[points.length - 1].timestamp.slice(0, 10));
      svg.appendChild(lastLabel);
    }

    mountEl.appendChild(svg);

    // Colorblind-safe legend, built from the same .badge classes used for
    // status everywhere else in the app (measurement rows, average cards,
    // the PDF table) instead of ad hoc swatches, so it matches the rest of
    // the site's visual language.
    var legend = document.createElement('div');
    legend.className = 'chart-legend';
    legend.innerHTML =
      '<span class="badge badge--accent"><span class="badge__icon" aria-hidden="true">●</span><span class="badge__label">Reading</span></span>' +
      '<span class="badge badge--yellow"><span class="badge__icon" aria-hidden="true">▽△</span><span class="badge__label">Borderline zone</span></span>' +
      '<span class="badge badge--red"><span class="badge__icon" aria-hidden="true">▼▲</span><span class="badge__label">Out-of-range zone</span></span>' +
      '<span class="chart-legend__text"><span class="chart-legend__dash" aria-hidden="true"></span>Threshold boundary</span>';
    mountEl.appendChild(legend);
  }

  window.BsChart = { render: render, hideTooltip: hideTooltip };
})();
