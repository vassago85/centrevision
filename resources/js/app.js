import Chart from 'chart.js/auto';

/**
 * Chart colours are read back out of the CSS custom properties rather than
 * hard-coded, so a light/dark switch recolours the canvas without a reload.
 */
function themeColors() {
    const styles = getComputedStyle(document.documentElement);

    const read = (name) => styles.getPropertyValue(name).trim();

    return {
        accent: read('--tf-accent'),
        accent2: read('--tf-accent-2') || read('--tf-accent'),
        accentSoft: read('--tf-accent-soft'),
        positive: read('--tf-positive'),
        danger: read('--tf-danger'),
        warning: read('--tf-warning'),
        muted: read('--tf-ink-muted'),
        line: read('--tf-line'),
    };
}

/**
 * Bar chart configuration. Accepts either a single `values` array (rendered as
 * one series) or a `series` array of `{label, values, color}` objects for a
 * grouped bar chart.
 */
function barChartConfig({ labels, values, series, color, maxBarThickness, showLegend }) {
    const colors = themeColors();

    // Support the single-series shorthand (`values: [...]`) and the grouped
    // multi-series form (`series: [{label, values, color}, ...]`).
    const datasets = (series && series.length)
        ? series.map((s) => ({
              label: s.label,
              data: s.values,
              backgroundColor: colors[s.color] ?? s.color ?? colors.accent,
              borderRadius: 4,
              maxBarThickness,
          }))
        : [
              {
                  data: values ?? [],
                  backgroundColor: colors[color] ?? colors.accent,
                  borderRadius: 4,
                  maxBarThickness,
              },
          ];

    return {
        type: 'bar',
        data: { labels, datasets },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: showLegend
                    ? {
                          display: true,
                          position: 'top',
                          align: 'end',
                          labels: {
                              color: colors.muted,
                              boxWidth: 10,
                              boxHeight: 10,
                              padding: 12,
                              font: { size: 11 },
                          },
                      }
                    : { display: false },
                tooltip: {
                    displayColors: false,
                    padding: 8,
                },
            },
            scales: {
                x: {
                    grid: { display: false },
                    ticks: { color: colors.muted, font: { size: 11 } },
                },
                y: {
                    grid: { color: colors.line },
                    ticks: { color: colors.muted, font: { size: 11 } },
                    beginAtZero: true,
                },
            },
        },
    };
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('tfBarChart', (config = {}) => ({
        chart: null,
        observer: null,

        init() {
            this.render();

            // Redraw when the appearance switcher toggles the .dark class.
            this.observer = new MutationObserver(() => this.render());
            this.observer.observe(document.documentElement, {
                attributes: true,
                attributeFilter: ['class'],
            });

            // Livewire may replace the dataset when the date range changes.
            this.$watch('$el.dataset.values', () => this.render());
        },

        destroy() {
            this.observer?.disconnect();
            this.chart?.destroy();
        },

        render() {
            this.chart?.destroy();

            const canvas = this.$refs.canvas;

            if (!canvas) {
                return;
            }

            this.chart = new Chart(
                canvas,
                barChartConfig({
                    labels: config.labels ?? [],
                    values: config.values ?? [],
                    series: config.series,
                    color: config.color ?? 'accent',
                    maxBarThickness: config.maxBarThickness ?? 20,
                    showLegend: config.showLegend ?? false,
                })
            );
        },
    }));
});
