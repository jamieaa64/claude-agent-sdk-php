<?php

declare(strict_types=1);

namespace Claude\AgentSdk\Exceptions;

final class ProcessError extends SdkException {

  public function __construct(string $message, private readonly int $exitCode) {
    parent::__construct($message);
  }

  public function getExitCode(): int {
    return $this->exitCode;
  }

}
