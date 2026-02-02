<?php

declare(strict_types=1);

namespace Claude\AgentSdk\Types;

final class PermissionResultAllow {

  public function __construct(
    public readonly ?array $updatedInput = null,
    public readonly ?array $updatedPermissions = null,
  ) {
  }

}
