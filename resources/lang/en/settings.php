<?php

return [

    'groups' => [
        'defaults' => [
            'title' => 'Defaults',
            'description' => 'What revenue, metrics and reports open on before anybody picks something else at the top.',
        ],
    ],

    'fields' => [
        'default_period' => [
            'label' => 'Period on opening',
            'description' => 'The period every insights screen shows without a choice of its own. Picking another one at the top keeps it for that visit.',
        ],
        'currency' => [
            'label' => 'Currency of the revenue screen',
            'description' => 'The three-letter code of the currency the revenue screen opens on, e.g. EUR. Empty means: follow the payments addon. If nothing was ever taken in the named currency, the screen opens on the one that earned the most.',
        ],
    ],

];
