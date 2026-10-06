<?php

declare(strict_types=1);

// Regression test: datasource urls become valid DBAL params. PDO prefixes
// unknown to DBAL ("mysql:") used to reach DriverManager verbatim
// (UnknownDriver), and scalar doctrine.* options were silently dropped.
// Run with: php tests/run.php

use dev\winterframework\doctrine\common\DoctrineDsn;
use WinterDoctrineTest\Support\Checks;

require_once __DIR__ . '/bootstrap.php';

$p = DoctrineDsn::toParams('mysql:host=localhost;port=3307;dbname=testdb');
Checks::check('mysql: prefix maps to pdo_mysql', $p['driver'] === 'pdo_mysql');
Checks::check('DSN keys become params', $p['host'] === 'localhost' && $p['port'] === '3307' && $p['dbname'] === 'testdb');

Checks::check('DBAL driver prefixes pass through (pgsql)', DoctrineDsn::toParams('pgsql:host=db')['driver'] === 'pgsql');
Checks::check('DBAL driver prefixes pass through (pdo_pgsql)', DoctrineDsn::toParams('pdo_pgsql:host=db')['driver'] === 'pdo_pgsql');

$mem = DoctrineDsn::toParams('sqlite::memory:');
Checks::check('sqlite memory DSN', ($mem['memory'] ?? false) === true && !isset($mem['path']));
Checks::check('sqlite file DSN', DoctrineDsn::toParams('sqlite:/tmp/app.db')['path'] === '/tmp/app.db');

$params = DoctrineDsn::connectionParams(
    'mysql:host=localhost;user=dsnuser;password=dsnpass',
    'cfguser',
    '',
    ['charset' => 'utf8mb4', 'serverVersion' => '8.0', 'driverOptions' => [], 'empty' => '', 'nothing' => null]
);
Checks::check('scalar doctrine options kept (charset)', ($params['charset'] ?? null) === 'utf8mb4');
Checks::check('scalar doctrine options kept (serverVersion)', ($params['serverVersion'] ?? null) === '8.0');
Checks::check('empty doctrine options skipped', !isset($params['driverOptions']) && !isset($params['empty']) && !isset($params['nothing']));
Checks::check('configured username wins over DSN user', $params['user'] === 'cfguser');
Checks::check('DSN password used when none configured', $params['password'] === 'dsnpass');

$override = DoctrineDsn::connectionParams('mysql:host=a', '', '', ['driver' => 'mysqli']);
Checks::check('doctrine.driver overrides the DSN driver', $override['driver'] === 'mysqli');
