<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

spl_autoload_register(function (string $class): void {
  $prefix = 'Claude\\AgentSdk\\Tests\\';
  $baseDir = __DIR__ . '/';
  if (str_starts_with($class, $prefix)) {
    $relative = substr($class, strlen($prefix));
    $path = $baseDir . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
      require_once $path;
    }
  }
});
