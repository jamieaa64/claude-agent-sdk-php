<?php

declare(strict_types=1);

spl_autoload_register(function (string $class): void {
  $prefix = 'Claude\\AgentSdk\\';
  $baseDir = __DIR__ . '/src/Claude/AgentSdk/';

  if (str_starts_with($class, $prefix)) {
    $relative = substr($class, strlen($prefix));
    $path = $baseDir . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
      require_once $path;
      return;
    }

    // Fallback for grouped class files in Types/.
    if (str_starts_with($relative, 'Types\\')) {
      $typesMessage = $baseDir . 'Types/Message.php';
      if (is_file($typesMessage)) {
        require_once $typesMessage;
      }
      $typesBlocks = $baseDir . 'Types/Blocks.php';
      if (is_file($typesBlocks)) {
        require_once $typesBlocks;
      }
    }
  }
});

require_once __DIR__ . '/src/Claude/AgentSdk/McpSdk.php';
