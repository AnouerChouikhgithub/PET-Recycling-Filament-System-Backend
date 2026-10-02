<?php

use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Process\Process;

require dirname(__DIR__).'/vendor/autoload.php';

(new Dotenv())->bootEnv(dirname(__DIR__).'/.env');

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}

/**
 * Repeatability contract: `php bin/phpunit` must be green on a dirty,
 * emptied, or completely missing test schema.
 *
 * DAMA wraps every test in a rolled-back transaction, but it cannot invent
 * the schema — so when the test database has no migrations table, this
 * bootstrap repairs itself: create the DB (if missing), run every
 * migration, then prove mapping/schema consistency. A ready schema costs
 * exactly one cheap probe query.
 *
 * Everything below runs in the TEST environment only, against the single
 * DATABASE_URL of .env/.env.test + the doctrine `dbname_suffix: _test`
 * (db_3awedlou → db_3awedlou_test). It never touches the dev database.
 */
(function (): void {
    $projectDir = dirname(__DIR__);
    $console = $projectDir.'/bin/console';
    if (!is_file($console)) {
        return;
    }

    $run = static function (array $args) use ($projectDir, $console): Process {
        $process = new Process([PHP_BINARY, $console, '--env=test', '--no-debug', ...$args], $projectDir, null, null, 300);
        $process->run();

        return $process;
    };

    $probe = $run(['dbal:run-sql', '--sql', 'SELECT 1 FROM doctrine_migration_versions LIMIT 1']);
    if ($probe->isSuccessful()) {
        return; // schema is ready — the common path
    }

    // Repair path: missing database, missing migrations table, or an
    // emptied public schema.
    fwrite(STDERR, "[tests/bootstrap] test schema not ready — rebuilding (create DB + migrate)…\n");

    $create = $run(['doctrine:database:create', '--if-not-exists']);
    if (!$create->isSuccessful()) {
        fwrite(STDERR, "[tests/bootstrap] doctrine:database:create failed:\n".$create->getErrorOutput().$create->getOutput());
        exit(1);
    }

    $migrate = $run(['doctrine:migrations:migrate', '--all-or-nothing', '--no-interaction']);
    if (!$migrate->isSuccessful()) {
        fwrite(STDERR, "[tests/bootstrap] doctrine:migrations:migrate failed:\n".$migrate->getErrorOutput().$migrate->getOutput());
        exit(1);
    }

    $validate = $run(['doctrine:schema:validate', '--skip-sync']);
    if (!$validate->isSuccessful()) {
        fwrite(STDERR, "[tests/bootstrap] freshly migrated schema is NOT mapping-consistent:\n".$validate->getOutput().$validate->getErrorOutput());
        exit(1);
    }

    fwrite(STDERR, "[tests/bootstrap] test schema rebuilt OK.\n");
})();
