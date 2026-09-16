<?php

return [
    'group' => 'Website',

    'visitors' => 'Besucher',
    'visitors_description' => 'Menschen, nicht Besuche: wer an drei Tagen wiederkommt, ist für den Zeitraum ein Besucher und an jedem der drei Tage einer. Deshalb ergeben die Tagesbalken mehr als die Zahl darüber, und das bleibt so.',
    'sessions' => 'Besuche',
    'sessions_description' => 'Ein Mensch kann mehrere haben; ein Besuch, der eine halbe Stunde ruht und weitergeht, zählt als zwei.',
    'pageviews' => 'Seitenaufrufe',
    'pageviews_description' => 'Geöffnete Seiten. Als einzige der drei Zahlen lässt sie sich über Tage addieren.',
    'bounce_rate' => 'Absprungrate',
    'bounce_rate_description' => 'Anteil der Besuche mit genau einer Seite. Ohne Besuche gibt es keine Antwort, und keine schmeichelhafte Null.',
    'pages_per_session' => 'Seiten je Besuch',
    'pages_per_session_description' => 'Seitenaufrufe geteilt durch Besuche. Ohne Besuche keine Antwort.',
    'session_duration' => 'Besuchsdauer',
    'session_duration_description' => 'Durchschnittliche Dauer eines Besuchs. Wer eine Seite öffnet und geht, hat keinen zweiten Zeitstempel und wiegt in dieser Zahl als Null.',

    'top_pages' => 'Meistgelesene Seiten',
    'top_pages_description' => 'Die zwanzig Pfade mit den meisten Besuchern im Zeitraum, dazu wie oft sie gelesen wurden und wie oft dort der Besuch endete.',
    'referrers' => 'Woher die Besucher kamen',
    'referrers_description' => 'Die Seite, die hierher verlinkt hat. Wer die Adresse tippt, ein Lesezeichen benutzt oder aus dem Mailprogramm kommt, bringt gar keine Herkunft mit und ist eine eigene Zeile, keine Lücke.',
    'countries' => 'Besucher nach Land',
    'countries_description' => 'Nach dem Land, das der Analyse-Dienst aus der Adresse liest — dort kam die Verbindung her, nicht zwingend dort wohnt der Mensch.',
    'devices' => 'Geräte',
    'devices_description' => 'Rechner, Handy, Tablet — so, wie der Browser sich selbst beschrieben hat.',
    'browsers' => 'Browser',
    'browsers_description' => 'So, wie der Browser sich selbst beschrieben hat. Bots filtert der Analyse-Dienst, nicht dieses Addon.',

    'col_path' => 'Pfad',
    'col_referrer' => 'Herkunft',
    'col_country' => 'Land',
    'col_device' => 'Gerät',
    'col_browser' => 'Browser',
    'col_visitors' => 'Besucher',
    'col_pageviews' => 'Seitenaufrufe',
    'col_bounce_rate' => 'Absprungrate',

    'direct' => 'Direkt',

    'settings_title' => 'Website-Statistik',
    'settings_description' => 'Welche Site auf dem Analyse-Dienst diese Installation ist. Adresse und Schlüssel des Dienstes stehen in der Umgebung, denn ein Schlüssel gehört nicht auf einen Bildschirm. Leer heißt: gar keine Website-Zahlen.',
    'settings_site_id' => 'Site',
    'settings_site_id_description' => 'Die Site-Kennung, die der Analyse-Dienst dieser Website gegeben hat. Leer schaltet die ganze Gruppe Website ab.',
];
