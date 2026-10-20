<?php

use App\Kernel;

/*
 * PHP's built-in web server (`php -S`, and therefore `symfony serve` too) does
 * NOT copy the process environment into $_SERVER/$_ENV, while every other SAPI
 * (php-fpm, Apache, CGI) does. Symfony Runtime + Dotenv decide "is this
 * variable already set by the operator?" by looking in those superglobals, so
 * under `php -S` a shell value was invisible and `.env.local` silently won:
 * `AGENT_ENABLED=true php -S ...` had no effect at all (BUGS-TO-FIX F7).
 *
 * Mirroring the process environment first restores the documented precedence
 * (real environment > .env.local > .env) for the built-in server, exactly as
 * it already behaves under php-fpm. Only missing keys are filled in, and only
 * in this SAPI.
 */
if ('cli-server' === \PHP_SAPI) {
    foreach (getenv() as $name => $value) {
        $_ENV[$name] ??= $value;
        $_SERVER[$name] ??= $value;
    }
}

require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

return static function (array $context) {
    return new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
};
