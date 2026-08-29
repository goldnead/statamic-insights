/**
 * Statamic Insights — Control Panel entry point.
 *
 * Every page registered here matches an `Inertia::render('insights::...')` on
 * the PHP side, string for string. A name that renders but is not registered
 * answers 200 and paints a white page, with the reason only in the console —
 * `EveryInertiaPageIsRegisteredTest` compares the two lists so that cannot ship.
 */

import Revenue from './pages/Revenue.vue';
import Metrics from './pages/Metrics.vue';
import Metric from './pages/Metric.vue';

Statamic.booting(() => {
    Statamic.$inertia.register('insights::Revenue', Revenue);
    Statamic.$inertia.register('insights::Metrics', Metrics);
    Statamic.$inertia.register('insights::Metric', Metric);
});
