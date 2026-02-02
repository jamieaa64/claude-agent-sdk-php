<?php

declare(strict_types=1);

namespace Claude\AgentSdk\Tests;

use Claude\AgentSdk\Exceptions\CLIJSONDecodeError;
use Claude\AgentSdk\Exceptions\MessageParseError;
use PHPUnit\Framework\TestCase;

final class ErrorsTest extends TestCase {

  public function testMessageParseErrorKeepsRaw(): void {
    $error = new MessageParseError('bad', ['foo' => 'bar']);
    $this->assertSame('bad', $error->getMessage());
    $this->assertSame(['foo' => 'bar'], $error->getRaw());
  }

  public function testCliJsonDecodeErrorKeepsLine(): void {
    $error = new CLIJSONDecodeError('bad json', '{broken');
    $this->assertSame('{broken', $error->getLineText());
  }

}
