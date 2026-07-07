<?php

namespace Board\Marketplace;

use RuntimeException;

/**
 * Raised when a marketplace install/update cannot proceed (no release, SDK
 * incompatibility, an unconfirmed breaking update, a download/extract failure…).
 * The message is user-facing.
 */
class PluginInstallException extends RuntimeException {}
