<?php
define("DEMO", false);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

define("APP_NAME", "Tenant Live Overview");

spl_autoload_register(function (string $class): void {
    $prefix = "NHMP\\";
    $baseDir = __DIR__ . "/";
    if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = $baseDir . str_replace("\\", "/", $relative) . ".php";
    if (is_file($file)) {
        require $file;
    }
});
