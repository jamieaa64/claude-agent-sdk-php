<?php

declare(strict_types=1);

namespace Claude\AgentSdk\Transport;

interface TransportInterface {

  public function connect(): void;

  /**
   * @return iterable<array>
   */
  public function readMessages(): iterable;

  public function write(string $data): void;

  public function closeInput(): void;

  public function close(): void;

}
