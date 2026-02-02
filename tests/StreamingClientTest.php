<?php

declare(strict_types=1);

namespace Claude\AgentSdk\Tests;

use Claude\AgentSdk\ClaudeAgentOptions;
use Claude\AgentSdk\Client;
use Claude\AgentSdk\Tests\Support\FakeTransport;
use Claude\AgentSdk\Types\ResultMessage;
use PHPUnit\Framework\TestCase;

final class StreamingClientTest extends TestCase {

  public function testConnectWithStringPrompt(): void {
    $transport = new FakeTransport();
    $client = new Client(new ClaudeAgentOptions(), $transport);
    $client->connect('Hello Claude');

    $this->assertTrue($transport->connected);
  }

  public function testConnectWithIterablePrompt(): void {
    $transport = $this->makeTransportWithInitResponse();
    $client = new Client(new ClaudeAgentOptions(), $transport);

    $stream = (function (): iterable {
      yield [
        'type' => 'user',
        'message' => ['role' => 'user', 'content' => 'Hi'],
      ];
    })();

    $client->connect($stream);
    $this->assertTrue($transport->connected);
  }

  public function testQueryWritesUserMessage(): void {
    $transport = $this->makeTransportWithInitResponse();
    $client = new Client(new ClaudeAgentOptions(), $transport);
    $client->connect($this->emptyStream());

    $client->query('Test message');

    $found = false;
    foreach ($transport->writes as $write) {
      $decoded = json_decode($write, true);
      if (($decoded['type'] ?? null) === 'user') {
        $found = true;
        $this->assertSame('Test message', $decoded['message']['content']);
      }
    }
    $this->assertTrue($found);
  }

  public function testReceiveMessages(): void {
    $transport = $this->makeTransportWithInitResponse([
      [
        'type' => 'assistant',
        'message' => [
          'content' => [
            ['type' => 'text', 'text' => 'Hello!'],
          ],
        ],
      ],
      [
        'type' => 'user',
        'message' => [
          'content' => 'Hi there',
        ],
      ],
    ]);

    $client = new Client(new ClaudeAgentOptions(), $transport);
    $client->connect($this->emptyStream());

    $messages = iterator_to_array($client->receiveMessages());
    $this->assertCount(2, $messages);
    $this->assertSame('assistant', $messages[0]->getType());
    $this->assertSame('user', $messages[1]->getType());
  }

  public function testReceiveResponseStopsAtResult(): void {
    $transport = $this->makeTransportWithInitResponse([
      [
        'type' => 'assistant',
        'message' => [
          'content' => [
            ['type' => 'text', 'text' => 'Answer'],
          ],
        ],
      ],
      [
        'type' => 'result',
        'subtype' => 'success',
        'duration_ms' => 1000,
        'duration_api_ms' => 800,
        'is_error' => false,
        'num_turns' => 1,
        'session_id' => 'test',
      ],
      [
        'type' => 'assistant',
        'message' => [
          'content' => [
            ['type' => 'text', 'text' => 'Should not see this'],
          ],
        ],
      ],
    ]);

    $client = new Client(new ClaudeAgentOptions(), $transport);
    $client->connect($this->emptyStream());

    $messages = iterator_to_array($client->receiveResponse());
    $this->assertCount(2, $messages);
    $this->assertInstanceOf(ResultMessage::class, $messages[1]);
  }

  public function testInterruptSendsControlRequest(): void {
    $transport = $this->makeTransportWithInitResponse();
    $client = new Client(new ClaudeAgentOptions(), $transport);
    $client->connect($this->emptyStream());

    $client->interrupt();

    $found = false;
    foreach ($transport->writes as $write) {
      $decoded = json_decode($write, true);
      if (($decoded['type'] ?? null) === 'control_request' && ($decoded['request']['subtype'] ?? null) === 'interrupt') {
        $found = true;
        break;
      }
    }
    $this->assertTrue($found);
  }

  public function testQueryNotConnectedThrows(): void {
    $this->expectException(\RuntimeException::class);
    $client = new Client(new ClaudeAgentOptions(), new FakeTransport());
    $client->query('Test');
  }

  public function testInterruptNotConnectedThrows(): void {
    $this->expectException(\RuntimeException::class);
    $client = new Client(new ClaudeAgentOptions(), new FakeTransport());
    $client->interrupt();
  }

  private function makeTransportWithInitResponse(array $messages = []): FakeTransport {
    $transport = new FakeTransport($messages);
    $transport->onWrite = function (string $data, FakeTransport $transport): void {
      $decoded = json_decode($data, true);
      if (!is_array($decoded) || ($decoded['type'] ?? null) !== 'control_request') {
        return;
      }
      if (($decoded['request']['subtype'] ?? null) === 'initialize') {
        $transport->pushMessage([
          'type' => 'control_response',
          'response' => [
            'subtype' => 'success',
            'request_id' => $decoded['request_id'],
            'response' => [
              'commands' => [],
              'output_style' => 'default',
            ],
          ],
        ]);
      }
      if (($decoded['request']['subtype'] ?? null) === 'interrupt') {
        $transport->pushMessage([
          'type' => 'control_response',
          'response' => [
            'subtype' => 'success',
            'request_id' => $decoded['request_id'],
            'response' => [],
          ],
        ]);
      }
    };
    return $transport;
  }

  private function emptyStream(): iterable {
    return (function (): iterable { if (false) { yield []; } })();
  }

}
