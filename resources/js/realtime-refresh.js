export function registerRealtimeRefresh(Alpine) {
    Alpine.data('nocRealtimeRefresh', (options = {}) => ({
        unsubscribers: [],
        refreshTimer: null,

        init() {
            if (!window.NocRealtime?.enabled) {
                return;
            }

            const debounce = Number(
                options.debounce ?? window.__NOC_REALTIME__?.debounce_ms ?? 750,
            );

            const scheduleRefresh = () => {
                window.clearTimeout(this.refreshTimer);

                this.refreshTimer = window.setTimeout(async () => {
                    try {
                        await (options.method
                            ? this.$wire.call(options.method)
                            : this.$wire.$refresh());
                    } catch (error) {
                        console.error('Realtime refresh failed.', error);

                        return;
                    }

                    if (options.refreshCharts) {
                        window.NocCharts?.updateSeriesCharts(this.$el);
                    }
                }, debounce);
            };

            this.unsubscribers = (options.listeners ?? []).map(({ channel, event }) =>
                window.NocRealtime.subscribe(channel, event, scheduleRefresh),
            );
        },

        destroy() {
            window.clearTimeout(this.refreshTimer);

            for (const unsubscribe of this.unsubscribers) {
                unsubscribe();
            }

            this.unsubscribers = [];
        },
    }));
}
