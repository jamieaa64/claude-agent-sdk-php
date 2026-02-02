<?php

declare(strict_types=1);

namespace Claude\AgentSdk;

final class SdkMcpTool {

  public function __construct(
    public readonly string $name,
    public readonly string $description,
    public readonly array $inputSchema,
    public readonly \Closure $handler,
  ) {
  }

}

final class SdkMcpServer {

  /** @var array<string, SdkMcpTool> */
  private array $tools = [];

  public function __construct(
    public readonly string $name,
    public readonly string $version,
    array $tools = [],
  ) {
    foreach ($tools as $tool) {
      if ($tool instanceof SdkMcpTool) {
        $this->tools[$tool->name] = $tool;
      }
    }
  }

  public function hasTools(): bool {
    return !empty($this->tools);
  }

  public function listTools(): array {
    $items = [];
    foreach ($this->tools as $tool) {
      $items[] = [
        'name' => $tool->name,
        'description' => $tool->description,
        'inputSchema' => $tool->inputSchema,
      ];
    }
    return $items;
  }

  public function callTool(string $name, array $arguments): array {
    if (!isset($this->tools[$name])) {
      return [
        'is_error' => true,
        'content' => [
          ['type' => 'text', 'text' => "Tool '{$name}' not found"],
        ],
      ];
    }

    try {
      $result = ($this->tools[$name]->handler)($arguments);
      if (is_array($result)) {
        return $result;
      }
      return [
        'content' => [
          ['type' => 'text', 'text' => (string) $result],
        ],
      ];
    }
    catch (\Throwable $e) {
      return [
        'is_error' => true,
        'content' => [
          ['type' => 'text', 'text' => $e->getMessage()],
        ],
      ];
    }
  }

  public function handleMessage(array $message): array {
    $method = $message['method'] ?? null;
    $id = $message['id'] ?? null;
    $params = $message['params'] ?? [];

    if ($method === 'initialize') {
      return [
        'jsonrpc' => '2.0',
        'id' => $id,
        'result' => [
          'protocolVersion' => '2024-11-05',
          'capabilities' => [
            'tools' => new \stdClass(),
          ],
          'serverInfo' => [
            'name' => $this->name,
            'version' => $this->version,
          ],
        ],
      ];
    }

    if ($method === 'tools/list') {
      return [
        'jsonrpc' => '2.0',
        'id' => $id,
        'result' => [
          'tools' => $this->listTools(),
        ],
      ];
    }

    if ($method === 'tools/call') {
      $toolName = $params['name'] ?? null;
      $args = $params['arguments'] ?? [];
      $result = $this->callTool((string) $toolName, is_array($args) ? $args : []);
      return [
        'jsonrpc' => '2.0',
        'id' => $id,
        'result' => $result,
      ];
    }

    if ($method === 'notifications/initialized') {
      return [
        'jsonrpc' => '2.0',
        'result' => new \stdClass(),
      ];
    }

    return [
      'jsonrpc' => '2.0',
      'id' => $id,
      'error' => [
        'code' => -32601,
        'message' => "Method '{$method}' not found",
      ],
    ];
  }

}

function tool(string $name, string $description, array $inputSchema, callable $handler): SdkMcpTool {
  return new SdkMcpTool($name, $description, $inputSchema, \Closure::fromCallable($handler));
}

function create_sdk_mcp_server(string $name, string $version = '1.0.0', array $tools = []): array {
  $server = new SdkMcpServer($name, $version, $tools);
  return [
    'type' => 'sdk',
    'name' => $name,
    'version' => $version,
    'instance' => $server,
  ];
}
