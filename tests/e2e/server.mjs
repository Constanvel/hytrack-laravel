// Seeds the database named in DB_DATABASE, keeps a snapshot next to it for per-test resets
// (see hytrack.spec.ts), then serves Laravel with PHP's built-in server.
// `php artisan serve` is not used because it drops these environment variables.
import { execFileSync, spawn } from 'node:child_process';
import { copyFileSync, writeFileSync } from 'node:fs';

const { DB_DATABASE: database, E2E_PORT: port } = process.env;
writeFileSync(database, '');
execFileSync('php', ['artisan', 'migrate:fresh', '--seed', '--force'], { stdio: 'inherit' });
copyFileSync(database, database.replace(/\.sqlite$/, '-seed.sqlite'));

const router = '../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php';
// PHP's built-in server handles one request at a time, so opcache matters here.
const opcache = ['-d', 'zend_extension=opcache', '-d', 'opcache.enable_cli=1'];
spawn('php', [...opcache, '-S', `127.0.0.1:${port}`, router], {
  cwd: 'public',
  stdio: 'inherit',
}).on('exit', (code) => process.exit(code ?? 0));
