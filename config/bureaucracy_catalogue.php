<?php

return [
    'schema_version' => 'bureaucracy.catalogue.1',
    'jurisdictions' => ['de-nrw-cologne' => ['timezone' => 'Europe/Berlin', 'city' => 'Cologne', 'city_aliases' => ['Köln', 'Koeln']]],
    // Plain navigation labels for the closed topic set enforced by CatalogueCompiler. Not legal content.
    'topic_labels' => ['address' => 'Address', 'residence' => 'Residence', 'tax' => 'Tax', 'health' => 'Health insurance',
        'money' => 'Money', 'family' => 'Family', 'work' => 'Work', 'education' => 'Education', 'driving' => 'Driving'],
    // Directory navigation does not confer legal-content approval.
    'action_hosts' => ['stadt-koeln.de', 'gesetze-im-internet.de', 'bamf.de', 'make-it-in-germany.com', 'eur-lex.europa.eu', 'recht.bund.de', 'bzst.de'],
];
