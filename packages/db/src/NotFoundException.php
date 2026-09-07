<?php

namespace Washi\Db;

/**
 * Thrown by `Entity::findOrFail()` and `Query::firstOrFail()`.
 * The HTTP layer (washi/washi) maps it to a 404 response; the db package itself knows nothing about HTTP.
 */
class NotFoundException extends \RuntimeException {}
