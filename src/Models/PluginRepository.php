<?php

namespace Board\Marketplace\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * An extra composer source for the plugins project — the marketplace equivalent
 * of a composer.json `repositories` entry. Lets an instance install plugins that
 * are not on Packagist (private VCS repo, self-hosted Satis/composer registry).
 * Written into the managed plugins manifest before every composer operation.
 */
#[Fillable(['type', 'url'])]
class PluginRepository extends Model
{
    /** @var array<int, string> The composer repository types the UI offers. */
    public const TYPES = ['vcs', 'composer'];
}
