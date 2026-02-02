<?php

declare(strict_types=1);

namespace Claude\AgentSdk\Types;

final class ToolPermissionContext {

  public function __construct(
    public readonly array $suggestions = [],
    public readonly ?string $blockedPath = null,
  ) {
  }

}
