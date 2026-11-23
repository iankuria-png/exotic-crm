<?php

/*
| Directory template library. A template is matched by technology keys and/or
| selectors present on the page, then supplies list, pagination and profile rules.
| Every rule can be overridden per site. Keep selectors broad enough to survive
| small theme changes; the probe reports match counts so drift is visible.
*/

return [
    'escortwp' => [
        'label' => 'EscortWP (WordPress theme)',
        'family' => 'WordPress · escortwp theme',
        'match' => [
            'tech' => ['theme:escortwp'],
            'selectors' => ['div.girl'],
        ],
        'rules' => [
            'row_selector' => 'div.girl',
            'name_selector' => '.inicslab-card-name, [itemprop="name"], .girl-name, .girl-desc-name, h3',
            'phone_selector' => 'a[href^="tel:"]',
            'whatsapp_selector' => 'a[href*="wa.me"], a[href*="whatsapp.com"]',
            'email_selector' => 'a[href^="mailto:"]',
            'link_selector' => '.thumbwrapper > a, a[href*="/escort/"]',
            'city_selector' => '.inicslab-card-location, [itemprop="homeLocation"], .girl-desc-location',
            'age_selector' => '.girl-age, .inicslab-card-age',
            'image_selector' => 'img[itemprop="image"], .thumbwrapper img',
        ],
        'pagination' => [
            'next_selector' => 'a.next.page-numbers, a[rel="next"], link[rel="next"]',
            'pattern' => '{base}page/{n}/',
        ],
        'profile' => [
            'phone_selector' => 'a[href^="tel:"], .escortphone a, .phone a',
            'whatsapp_selector' => 'a[href*="wa.me"], a[href*="whatsapp.com"]',
            'name_selector' => 'h1, .profile-title h3',
            'city_selector' => '[itemprop="homeLocation"], .escort-location',
            'age_selector' => '.age, .profile-age',
        ],
    ],

    'laravel_escort_items' => [
        'label' => 'Escort directory (Laravel “escort-item-one” family)',
        'family' => 'Laravel · KenyaRaha / MombasaHot / NairobiHot style',
        'match' => [
            'tech' => ['laravel'],
            'selectors' => ['li.escort-item-one'],
        ],
        'rules' => [
            'row_selector' => 'li.escort-item-one',
            'name_selector' => '.profile-name h5 a, .profile-name a, .profile-name',
            'phone_selector' => 'a[href^="tel:"]',
            'whatsapp_selector' => 'a[href*="wa.me"], a[href*="whatsapp.com"]',
            'email_selector' => 'a[href^="mailto:"]',
            'link_selector' => '.profile-name h5 a, .profile-name a, .big-img a',
            'city_selector' => '.location, .city',
            'age_selector' => '.age',
            'image_selector' => '.big-img img, img',
            'city_from_url' => '#escort-from-([a-z0-9-]+?)(?:-kenya|-tanzania|-uganda|-nigeria|-ghana)?/#',
        ],
        'pagination' => [
            'next_selector' => 'a[rel="next"], .pagination a.next, .pagination li:last-child a',
            'pattern' => '{url}?page={n}',
        ],
        'profile' => [
            'phone_selector' => 'a[href^="tel:"]',
            'whatsapp_selector' => 'a[href*="wa.me"], a[href*="whatsapp.com"]',
            'name_selector' => 'h1, .profile-name',
            'city_selector' => '.location, .city',
            'age_selector' => '.age',
        ],
    ],

    'schema_person_cards' => [
        'label' => 'Schema.org Person cards',
        'family' => 'Any CMS using itemtype="Person" listings',
        'match' => [
            'tech' => [],
            'selectors' => ['[itemtype*="schema.org/Person"]'],
        ],
        'rules' => [
            'row_selector' => '[itemtype*="schema.org/Person"]',
            'name_selector' => '[itemprop="name"]',
            'phone_selector' => '[itemprop="telephone"], a[href^="tel:"]',
            'whatsapp_selector' => 'a[href*="wa.me"], a[href*="whatsapp.com"]',
            'email_selector' => '[itemprop="email"], a[href^="mailto:"]',
            'link_selector' => 'a[href]',
            'city_selector' => '[itemprop="homeLocation"], [itemprop="address"]',
            'image_selector' => '[itemprop="image"], img',
        ],
        'pagination' => [
            'next_selector' => 'a[rel="next"], link[rel="next"], a.next',
        ],
        'profile' => [
            'phone_selector' => '[itemprop="telephone"], a[href^="tel:"]',
            'whatsapp_selector' => 'a[href*="wa.me"], a[href*="whatsapp.com"]',
        ],
    ],
];
