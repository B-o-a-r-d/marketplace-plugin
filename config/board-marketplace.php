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

];
