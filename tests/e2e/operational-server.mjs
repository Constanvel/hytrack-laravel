// Operational end-to-end server: MySQL test database (never hytrack_laravel), demo controls
// off. Resets and seeds the test database, then serves Laravel with PHP's built-in server.
import { execFileSync, spawn } from 'node:child_process';

const { DB_DATABASE: database, E2E_PORT: port } = process.env;
if (!database?.endsWith('_test'))
  throw new Error(`Refusing to use ${database}: not a _test database.`);
execFileSync('php', ['tests/MySql/reset-test-database.php'], { stdio: 'inherit' });

const router = '../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php';
const opcache = ['-d', 'zend_extension=opcache', '-d', 'opcache.enable_cli=1'];
spawn('php', [...opcache, '-S', `127.0.0.1:${port}`, router], {
  cwd: 'public',
  stdio: 'inherit',
}).on('exit', (code) => process.exit(code ?? 0));
