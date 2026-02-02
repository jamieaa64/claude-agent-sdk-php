<?php

declare(strict_types=1);

namespace Claude\AgentSdk\Tests;

use function Claude\AgentSdk\create_sdk_mcp_server;
use function Claude\AgentSdk\tool;
use Claude\AgentSdk\SdkMcpServer;
use PHPUnit\Framework\TestCase;

final class SdkMcpIntegrationTest extends TestCase {

  public function testSdkMcpServerHandlers(): void {
    $toolExecutions = [];

    $greetUser = tool('greet_user', 'Greets a user by name', ['name' => 'string'], function (array $args) use (&$toolExecutions) {
      $toolExecutions[] = ['name' => 'greet_user', 'args' => $args];
      return ['content' => [['type' => 'text', 'text' => 'Hello, ' . $args['name'] . '!']]];
    });

    $addNumbers = tool('add_numbers', 'Adds two numbers', ['a' => 'float', 'b' => 'float'], function (array $args) use (&$toolExecutions) {
      $toolExecutions[] = ['name' => 'add_numbers', 'args' => $args];
      $result = $args['a'] + $args['b'];
      return ['content' => [['type' => 'text', 'text' => 'The sum is ' . $result]]];
    });

    $serverConfig = create_sdk_mcp_server('test-sdk-server', '1.0.0', [$greetUser, $addNumbers]);
    $this->assertSame('sdk', $serverConfig['type']);
    $this->assertSame('test-sdk-server', $serverConfig['name']);

    $server = $serverConfig['instance'];
    $this->assertInstanceOf(SdkMcpServer::class, $server);
    $this->assertTrue($server->hasTools());

    $listResponse = $server->handleMessage(['method' => 'tools/list', 'id' => 1]);
    $this->assertCount(2, $listResponse['result']['tools']);

    $callResponse = $server->handleMessage([
      'method' => 'tools/call',
      'id' => 2,
      'params' => ['name' => 'greet_user', 'arguments' => ['name' => 'Alice']],
    ]);
    $this->assertStringContainsString('Hello, Alice!', $callResponse['result']['content'][0]['text']);
    $this->assertCount(1, $toolExecutions);
  }

  public function testToolCreation(): void {
    $echo = tool('echo', 'Echo input', ['input' => 'string'], function (array $args) {
      return ['output' => $args['input']];
    });

    $this->assertSame('echo', $echo->name);
    $this->assertSame('Echo input', $echo->description);
    $this->assertSame(['input' => 'string'], $echo->inputSchema);
    $result = ($echo->handler)(['input' => 'test']);
    $this->assertSame(['output' => 'test'], $result);
  }

  public function testErrorHandling(): void {
    $fail = tool('fail', 'Always fails', [], function () {
      throw new \RuntimeException('Expected error');
    });

    $serverConfig = create_sdk_mcp_server('error-test', '1.0.0', [$fail]);
    $server = $serverConfig['instance'];

    $response = $server->handleMessage([
      'method' => 'tools/call',
      'id' => 3,
      'params' => ['name' => 'fail', 'arguments' => []],
    ]);

    $this->assertTrue($response['result']['is_error']);
    $this->assertStringContainsString('Expected error', $response['result']['content'][0]['text']);
  }

  public function testMixedServers(): void {
    $sdkTool = tool('sdk_tool', 'SDK tool', [], function () {
      return ['result' => 'from SDK'];
    });

    $sdkServer = create_sdk_mcp_server('sdk-server', '1.0.0', [$sdkTool]);
    $externalServer = ['type' => 'stdio', 'command' => 'echo', 'args' => ['test']];

    $options = new \Claude\AgentSdk\ClaudeAgentOptions(mcpServers: ['sdk' => $sdkServer, 'external' => $externalServer]);

    $this->assertSame('sdk', $options->mcpServers['sdk']['type']);
    $this->assertSame('stdio', $options->mcpServers['external']['type']);
  }

  public function testServerCreation(): void {
    $serverConfig = create_sdk_mcp_server('test-server', '2.0.0', []);
    $this->assertSame('sdk', $serverConfig['type']);
    $this->assertSame('test-server', $serverConfig['name']);
    $server = $serverConfig['instance'];
    $this->assertInstanceOf(SdkMcpServer::class, $server);
    $this->assertFalse($server->hasTools());
  }

  public function testImageContentSupport(): void {
    $pngData = base64_encode('fake');
    $toolExecutions = [];

    $generateChart = tool('generate_chart', 'Generates a chart', ['title' => 'string'], function (array $args) use (&$toolExecutions, $pngData) {
      $toolExecutions[] = ['name' => 'generate_chart', 'args' => $args];
      return [
        'content' => [
          ['type' => 'text', 'text' => 'Generated chart: ' . $args['title']],
          ['type' => 'image', 'data' => $pngData, 'mimeType' => 'image/png'],
        ],
      ];
    });

    $serverConfig = create_sdk_mcp_server('image-test', '1.0.0', [$generateChart]);
    $server = $serverConfig['instance'];

    $response = $server->handleMessage([
      'method' => 'tools/call',
      'id' => 4,
      'params' => ['name' => 'generate_chart', 'arguments' => ['title' => 'Sales Report']],
    ]);

    $this->assertCount(2, $response['result']['content']);
    $this->assertSame('image/png', $response['result']['content'][1]['mimeType']);
    $this->assertSame($pngData, $response['result']['content'][1]['data']);
    $this->assertCount(1, $toolExecutions);
  }

}
