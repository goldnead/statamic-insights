<?php

return [

    'groups' => [
        'defaults' => [
            'title' => 'Voreinstellungen',
            'description' => 'Womit Umsatz, Kennzahlen und Berichte öffnen, bevor jemand oben etwas anderes wählt.',
        ],
    ],

    'fields' => [
        'default_period' => [
            'label' => 'Zeitraum beim Öffnen',
            'description' => 'Der Zeitraum, den alle Auswertungs-Bildschirme ohne eigene Wahl zeigen. Wer oben einen anderen wählt, behält ihn für diesen Besuch.',
        ],
        'currency' => [
            'label' => 'Währung des Umsatzbildschirms',
            'description' => 'Der dreistellige Code der Währung, auf die der Umsatzbildschirm öffnet, etwa EUR. Leer heißt: der Währung des Zahlungs-Addons folgen. Wurde in der genannten Währung nie etwas eingenommen, öffnet der Bildschirm auf der, in der am meisten hereinkam.',
        ],
    ],

];
