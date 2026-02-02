<?php

declare(strict_types=1);

namespace Claude\AgentSdk\Tests;

use Claude\AgentSdk\ClaudeAgentOptions;
use Claude\AgentSdk\Client;
use Claude\AgentSdk\Tests\Support\FakeTransport;
use Claude\AgentSdk\Types\PermissionResultAllow;
use PHPUnit\Framework\TestCase;

final class ClientControlTest extends TestCase {

  public function testHandleCanUseToolControlRequest(): void {
    $transport = $this->makeTransportWithInitResponse([
      [
        'type' => 'control_request',
        'request_id' => 'req_1',
        'request' => [
          'subtype' => 'can_use_tool',
          'tool_name' => 'bash',
          'input' => ['cmd' => 'ls'],
          'permission_suggestions' => [],
          'blocked_path' => null,
        ],
      ],
      [
        'type' => 'assistant',
        'message' => [
          'content' => [
            ['type' => 'text', 'text' => 'ok'],
          ],
        ],
      ],
    ]);

    $options = new ClaudeAgentOptions(
      canUseTool: function (string $tool, array $input, $context) {
        return new PermissionResultAllow();
      },
    );

    $client = new Client($options, $transport);
    $client->connect((function (): iterable { if (false) { yield []; } })());

    $messages = iterator_to_array($client->receiveMessages());

    $this->assertCount(1, $messages);
    $this->assertSame('assistant', $messages[0]->getType());
    $this->assertNotEmpty($transport->writes);

    $written = json_decode(end($transport->writes), true);
    $this->assertSame('control_response', $written['type']);
    $this->assertSame('success', $written['response']['subtype']);
  }

  public function testInterruptSendsControlRequestAndHandlesResponse(): void {
    $transport = $this->makeTransportWithInitResponse();
    $baseHandler = $transport->onWrite;
    $transport->onWrite = function (string $data, FakeTransport $transport) use ($baseHandler): void {
      if (is_callable($baseHandler)) {
        $baseHandler($data, $transport);
      }
      $decoded = json_decode($data, true);
      if (!is_array($decoded) || ($decoded['type'] ?? null) !== 'control_request') {
        return;
      }
      $requestId = $decoded['request_id'] ?? null;
      if (!$requestId) {
        return;
      }
      if (($decoded['request']['subtype'] ?? null) === 'interrupt') {
        $transport->pushMessage([
          'type' => 'control_response',
          'response' => [
            'subtype' => 'success',
            'request_id' => $requestId,
            'response' => [],
          ],
        ]);
      }
    };

    $client = new Client(new ClaudeAgentOptions(), $transport);
    $client->connect((function (): iterable { if (false) { yield []; } })());

    $client->interrupt();

    $this->assertNotEmpty($transport->writes);
  }

  public function testMcpMessageHandledViaServer(): void {
    $transport = $this->makeTransportWithInitResponse([
      [
        'type' => 'control_request',
        'request_id' => 'mcp-1',
        'request' => [
          'subtype' => 'mcp_message',
          'server_name' => 'sdk',
          'message' => [
            'method' => 'tools/list',
            'id' => 1,
          ],
        ],
      ],
    ]);

    $serverConfig = \Claude\AgentSdk\create_sdk_mcp_server('test', '1.0.0', [
      \Claude\AgentSdk\tool('echo', 'Echo', ['input' => 'string'], function (array $args) {
        return ['content' => [['type' => 'text', 'text' => $args['input'] ?? '']]];
      }),
    ]);

    $options = new \Claude\AgentSdk\ClaudeAgentOptions(mcpServers: ['sdk' => $serverConfig]);

    $client = new Client($options, $transport);
    $client->connect((function (): iterable { if (false) { yield []; } })());

    iterator_to_array($client->receiveMessages());

    $written = json_decode(end($transport->writes), true);
    $this->assertSame('control_response', $written['type']);
    $this->assertSame('success', $written['response']['subtype']);
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
    };
    return $transport;
  }

}
