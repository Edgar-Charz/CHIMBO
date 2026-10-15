/**
 * Dashboard charts (Chart.js, stored in assets/vendor/chartjs). Every chart is a canvas that says what to draw:
 *
 *   <canvas data-chart="daily" data-chart-source="sales-data">   one column per day (oldest left)
 *   <canvas data-chart="bars"  data-chart-source="pipeline-data"> one horizontal bar per item, value written at the end
 *
 * The numbers are in <script type="application/json" id="…">:
 *   { labels: [...], values: [...], format: "money" | "count",
 *     titles: [...] (optional, tooltip heading per item), tooltip: [[line, …], …] (optional, ready-written lines) }
 * The same numbers are in each chart's "Show as a table" view.
 *
 * Look (same on every chart): one series in CHIMBO green (#1d7a52, validated for lightness, chroma and contrast),
 * thin bars (≤ 24 px) rounded at the value end and square at the baseline, hairline grid, muted axis text.
 * One axis per chart; no legend (the card title names the single series).
 */
(() => {
  'use strict';

  if (typeof Chart === 'undefined') {
    return;
  }

  const COLORS = {
    bar: '#1d7a52',
    barHover: '#0e3b2a',   // the brand's dark green
    grid: '#ece6db',
    axisText: '#6b7570',
    tooltip: '#1f2a24',
  };

  const thousands = new Intl.NumberFormat('en-US');
  const compact = new Intl.NumberFormat('en-US', { notation: 'compact', maximumFractionDigits: 1 });

  /** A number as shown on an axis or next to a bar: "TZS 120K" for money, "1,240" for counts. */
  const shortValue = (value, format) => (format === 'money' ? `TZS ${compact.format(value)}` : thousands.format(value));

  Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
  Chart.defaults.color = COLORS.axisText;

  const barDataset = (values) => ({
    data: values,
    backgroundColor: COLORS.bar,
    hoverBackgroundColor: COLORS.barHover,
    maxBarThickness: 24,
    borderRadius: 4,
    borderSkipped: 'start',   // rounded at the value end, square at the baseline
  });

  /** The tooltip: the item's title, then its ready-written lines (or the value). */
  const tooltip = (data) => ({
    backgroundColor: COLORS.tooltip,
    padding: 10,
    displayColors: false,
    callbacks: {
      title: (items) => (data.titles ? data.titles[items[0].dataIndex] : items[0].label),
      label: (item) => (data.tooltip ? data.tooltip[item.dataIndex] : shortValue(item.raw, data.format)),
    },
  });

  /** Writes each bar's value just past its end in the axis text colour (never the bar colour). */
  const valueAtBarEnd = {
    id: 'valueAtBarEnd',
    afterDatasetsDraw(chart) {
      const { ctx } = chart;
      const format = chart.options.plugins.valueAtBarEnd.format;
      ctx.save();
      ctx.fillStyle = COLORS.axisText;
      ctx.font = `600 12px ${Chart.defaults.font.family}`;
      ctx.textAlign = 'left';
      ctx.textBaseline = 'middle';
      chart.getDatasetMeta(0).data.forEach((bar, index) => {
        ctx.fillText(shortValue(chart.data.datasets[0].data[index], format), bar.x + 6, bar.y);
      });
      ctx.restore();
    },
  };

  /** One column per day; hovering anywhere over a day shows it. */
  const daily = (data) => ({
    type: 'bar',
    data: { labels: data.labels, datasets: [barDataset(data.values)] },
    options: {
      interaction: { mode: 'index', intersect: false },
      plugins: { legend: { display: false }, tooltip: tooltip(data) },
      scales: {
        x: { grid: { display: false }, border: { color: COLORS.grid }, ticks: { maxRotation: 0, autoSkip: true, autoSkipPadding: 12 } },
        y: {
          beginAtZero: true,
          grid: { color: COLORS.grid, lineWidth: 1 },
          border: { display: false },
          ticks: { precision: 0, maxTicksLimit: 5, callback: (value) => shortValue(value, data.format) },
        },
      },
    },
  });

  /** One horizontal bar per item, in the given order, its value written at the end (no value axis needed). */
  const bars = (data) => ({
    type: 'bar',
    plugins: [valueAtBarEnd],
    data: { labels: data.labels, datasets: [barDataset(data.values)] },
    options: {
      indexAxis: 'y',
      layout: { padding: { right: data.format === 'money' ? 72 : 32 } },   // room for the value past the longest bar
      plugins: { legend: { display: false }, tooltip: tooltip(data), valueAtBarEnd: { format: data.format } },
      scales: {
        x: { display: false, beginAtZero: true },
        y: { grid: { display: false }, border: { display: false } },
      },
    },
  });

  const CHART_TYPES = { daily, bars };

  document.querySelectorAll('canvas[data-chart]').forEach((canvas) => {
    const source = document.getElementById(canvas.dataset.chartSource);
    const build = CHART_TYPES[canvas.dataset.chart];
    if (!source || !build) {
      return;
    }
    const config = build(JSON.parse(source.textContent));
    config.options = { responsive: true, maintainAspectRatio: false, animation: { duration: 250 }, ...config.options };
    new Chart(canvas, config);
  });
})();
