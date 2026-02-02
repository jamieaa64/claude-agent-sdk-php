<?php

declare(strict_types=1);

namespace Claude\AgentSdk\Exceptions;

final class MessageParseError extends SdkException {

  public function __construct(string $message, private readonly array $raw) {
    parent::__construct($message);
  }

  public function getRaw(): array {
    return $this->raw;
  }

}
