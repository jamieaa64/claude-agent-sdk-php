<?php

declare(strict_types=1);

namespace Claude\AgentSdk\Exceptions;

final class CLIJSONDecodeError extends SdkException {

  public function __construct(string $message, private readonly string $lineText) {
    parent::__construct($message);
  }

  public function getLineText(): string {
    return $this->lineText;
  }

}
