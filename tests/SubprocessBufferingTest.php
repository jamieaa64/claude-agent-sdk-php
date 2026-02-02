<?php

declare(strict_types=1);

namespace Claude\AgentSdk\Tests;

use Claude\AgentSdk\ClaudeAgentOptions;
use Claude\AgentSdk\Exceptions\CLIJSONDecodeError;
use Claude\AgentSdk\Transport\SubprocessCLITransport;
use PHPUnit\Framework\TestCase;

final class SubprocessBufferingTest extends TestCase {

  public function testMultipleJsonObjectsOnSingleLine(): void {
    $json1 = json_encode(['type' => 'message', 'id' => 'msg1', 'content' => 'First message']);
    $json2 = json_encode(['type' => 'result', 'id' => 'res1', 'status' => 'completed']);

    $transport = new SubprocessCLITransport('test', new ClaudeAgentOptions(cliPath: '/usr/bin/claude'));
    $this->setBuffer($transport, $json1 . "\n" . $json2);

    $messages = $this->drainAndFlush($transport);
    $this->assertCount(2, $messages);
    $this->assertSame('message', $messages[0]['type']);
    $this->assertSame('result', $messages[1]['type']);
  }

  public function testJsonWithEmbeddedNewlines(): void {
    $json1 = json_encode(['type' => 'message', 'content' => "Line 1\nLine 2\nLine 3"]);
    $json2 = json_encode(['type' => 'result', 'data' => "Some\nMultiline\nContent"]);

    $transport = new SubprocessCLITransport('test', new ClaudeAgentOptions(cliPath: '/usr/bin/claude'));
    $this->setBuffer($transport, $json1 . "\n" . $json2);

    $messages = $this->drainAndFlush($transport);
    $this->assertCount(2, $messages);
    $this->assertSame("Line 1\nLine 2\nLine 3", $messages[0]['content']);
    $this->assertSame("Some\nMultiline\nContent", $messages[1]['data']);
  }

  public function testMultipleNewlinesBetweenObjects(): void {
    $json1 = json_encode(['type' => 'message', 'id' => 'msg1']);
    $json2 = json_encode(['type' => 'result', 'id' => 'res1']);

    $transport = new SubprocessCLITransport('test', new ClaudeAgentOptions(cliPath: '/usr/bin/claude'));
    $this->setBuffer($transport, $json1 . "\n\n\n" . $json2);

    $messages = $this->drainAndFlush($transport);
    $this->assertCount(2, $messages);
  }

  public function testSplitJsonAcrossReads(): void {
    $jsonObj = json_encode([
      'type' => 'assistant',
      'message' => [
        'content' => [
          ['type' => 'text', 'text' => str_repeat('x', 1000)],
          ['type' => 'tool_use', 'id' => 'tool_123', 'name' => 'Read', 'input' => ['file_path' => '/test.txt']],
        ],
      ],
    ]);

    $part1 = substr($jsonObj, 0, 100);
    $part2 = substr($jsonObj, 100, 200);
    $part3 = substr($jsonObj, 300);

    $transport = new SubprocessCLITransport('test', new ClaudeAgentOptions(cliPath: '/usr/bin/claude'));
    $this->setBuffer($transport, $part1);

    $messages = $this->drainOnly($transport);
    $this->assertCount(0, $messages);

    $this->appendBuffer($transport, $part2);
    $this->appendBuffer($transport, $part3);
    $messages = $this->drainAndFlush($transport);
    $this->assertCount(1, $messages);
    $this->assertSame('assistant', $messages[0]['type']);
  }

  public function testLargeMinifiedJson(): void {
    $largeData = ['data' => array_map(fn ($i) => ['id' => $i, 'value' => str_repeat('x', 100)], range(0, 200))];
    $jsonObj = [
      'type' => 'user',
      'message' => [
        'role' => 'user',
        'content' => [
          [
            'tool_use_id' => 'toolu_016fed1NhiaMLqnEvrj5NUaj',
            'type' => 'tool_result',
            'content' => json_encode($largeData),
          ],
        ],
      ],
    ];

    $complete = json_encode($jsonObj);
    $chunkSize = 1024;
    $chunks = str_split($complete, $chunkSize);

    $transport = new SubprocessCLITransport('test', new ClaudeAgentOptions(cliPath: '/usr/bin/claude'));
    $this->setBuffer($transport, '');
    foreach ($chunks as $chunk) {
      $this->appendBuffer($transport, $chunk);
    }

    $messages = $this->drainAndFlush($transport);
    $this->assertCount(1, $messages);
    $this->assertSame('user', $messages[0]['type']);
  }

