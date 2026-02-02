<?php

declare(strict_types=1);

namespace Claude\AgentSdk\Tests;

use Claude\AgentSdk\ClaudeAgentOptions;
use Claude\AgentSdk\Client;
use Claude\AgentSdk\Tests\Support\FakeTransport;
use Claude\AgentSdk\Types\PermissionResultAllow;
use Claude\AgentSdk\Types\PermissionResultDeny;
use PHPUnit\Framework\TestCase;

final class ToolCallbacksTest extends TestCase {

  public function testPermissionCallbackAllow(): void {
    $transport = $this->makeTransportWithInitResponse([
      [
        'type' => 'control_request',
        'request_id' => 'test-1',
        'request' => [
          'subtype' => 'can_use_tool',
          'tool_name' => 'TestTool',
          'input' => ['param' => 'value'],
          'permission_suggestions' => [],
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
        $this->assertSame('TestTool', $tool);
        $this->assertSame(['param' => 'value'], $input);
        return new PermissionResultAllow();
      }
    );

    $client = new Client($options, $transport);
    $client->connect($this->emptyStream());

    $messages = iterator_to_array($client->receiveMessages());

    $this->assertCount(1, $messages);
    $this->assertNotEmpty($transport->writes);
    $written = json_decode(end($transport->writes), true);
    $this->assertSame('control_response', $written['type']);
    $this->assertSame('success', $written['response']['subtype']);
  }

  public function testPermissionCallbackDeny(): void {
    $transport = $this->makeTransportWithInitResponse([
      [
        'type' => 'control_request',
        'request_id' => 'test-2',
        'request' => [
          'subtype' => 'can_use_tool',
          'tool_name' => 'DangerousTool',
          'input' => ['command' => 'rm -rf /'],
          'permission_suggestions' => ['deny'],
        ],
      ],
    ]);

    $options = new ClaudeAgentOptions(
      canUseTool: function () {
        return new PermissionResultDeny('Security policy violation');
      }
    );

    $client = new Client($options, $transport);
    $client->connect($this->emptyStream());

    iterator_to_array($client->receiveMessages());

    $this->assertNotEmpty($transport->writes);
    $written = json_decode(end($transport->writes), true);
    $this->assertSame('deny', $written['response']['response']['behavior']);
    $this->assertSame('Security policy violation', $written['response']['response']['message']);
  }

  public function testPermissionCallbackInputModification(): void {
    $transport = $this->makeTransportWithInitResponse([
      [
        'type' => 'control_request',
        'request_id' => 'test-3',
        'request' => [
          'subtype' => 'can_use_tool',
          'tool_name' => 'WriteTool',
          'input' => ['file_path' => '/etc/passwd'],
          'permission_suggestions' => [],
        ],
      ],
    ]);

    $options = new ClaudeAgentOptions(
      canUseTool: function (string $tool, array $input) {
        $input['safe_mode'] = true;
        return new PermissionResultAllow(updatedInput: $input);
      }
    );

    $client = new Client($options, $transport);
    $client->connect($this->emptyStream());

    iterator_to_array($client->receiveMessages());

    $written = json_decode(end($transport->writes), true);
    $this->assertSame(true, $written['response']['response']['updatedInput']['safe_mode']);
  }

  public function testCallbackExceptionHandling(): void {
    $transport = $this->makeTransportWithInitResponse([
      [
        'type' => 'control_request',
        'request_id' => 'test-5',
        'request' => [
          'subtype' => 'can_use_tool',
          'tool_name' => 'TestTool',
          'input' => [],
          'permission_suggestions' => [],
        ],
      ],
    ]);

    $options = new ClaudeAgentOptions(
      canUseTool: function () {
        throw new \RuntimeException('Callback error');
      }
    );

    $client = new Client($options, $transport);
    $client->connect($this->emptyStream());

    iterator_to_array($client->receiveMessages());

    $written = json_decode(end($transport->writes), true);
    $this->assertSame('error', $written['response']['subtype']);
    $this->assertStringContainsString('Callback error', $written['response']['error']);
  }

  public function testHookOutputFieldsConverted(): void {
    $transport = $this->makeTransportWithInitResponse([
      [
        'type' => 'control_request',
        'request_id' => 'test-hook-1',
        'request' => [
          'subtype' => 'hook_callback',
          'callback_id' => 'hook_0',
          'input' => ['test' => 'data'],
          'tool_use_id' => 'tool-123',
        ],
      ],
    ]);

    $options = new ClaudeAgentOptions(
      hooks: [
        'PreToolUse' => [
          [
            'matcher' => ['tool' => 'TestTool'],
            'hooks' => [
              function () {
                return [
                  'continue_' => true,
                  'suppressOutput' => false,
                  'stopReason' => 'Test stop reason',
                  'decision' => 'block',
                  'systemMessage' => 'Test system message',
                  'reason' => 'Test reason for blocking',
                  'hookSpecificOutput' => [
                    'hookEventName' => 'PreToolUse',
                    'permissionDecision' => 'deny',
                    'permissionDecisionReason' => 'Security policy violation',
                    'updatedInput' => ['modified' => 'input'],
                  ],
                ];
              },
            ],
          ],
        ],
      ],
    );

    $client = new Client($options, $transport);
    $client->connect($this->emptyStream());

    iterator_to_array($client->receiveMessages());

    $written = json_decode(end($transport->writes), true);
    $result = $written['response']['response'];
    $this->assertTrue($result['continue']);
    $this->assertFalse(isset($result['continue_']));
    $this->assertSame('Test stop reason', $result['stopReason']);
    $this->assertSame('block', $result['decision']);
    $this->assertSame('Test reason for blocking', $result['reason']);
    $this->assertSame('Test system message', $result['systemMessage']);
    $this->assertSame('PreToolUse', $result['hookSpecificOutput']['hookEventName']);
  }

  public function testAsyncHookFieldConversion(): void {
    $transport = $this->makeTransportWithInitResponse([
      [
        'type' => 'control_request',
        'request_id' => 'test-async',
        'request' => [
          'subtype' => 'hook_callback',
          'callback_id' => 'hook_0',
          'input' => ['test' => 'async_data'],
          'tool_use_id' => null,
        ],
      ],
    ]);

    $options = new ClaudeAgentOptions(
      hooks: [
        'PreToolUse' => [
          [
            'matcher' => null,
            'hooks' => [
              function () {
                return [
                  'async_' => true,
                  'asyncTimeout' => 5000,
                ];
              },
            ],
          ],
        ],
      ],
    );

    $client = new Client($options, $transport);
    $client->connect($this->emptyStream());

    iterator_to_array($client->receiveMessages());

    $written = json_decode(end($transport->writes), true);
    $result = $written['response']['response'];
    $this->assertTrue($result['async']);
    $this->assertSame(5000, $result['asyncTimeout']);
  }

  private function makeTransportWithInitResponse(array $messages): FakeTransport {
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

  private function emptyStream(): iterable {
    return (function (): iterable {
      if (false) {
        yield [];
      }
    })();
  }

}
