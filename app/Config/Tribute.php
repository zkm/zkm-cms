<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * The Star Trek memorial theme: an LCARS-style skin (public/theme/tribute.css),
 * a banner under the header on every page, and the memorial roll on the home page.
 *
 * Turn it off in .env with `tribute.enabled = false`, then flush the page cache.
 */
class Tribute extends BaseConfig
{
    public bool $enabled = true;

    /**
     * The person the banner is for.
     *
     * @var array{name: string, role: string, years: string, line: string}
     */
    public array $featured = [
        'name'  => 'Sir Patrick Stewart',
        'role'  => 'Captain Jean-Luc Picard',
        'years' => '1940 – 2026',
        'line'  => 'Make it so.',
    ];

    /**
     * Series regulars and principal film cast we've lost, most recent first.
     * Years are the year of passing.
     *
     * @var list<array{name: string, role: string, credits: string, year: int}>
     */
    public array $crew = [
        ['name' => 'Patrick Stewart',           'role' => 'Jean-Luc Picard',                    'credits' => 'TNG · Films · Picard',       'year' => 2026],
        ['name' => 'Walter Koenig',             'role' => 'Pavel Chekov',                       'credits' => 'TOS · Films',                'year' => 2026],
        ['name' => 'Nichelle Nichols',          'role' => 'Nyota Uhura',                        'credits' => 'TOS · TAS · Films',          'year' => 2022],
        ['name' => 'René Auberjonois',          'role' => 'Odo',                                'credits' => 'Deep Space Nine',            'year' => 2019],
        ['name' => 'Aron Eisenberg',            'role' => 'Nog',                                'credits' => 'Deep Space Nine',            'year' => 2019],
        ['name' => 'Anton Yelchin',             'role' => 'Pavel Chekov',                       'credits' => 'Kelvin-timeline films',      'year' => 2016],
        ['name' => 'Leonard Nimoy',             'role' => 'Spock',                              'credits' => 'TOS · TAS · Films',          'year' => 2015],
        ['name' => 'Grace Lee Whitney',         'role' => 'Janice Rand',                        'credits' => 'TOS · Films',                'year' => 2015],
        ['name' => 'Majel Barrett-Roddenberry', 'role' => 'Christine Chapel · Lwaxana Troi',    'credits' => 'TOS through Enterprise',     'year' => 2008],
        ['name' => 'James Doohan',              'role' => 'Montgomery Scott',                   'credits' => 'TOS · TAS · Films',          'year' => 2005],
        ['name' => 'DeForest Kelley',           'role' => 'Leonard McCoy',                      'credits' => 'TOS · TAS · Films',          'year' => 1999],
        ['name' => 'Persis Khambatta',          'role' => 'Ilia',                               'credits' => 'The Motion Picture',         'year' => 1998],
        ['name' => 'Mark Lenard',               'role' => 'Sarek',                              'credits' => 'TOS · TNG · Films',          'year' => 1996],
        ['name' => 'Jeffrey Hunter',            'role' => 'Christopher Pike',                   'credits' => '“The Cage”',                 'year' => 1969],
    ];

    /**
     * Guest and recurring players, shown as a single line under the roll.
     *
     * @var list<array{name: string, role: string, year: int}>
     */
    public array $alsoRemembered = [
        ['name' => 'Patti Yasutake',      'role' => 'Alyssa Ogawa',   'year' => 2024],
        ['name' => 'Gary Graham',         'role' => 'Soval',          'year' => 2024],
        ['name' => 'Annie Wersching',     'role' => 'the Borg Queen', 'year' => 2023],
        ['name' => 'Kirstie Alley',       'role' => 'Saavik',         'year' => 2022],
        ['name' => 'Christopher Plummer', 'role' => 'General Chang',  'year' => 2021],
        ['name' => 'Ricardo Montalbán',   'role' => 'Khan',           'year' => 2009],
    ];
}
