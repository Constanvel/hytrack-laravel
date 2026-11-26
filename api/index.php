<?php

// Vercel entry point. The function filesystem is read-only except /tmp, so every
// file Laravel writes at runtime goes there. Vercel also has no deploy-time migration step,
// so each new instance migrates the database itself (config/hytrack.php). Real env vars still win.
$defaults = [
    'HYTRACK_AUTO_MIGRATE' => 'true',
    'APP_CONFIG_CACHE' => '/tmp/config.php',
    'APP_EVENTS_CACHE' => '/tmp/events.php',
    'APP_PACKAGES_CACHE' => '/tmp/packages.php',
    'APP_ROUTES_CACHE' => '/tmp/routes.php',
    'APP_SERVICES_CACHE' => '/tmp/services.php',
    'VIEW_COMPILED_PATH' => '/tmp/views',
    'LOG_CHANNEL' => 'stderr',
];

foreach ($defaults as $key => $value) {
    if (getenv($key) === false) {
        putenv("$key=$value");
        $_ENV[$key] = $_SERVER[$key] = $value;
    }
}

require __DIR__.'/../public/index.php';
