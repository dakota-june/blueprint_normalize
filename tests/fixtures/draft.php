<?php
// The draft as Blueprint's YAML parser would hand it to the lexers.
return [
    'models' => [
        'ContactType' => [
            'name' => 'string normalize:trim,upper',
            'notes' => 'text nullable normalize_on_set:squish,limit:500;...',
            'sort' => 'integer',
            'published_at' => 'timestamp nullable',
        ],
        'Plain' => [
            'label' => 'string',
        ],
    ],
];
