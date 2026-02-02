<?php

declare(strict_types=1);

namespace Claude\AgentSdk\Types;

final class PermissionResultDeny {

  public function __construct(
    public readonly string $message,
    public readonly bool $interrupt = false,
  ) {
  }

}
