<?php

declare(strict_types=1);

namespace Claude\AgentSdk\Tests\Support;

use Claude\AgentSdk\Transport\TransportInterface;

final class FakeTransport implements TransportInterface {

  /** @var array<int, array> */
  private array $messages = [];
  /** @var array<int, string> */
  public array $writes = [];
  public bool $connected = false;
  public bool $closed = false;
  public bool $inputClosed = false;
  public ?\Closure $onWrite = null;

  public function __construct(array $messages = []) {
    $this->messages = $messages;
  }

  public function connect(): void {
    $this->connected = true;
  }

  public function readMessages(): iterable {
    $index = 0;
    while ($index < count($this->messages)) {
      yield $this->messages[$index];
      $index++;
    }
  }

  public function write(string $data): void {
    $this->writes[] = $data;
    if ($this->onWrite) {
      ($this->onWrite)($data, $this);
    }
  }

  public function closeInput(): void {
    $this->inputClosed = true;
  }

  public function close(): void {
    $this->closed = true;
  }

  public function pushMessage(array $message): void {
    $this->messages[] = $message;
  }

}
