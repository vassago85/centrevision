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
 *
 * `annotations` is an optional map keyed by x-axis label; each value is a list
 * of short strings appended to that bar's tooltip. Used by the dashboard to
 * surface holiday / weather context on the daily chart without adding another
 * bar series that would change the scale.
 */
function barChartConfig({ labels, values, series, color, maxBarThickness, showLegend, annotations }) {
    const colors = themeColors();

    // Support the single-series shorthand (`values: [...]`) and the grouped
    // multi-series form (`series: [{label, values, color}, ...]`).
    //
    // For grouped charts we detect the "scale mismatch" case — when one
    // series is at least four times larger than the first (usually the
    // "current period" vs a "previous period" that was much busier). In
    // that case we pin each series to its own Y-axis so a quiet current
    // period stays legible instead of collapsing to zero-height bars.
    const maxOf = (arr) => arr.reduce((m, v) => (v > m ? v : m), 0);
    const useDualAxis = (() => {
        if (!series || series.length < 2) return false;
        const primary = maxOf(series[0].values ?? []);
        return series.slice(1).some((s) => {
            const other = maxOf(s.values ?? []);
            return primary > 0 ? other >= primary * 4 : other > 0;
        });
    })();

    const datasets = (series && series.length)
        ? series.map((s, index) => ({
              label: s.label,
              data: s.values,
              backgroundColor: colors[s.color] ?? s.color ?? colors.accent,
              borderRadius: 4,
              maxBarThickness,
              yAxisID: useDualAxis ? (index === 0 ? 'y' : 'y1') : 'y',
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
            // Chart.js queues each draw through requestAnimationFrame. On a
            // Livewire re-render the canvas can be detached between the RAF
            // being scheduled and it firing, which is what caused the
            // "Cannot read properties of null (reading 'save')" crash — the
            // frame tried to draw on a canvas whose 2D context had gone away.
            // Skipping the animation removes that RAF window entirely.
            animation: false,
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
                    callbacks: {
                        // Append weather + holiday context to the tooltip if
                        // the caller supplied any for this bar's label. Keeps
                        // the chart itself unchanged (same bars, same scale)
                        // while explaining outliers on hover.
                        afterBody: (items) => {
                            if (!annotations || items.length === 0) return [];
                            const label = items[0].label;
                            const extras = annotations[label];
                            return Array.isArray(extras) ? extras : [];
                        },
                    },
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
                // Right-hand scale for the "previous period" bars when the
                // two series live on wildly different orders of magnitude.
                // Kept in-sync visually with the left axis (same grid off,
                // same font) so the dashboard doesn't feel like two charts
                // pretending to be one.
                ...(useDualAxis ? {
                    y1: {
                        position: 'right',
                        grid: { display: false },
                        ticks: { color: colors.muted, font: { size: 11 } },
                        beginAtZero: true,
                    },
                } : {}),
            },
        },
    };
}

document.addEventListener('alpine:init', () => {
    /**
     * Livewire-friendly Chart.js host. The canvas lives under `wire:ignore`,
     * so morphdom never touches it. The wrapper has a `data-chart-payload`
     * attribute Livewire keeps up to date on every poll; we watch that
     * attribute and either mount the chart the first time or push the new
     * numbers into the existing instance via chart.update('none').
     *
     * This is the mechanism that makes the dashboard update live without the
     * old symptom where a fresh poll would tear the canvas out and Alpine's
     * init would race an unlaid-out DOM node, leaving the chart blank until
     * the user hit reload.
     */
    window.Alpine.data('tfBarChart', () => ({
        chart: null,
        themeObserver: null,
        payloadObserver: null,
        currentPayload: null,

        mount() {
            this.currentPayload = this.readPayload();
            this.render();

            this.themeObserver = new MutationObserver(() => this.render());
            this.themeObserver.observe(document.documentElement, {
                attributes: true,
                attributeFilter: ['class'],
            });

            this.payloadObserver = new MutationObserver(() => {
                const next = this.readPayload();
                if (next === null) return;
                if (this.samePayload(next, this.currentPayload)) return;

                this.currentPayload = next;
                this.applyPayload();
            });
            this.payloadObserver.observe(this.$el, {
                attributes: true,
                attributeFilter: ['data-chart-payload'],
            });
        },

        destroy() {
            this.themeObserver?.disconnect();
            this.payloadObserver?.disconnect();
            this.safeDestroy();
        },

        readPayload() {
            const raw = this.$el?.dataset?.chartPayload;
            if (!raw) return null;
            try {
                return JSON.parse(raw);
            } catch (error) {
                console.error('Chart payload parse failed:', error);
                return null;
            }
        },

        samePayload(a, b) {
            if (a === b) return true;
            if (!a || !b) return false;
            return JSON.stringify(a) === JSON.stringify(b);
        },

        configFromPayload() {
            const p = this.currentPayload || {};
            return barChartConfig({
                labels: p.labels ?? [],
                values: p.values ?? [],
                series: p.series,
                color: p.color ?? 'accent',
                maxBarThickness: p.maxBarThickness ?? 20,
                showLegend: p.showLegend ?? false,
                annotations: p.annotations ?? {},
            });
        },

        /**
         * Chart.js keeps a per-canvas instance registry. Even with wire:ignore
         * on the canvas div, a page-level navigation or component teardown
         * could leave an orphan instance registered against our canvas node,
         * so we sweep any stray attachment before mounting a fresh chart.
         */
        safeDestroy() {
            const canvas = this.$refs?.canvas;
            const attached = canvas ? Chart.getChart(canvas) : null;

            if (attached && attached !== this.chart) {
                try { attached.destroy(); } catch (_) { /* already gone */ }
            }

            if (this.chart) {
                try { this.chart.destroy(); } catch (_) { /* already gone */ }
                this.chart = null;
            }
        },

        render() {
            this.safeDestroy();

            const canvas = this.$refs.canvas;

            // Bail if the canvas has been ripped out or has no paintable area
            // yet — Chart.js crashes on a 0x0 context. The theme observer or
            // the next payload change will retry.
            if (!canvas || !canvas.isConnected || canvas.offsetParent === null) {
                return;
            }

            try {
                this.chart = new Chart(canvas, this.configFromPayload());
            } catch (error) {
                console.error('Chart render failed:', error);
                this.chart = null;
            }
        },

        /**
         * Push the new payload into the existing Chart.js instance instead of
         * destroying and recreating it. Preserves the canvas across polls so
         * the chart never blanks out. Falls back to a full render if the
         * chart hasn't been created yet (e.g. first payload after a section
         * became visible).
         */
        applyPayload() {
            if (!this.chart) {
                this.render();
                return;
            }

            try {
                const next = this.configFromPayload();
                this.chart.data = next.data;
                this.chart.options = next.options;
                this.chart.update('none');
            } catch (error) {
                console.error('Chart update failed, remounting:', error);
                this.render();
            }
        },
    }));
});