  public function testBufferSizeExceeded(): void {
    $huge = '{"data": "' . str_repeat('x', 1024 * 1024 + 1000);
    $transport = new SubprocessCLITransport('test', new ClaudeAgentOptions(cliPath: '/usr/bin/claude'));
    $this->setBuffer($transport, $huge);

    $this->expectException(CLIJSONDecodeError::class);
    $this->drainOnly($transport);
  }

  public function testBufferSizeOption(): void {
    $huge = '{"data": "' . str_repeat('x', 1024);
    $transport = new SubprocessCLITransport('test', new ClaudeAgentOptions(
      cliPath: '/usr/bin/claude',
      maxBufferSize: 512,
    ));
    $this->setBuffer($transport, $huge);

    $this->expectException(CLIJSONDecodeError::class);
    $this->drainOnly($transport);
  }

  public function testMixedCompleteAndSplitJson(): void {
    $msg1 = json_encode(['type' => 'system', 'subtype' => 'start']);
    $largeMsg = ['type' => 'assistant', 'message' => ['content' => [['type' => 'text', 'text' => str_repeat('y', 5000)]]]];
    $largeJson = json_encode($largeMsg);
    $msg3 = json_encode(['type' => 'system', 'subtype' => 'end']);

    $lines = [
      $msg1 . "\n",
      substr($largeJson, 0, 1000),
      substr($largeJson, 1000, 2000),
      substr($largeJson, 3000) . "\n" . $msg3,
    ];

    $transport = new SubprocessCLITransport('test', new ClaudeAgentOptions(cliPath: '/usr/bin/claude'));
    $this->setBuffer($transport, '');
    foreach ($lines as $line) {
      $this->appendBuffer($transport, $line);
    }

    $messages = $this->drainAndFlush($transport);
    $this->assertCount(3, $messages);
    $this->assertSame('system', $messages[0]['type']);
    $this->assertSame('assistant', $messages[1]['type']);
    $this->assertSame('system', $messages[2]['type']);
  }

  private function drainOnly(SubprocessCLITransport $transport): array {
    return $this->invokeDrain($transport, false);
  }

  private function drainAndFlush(SubprocessCLITransport $transport): array {
    return $this->invokeDrain($transport, true);
  }

  private function invokeDrain(SubprocessCLITransport $transport, bool $flush): array {
    $messages = [];

    $ref = new \ReflectionClass($transport);
    $guard = $ref->getMethod('guardBufferSize');
    $guard->setAccessible(true);
    $guard->invoke($transport);

    $drain = $ref->getMethod('drainBuffer');
    $drain->setAccessible(true);
    foreach ($drain->invoke($transport) as $msg) {
      $messages[] = $msg;
    }

    if ($flush) {
      $flushMethod = $ref->getMethod('flushBuffer');
      $flushMethod->setAccessible(true);
      foreach ($flushMethod->invoke($transport) as $msg) {
        $messages[] = $msg;
      }
    }

    return $messages;
  }

  private function setBuffer(SubprocessCLITransport $transport, string $value): void {
    $ref = new \ReflectionClass($transport);
    $prop = $ref->getProperty('stdoutBuffer');
    $prop->setAccessible(true);
    $prop->setValue($transport, $value);
  }

  private function appendBuffer(SubprocessCLITransport $transport, string $value): void {
    $ref = new \ReflectionClass($transport);
    $prop = $ref->getProperty('stdoutBuffer');
    $prop->setAccessible(true);
    $current = (string) $prop->getValue($transport);
    $prop->setValue($transport, $current . $value);
  }

}
