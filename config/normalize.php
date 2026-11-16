<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Operation checks (Blueprint integration)
    |--------------------------------------------------------------------------
    |
    | Pipelines in draft.yaml are tried on sample strings when the draft is read,
    | so a typo or an unsafe operation stops the build instead of failing on save.
    |
    | denied   Extra Stringable method names to refuse (on top of pipe, tap, dump, ...).
    | samples  Extra strings to try, e.g. text typical of your data.
    |
    */

    'denied' => [],

    'samples' => [],

    /*
    |--------------------------------------------------------------------------
    | Manifest
    |--------------------------------------------------------------------------
    |
    | What the package changed in your models, recorded so `blueprint:erase`
    | can undo it. Relative to the project root.
    |
    */

    'manifest' => '.blueprint_normalize',

];
