/**
 * Dashboard charts (Chart.js, stored in assets/vendor/chartjs). Every chart is a canvas that says what to draw:
 *
 *   <canvas data-chart="daily" data-chart-source="sales-data">   one point per day (oldest left)
 *   <canvas data-chart="bars"  data-chart-source="pipeline-data"> one horizontal bar per item, value written at the end
 *   <canvas data-chart="doughnut" data-chart-source="orders-data"> order status distribution
 *
 * The numbers are in <script type="application/json" id="…">:
 *   { labels: [...], values: [...], format: "money" | "count",
 *     titles: [...] (optional, tooltip heading per item), tooltip: [...] (optional ready-written lines),
 *     colors: [...] (optional CSS color tokens for doughnut charts) }
 * Doughnut values are also listed in the card's accessible HTML legend.
 *
 * Look: daily trends use a soft green area line; comparisons use rounded green gradient bars. Both use a hairline
 * grid, muted axis text, one axis and no legend (the card title names the single series).
 */
(() => {
  'use strict';

  if (typeof Chart === 'undefined') {
    return;
  }

  const COLORS = {
    bar: '#1d7a52',
    barHover: '#0e3b2a',   // the brand's dark green
    barStart: '#0e3b2a',
    pointHover: '#f39200',
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

  const barGradient = ({ chart }) => {
    const { ctx, chartArea } = chart;
    if (!chartArea) {
      return COLORS.bar;
    }

    const gradient = chart.options.indexAxis === 'y'
      ? ctx.createLinearGradient(chartArea.left, 0, chartArea.right, 0)
      : ctx.createLinearGradient(0, chartArea.bottom, 0, chartArea.top);
    gradient.addColorStop(0, COLORS.barStart);
    gradient.addColorStop(0.58, COLORS.bar);
    gradient.addColorStop(0.82, 'rgba(255, 255, 255, 0.24)');
    gradient.addColorStop(1, COLORS.bar);
    return gradient;
  };

  /** Adds restrained depth to chart marks without changing their position or scale. */
  const chartDepth = {
    id: 'chartDepth',
    beforeDatasetDraw(chart) {
      chart.ctx.save();
      chart.ctx.shadowColor = 'rgba(14, 59, 42, 0.18)';
      chart.ctx.shadowBlur = chart.config.type === 'doughnut' ? 8 : 5;
      chart.ctx.shadowOffsetY = chart.config.type === 'doughnut' ? 2 : 3;
    },
    afterDatasetDraw(chart) {
      chart.ctx.restore();
    },
  };

  const areaGradient = ({ chart }) => {
    const { ctx, chartArea } = chart;
    if (!chartArea) {
      return 'rgba(29, 122, 82, 0.12)';
    }

    const gradient = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom);
    gradient.addColorStop(0, 'rgba(29, 122, 82, 0.22)');
    gradient.addColorStop(1, 'rgba(29, 122, 82, 0.01)');
    return gradient;
  };

  const barDataset = (values) => ({
    data: values,
    backgroundColor: barGradient,
    hoverBackgroundColor: COLORS.barHover,
    maxBarThickness: 24,
    borderRadius: 6,
    borderSkipped: false,
  });

  /** The tooltip: the item's title, then its ready-written lines (or the value). */
  const tooltip = (data) => ({
    backgroundColor: COLORS.tooltip,
    padding: 10,
    cornerRadius: 10,
    caretSize: 5,
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

  /** Redraws the active horizontal bar slightly raised so it reads as the selected chart section. */
  const liftActiveBar = {
    id: 'liftActiveBar',
    afterDatasetsDraw(chart) {
      if (chart.config.type !== 'bar') {
        return;
      }

      chart.getActiveElements().forEach(({ datasetIndex, index }) => {
        const element = chart.getDatasetMeta(datasetIndex).data[index];
        if (!element) {
          return;
        }

        const { x, y, base, height } = element.getProps(['x', 'y', 'base', 'height'], true);
        const left = Math.min(x, base);
        const width = Math.abs(x - base);
        const top = y - height / 2 - 4;
        const ctx = chart.ctx;
        ctx.save();
        ctx.fillStyle = element.options.backgroundColor;
        ctx.shadowColor = 'rgba(14, 59, 42, 0.3)';
        ctx.shadowBlur = 9;
        ctx.shadowOffsetY = 4;
        ctx.beginPath();
        if (typeof ctx.roundRect === 'function') {
          ctx.roundRect(left, top, width, height, 6);
        } else {
          ctx.rect(left, top, width, height);
        }
        ctx.fill();
        ctx.restore();
      });
    },
  };

  /** A smooth daily trend; hovering anywhere over a day shows its values. */
  const daily = (data) => ({
    type: 'line',
    plugins: [chartDepth],
    data: {
      labels: data.labels,
      datasets: [{
        data: data.values,
        borderColor: COLORS.bar,
        backgroundColor: areaGradient,
        borderWidth: 2.5,
        fill: true,
        tension: 0.35,
        pointRadius: 2,
        pointHoverRadius: 5,
        pointBackgroundColor: '#fff',
        pointBorderColor: COLORS.bar,
        pointHoverBackgroundColor: COLORS.pointHover,
        pointHoverBorderColor: COLORS.barHover,
        pointBorderWidth: 2,
      }],
    },
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
    plugins: [chartDepth, valueAtBarEnd, liftActiveBar],
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

  /** Status totals in a doughnut; the card provides a matching HTML legend and center count. */
  const doughnut = (data) => {
    const theme = getComputedStyle(document.documentElement);
    const colors = data.colors.map((color_token) => theme.getPropertyValue(color_token).trim());

    return {
      type: 'doughnut',
      plugins: [chartDepth],
      data: {
        labels: data.labels,
        datasets: [{
          data: data.values,
          backgroundColor: colors,
          borderColor: '#fff',
          borderWidth: 4,
          hoverOffset: 10,
          hoverBorderWidth: 5,
          spacing: 2,
        }],
      },
      options: {
        cutout: '68%',
        plugins: { legend: { display: false }, tooltip: tooltip(data) },
      },
    };
  };

  const CHART_TYPES = { daily, bars, doughnut };

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

  document.querySelectorAll('[data-dashboard-period-form]').forEach((form) => {
    const period = form.querySelector('[data-dashboard-period]');
    const customDates = form.querySelector('[data-dashboard-custom-dates]');
    const dateInputs = customDates?.querySelectorAll('input[type="date"]') ?? [];
    if (!period || !customDates) {
      return;
    }

    const updateDateFields = () => {
      const isCustom = period.value === 'custom';
      customDates.classList.toggle('d-none', !isCustom);
      dateInputs.forEach((input) => {
        input.required = isCustom;
      });
      if (!isCustom) {
        form.requestSubmit();
      }
    };

    period.addEventListener('change', updateDateFields);
  });

})();
