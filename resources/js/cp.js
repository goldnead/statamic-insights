/**
 * Statamic Insights — Control Panel entry point.
 *
 * Every page registered here matches an `Inertia::render('insights::...')` on
 * the PHP side, string for string. A name that renders but is not registered
 * answers 200 and paints a white page, with the reason only in the console —
 * `EveryInertiaPageIsRegisteredTest` compares the two lists so that cannot ship.
 */

import Revenue from './pages/Revenue.vue';

Statamic.booting(() => {
    Statamic.$inertia.register('insights::Revenue', Revenue);
});
