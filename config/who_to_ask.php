<?php

/**
 * Who to ask for everyday things Composer can't answer itself (owner, 4 Nov 2026). Each line was
 * read on its linked official page on 4 Nov 2026. No prices or hours here: the page gives them.
 */
return [
    'night_pharmacy' => [
        'who' => 'Pharmacy emergency service (Apothekennotdienst)',
        'says' => 'At night, on Sundays and on holidays, the pharmacy emergency service finds the pharmacy on duty nearest you.',
        'links' => [['label' => 'Find the pharmacy on duty', 'href' => 'https://www.aponet.de/notdienstsuche']],
    ],
    'doctor_now' => [
        'who' => 'Medical on-call service (116 117)',
        'says' => 'For something that can\'t wait but isn\'t life-threatening, call the medical on-call service on 116 117. In a life-threatening emergency, call 112.',
        'links' => [['label' => '116117.de', 'href' => 'https://www.116117.de/de/index.php']],
    ],
    'find_doctor' => [
        'who' => '116 117 doctor search (Arztsuche)',
        'says' => 'The 116 117 doctor search finds doctors and psychotherapists near you. Whether a practice speaks English is for the practice to say.',
        'links' => [['label' => 'Search for a doctor', 'href' => 'https://arztsuche.116117.de/']],
    ],
    'lost_property' => [
        'who' => 'Cologne\'s lost property office (Fundbüro)',
        'says' => 'Things lost on a KVB bus, tram or stop go to the city\'s lost property office (Fundbüro), which has an online search. They can take a while to get there.',
        'links' => [['label' => 'Fundbüro', 'href' => 'https://www.stadt-koeln.de/service/adressen/fundbuero'], ['label' => 'KVB on lost items', 'href' => 'https://www.kvb.koeln/service/fundbuero/']],
    ],
    'ticket_prices' => [
        'who' => 'KVB tickets',
        'says' => 'KVB\'s ticket pages cover its tickets and how to buy them, and Deutsche Bahn\'s page gives the Deutschlandticket\'s current price.',
        'links' => [['label' => 'KVB tickets', 'href' => 'https://www.kvb.koeln/tickets/'], ['label' => 'Deutschlandticket', 'href' => 'https://www.bahn.de/angebot/regio/deutschland-ticket']],
    ],
    'rent_problem' => [
        'who' => 'Mieterverein Köln',
        'says' => 'For a rent deposit (Kaution) or another problem with a landlord, the tenants\' association Mieterverein Köln gives legal advice to its members. Its number is 0221 20237-0.',
        'links' => [['label' => 'Mieterverein Köln', 'href' => 'https://www.mieterverein-koeln.de/']],
    ],
    'feeling_low' => [
        'who' => 'TelefonSeelsorge',
        'says' => 'If things feel heavy, TelefonSeelsorge is there to talk, day and night, free and anonymous: 0800 1110111, 0800 1110222 or 116 123. In danger, call 112.',
        'links' => [['label' => 'TelefonSeelsorge', 'href' => 'https://www.telefonseelsorge.de/']],
    ],
];
