<?php

return [

    /*
    | The GitHub repository holding the curated plugin catalog (one markdown file
    | per plugin under plugins/, with YAML front-matter).
    */
    'catalog_repo' => env('MARKETPLACE_CATALOG_REPO', 'B-o-a-r-d/Marketplace'),

    /*
    | Optional GitHub token to raise the API rate limit for catalog reads,
    | release lookups and zipball downloads. Public repos work without it.
    */
    'api_token' => env('MARKETPLACE_GITHUB_TOKEN'),

    /*
    | The composer binary used to manage the plugins project on the persistent
    | volume (composer-sourced plugins). Must be on PATH or an absolute path.
    */
    'composer_binary' => env('MARKETPLACE_COMPOSER_BINARY', 'composer'),

];
