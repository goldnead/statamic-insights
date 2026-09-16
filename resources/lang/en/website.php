<?php

return [
    'group' => 'Website',

    'visitors' => 'Visitors',
    'visitors_description' => 'People, not visits: the same person coming back on three days is one visitor for the period and one on each of the three days. Which is why the daily bars add up to more than the total above them, and always will.',
    'sessions' => 'Sessions',
    'sessions_description' => 'Visits. One person can have several; a visit that goes quiet for half an hour and resumes is counted as two.',
    'pageviews' => 'Pageviews',
    'pageviews_description' => 'Pages opened. The only one of the three that does add up across days.',
    'bounce_rate' => 'Bounce rate',
    'bounce_rate_description' => 'Share of sessions that saw one page and nothing else. No answer in a window with no sessions, rather than a flattering nought.',
    'pages_per_session' => 'Pages per session',
    'pages_per_session_description' => 'Pageviews divided by sessions. No answer in a window with no sessions.',
    'session_duration' => 'Session length',
    'session_duration_description' => 'Average time a visit lasted. A visit that opened one page and left has no second timestamp to measure against and weighs on this figure as nought.',

    'top_pages' => 'Most read pages',
    'top_pages_description' => 'The twenty paths with the most visitors in the period, with what each one is read as and how often it was the end of the visit.',
    'referrers' => 'Where visitors came from',
    'referrers_description' => 'The site that linked here. Somebody who typed the address, followed a bookmark or came out of a mail programme arrives with no referrer at all and is one row, not a gap.',
    'countries' => 'Visitors by country',
    'countries_description' => 'By the country the analytics service reads off the address, which is where the connection came from and not necessarily where the person lives.',
    'devices' => 'Devices',
    'devices_description' => 'Desktop, mobile, tablet — as the browser described itself.',
    'browsers' => 'Browsers',
    'browsers_description' => 'As the browser described itself. Bot traffic is filtered by the analytics service, not here.',

    'col_path' => 'Path',
    'col_referrer' => 'Referrer',
    'col_country' => 'Country',
    'col_device' => 'Device',
    'col_browser' => 'Browser',
    'col_visitors' => 'Visitors',
    'col_pageviews' => 'Pageviews',
    'col_bounce_rate' => 'Bounce rate',

    'direct' => 'Direct',

    'settings_title' => 'Website statistics',
    'settings_description' => 'Which site on the analytics service this installation is. The address of the service and its key are set in the environment, because a key is a credential and does not belong on a screen. Empty means no website figures at all.',
    'settings_site_id' => 'Site',
    'settings_site_id_description' => 'The site id the analytics service gave this website. Empty switches the whole Website group off.',
];
