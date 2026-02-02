<?php

declare(strict_types=1);

namespace Claude\AgentSdk;

use Claude\AgentSdk\Transport\TransportInterface;
use Claude\AgentSdk\Transport\SubprocessCLITransport;
use Claude\AgentSdk\Types\MessageFactory;
use Claude\AgentSdk\Types\MessageInterface;
use Claude\AgentSdk\Types\PermissionResultAllow;
use Claude\AgentSdk\Types\PermissionResultDeny;
use Claude\AgentSdk\Types\ToolPermissionContext;
use RuntimeException;
use Throwable;

final class Client {

  private ?TransportInterface $transport = null;
  private ?\Iterator $readIterator = null;
  private bool $readIteratorStarted = false;
  private array $messageQueue = [];
  private array $pendingControlResults = [];
  private array $hookCallbacks = [];
  private int $nextCallbackId = 0;
  private int $requestCounter = 0;
  private bool $isStreaming = false;
  private bool $closeInputOnFirstResult = false;

  public function __construct(
    private readonly ?ClaudeAgentOptions $options = null,
    private readonly ?TransportInterface $customTransport = null,
  ) {
  }

  public function connect(string|iterable|null $prompt = null): void {
    $options = $this->options ?? new ClaudeAgentOptions();

    $actualPrompt = $prompt;
    if ($prompt === null) {
      $actualPrompt = (function (): iterable {
        if (false) {
          yield [];
        }
      })();
    }

    $this->isStreaming = is_iterable($actualPrompt) && !is_string($actualPrompt);

    if ($options->canUseTool !== null) {
      if (!$this->isStreaming) {
        throw new RuntimeException('canUseTool requires streaming mode (iterable prompt).');
      }
      if ($options->permissionPromptToolName !== null) {
        throw new RuntimeException('canUseTool cannot be used with permissionPromptToolName.');
      }
      $options = $options->with(['permissionPromptToolName' => 'stdio']);
    }

    $this->transport = $this->customTransport ?? new SubprocessCLITransport($actualPrompt ?? '', $options);
    $this->transport->connect();

    if ($this->isStreaming) {
      $this->initialize();
    }

    if ($prompt !== null && is_iterable($prompt) && !is_string($prompt)) {
      $this->streamInput($prompt, $options);
    }
  }

  /**
   * @return iterable<MessageInterface>
   */
  public function receiveMessages(): iterable {
    if (!$this->transport) {
      throw new RuntimeException('Not connected. Call connect() first.');
    }

    while (true) {
      $message = $this->dequeueMessage();
      if ($message === null) {
        return;
      }

      $type = $message['type'] ?? null;
      if ($type === 'control_response') {
        $this->handleControlResponse($message);
        continue;
      }
      if ($type === 'control_request') {
        $this->handleControlRequest($message);
        continue;
      }
      if ($type === 'control_cancel_request') {
        continue;
      }

      if ($type === 'result' && $this->closeInputOnFirstResult) {
        $this->transport->closeInput();
        $this->closeInputOnFirstResult = false;
      }

      yield MessageFactory::fromArray($message);
    }
  }

  /**
   * @return iterable<MessageInterface>
   */
  public function receiveResponse(): iterable {
    foreach ($this->receiveMessages() as $message) {
      yield $message;
      if ($message instanceof \Claude\AgentSdk\Types\ResultMessage) {
        break;
      }
    }
  }

  public function query(string|iterable $prompt, string $sessionId = 'default'): void {
    if (!$this->transport) {
      throw new RuntimeException('Not connected. Call connect() first.');
    }

    if (is_string($prompt)) {
      $message = [
        'type' => 'user',
        'message' => ['role' => 'user', 'content' => $prompt],
        'parent_tool_use_id' => null,
        'session_id' => $sessionId,
      ];
      $this->transport->write(json_encode($message) . "\n");
      return;
    }

    foreach ($prompt as $message) {
      if (is_array($message) && !isset($message['session_id'])) {
        $message['session_id'] = $sessionId;
      }
      $this->transport->write(json_encode($message) . "\n");
    }
  }

  public function initialize(): ?array {
    if (!$this->transport || !$this->isStreaming) {
      return null;
    }

    $hooksConfig = $this->buildHooksConfig();

    $request = [
      'subtype' => 'initialize',
      'hooks' => $hooksConfig ?: null,
    ];

    return $this->sendControlRequest($request, $this->getInitializeTimeout());
  }

  public function getMcpStatus(): array {
    return $this->sendControlRequest(['subtype' => 'mcp_status']);
  }

  public function interrupt(): void {
    $this->sendControlRequest(['subtype' => 'interrupt']);
  }

  public function setPermissionMode(string $mode): void {
    $this->sendControlRequest(['subtype' => 'set_permission_mode', 'mode' => $mode]);
  }

  public function setModel(?string $model): void {
    $this->sendControlRequest(['subtype' => 'set_model', 'model' => $model]);
  }

  public function rewindFiles(string $userMessageId): void {
    $this->sendControlRequest(['subtype' => 'rewind_files', 'user_message_id' => $userMessageId]);
  }

  public function closeInput(): void {
    if ($this->transport) {
      $this->transport->closeInput();
    }
  }

  public function close(): void {
    if ($this->transport) {
      $this->transport->close();
      $this->transport = null;
      $this->readIterator = null;
      $this->readIteratorStarted = false;
      $this->messageQueue = [];
      $this->pendingControlResults = [];
    }
  }

  public function disconnect(): void {
    $this->close();
  }

  private function streamInput(iterable $stream, ClaudeAgentOptions $options): void {
    $hasControlCallbacks = $options->hooks !== null || $options->mcpMessageHandler !== null || $options->canUseTool !== null;
    $this->closeInputOnFirstResult = $hasControlCallbacks;

    foreach ($stream as $message) {
      $this->transport?->write(json_encode($message) . "\n");
    }

    if (!$hasControlCallbacks) {
      $this->transport?->closeInput();
    }
  }

  private function dequeueMessage(): ?array {
    if (!empty($this->messageQueue)) {
      return array_shift($this->messageQueue);
    }

    return $this->nextRawMessage();
  }

  private function nextRawMessage(): ?array {
    $iterator = $this->getReadIterator();
    if ($iterator === null) {
      return null;
    }

    if (!$iterator->valid()) {
      $this->readIterator = null;
      $this->readIteratorStarted = false;
      return null;
    }

    $current = $iterator->current();
    $iterator->next();

    return is_array($current) ? $current : null;
  }

  private function getReadIterator(): ?\Iterator {
    if (!$this->transport) {
      return null;
    }

    if ($this->readIterator) {
      return $this->readIterator;
    }

    $iterable = $this->transport->readMessages();
    if ($iterable instanceof \Iterator) {
      $this->readIterator = $iterable;
    }
    else {
      $this->readIterator = (function () use ($iterable): \Generator {
        foreach ($iterable as $item) {
          yield $item;
        }
      })();
    }

    if (!$this->readIteratorStarted) {
      $this->readIterator->rewind();
      $this->readIteratorStarted = true;
    }

    return $this->readIterator;
  }

  private function sendControlRequest(array $request, float $timeoutSeconds = 60.0): array {
    if (!$this->transport || !$this->isStreaming) {
      throw new RuntimeException('Control requests require streaming mode.');
    }

    $this->requestCounter++;
    $requestId = 'req_' . $this->requestCounter . '_' . bin2hex(random_bytes(4));
    $this->pendingControlResults[$requestId] = null;

    $controlRequest = [
      'type' => 'control_request',
      'request_id' => $requestId,
      'request' => $request,
    ];

    $this->transport->write(json_encode($controlRequest) . "\n");

    $deadline = microtime(true) + $timeoutSeconds;

    while (true) {
      if (array_key_exists($requestId, $this->pendingControlResults) && $this->pendingControlResults[$requestId] !== null) {
        $result = $this->pendingControlResults[$requestId];
        unset($this->pendingControlResults[$requestId]);

        if ($result instanceof Throwable) {
          throw new RuntimeException($result->getMessage(), $result->getCode(), $result);
        }

        $response = $result['response'] ?? [];
        return is_array($response) ? $response : [];
      }

      if (microtime(true) > $deadline) {
        unset($this->pendingControlResults[$requestId]);
        throw new RuntimeException('Control request timeout: ' . ($request['subtype'] ?? 'unknown'));
      }

      $message = $this->nextRawMessage();
      if ($message === null) {
        usleep(1000);
        continue;
      }

      $this->processRawMessage($message);
    }
  }

  private function processRawMessage(array $message): void {
    $type = $message['type'] ?? null;
    if ($type === 'control_response') {
      $this->handleControlResponse($message);
      return;
    }
    if ($type === 'control_request') {
      $this->handleControlRequest($message);
      return;
    }
    if ($type === 'control_cancel_request') {
      return;
    }

    if ($type === 'result' && $this->closeInputOnFirstResult) {
      $this->transport?->closeInput();
      $this->closeInputOnFirstResult = false;
    }

    $this->messageQueue[] = $message;
  }

  private function handleControlResponse(array $message): void {
    $response = $message['response'] ?? null;
    if (!is_array($response)) {
      return;
    }

    $requestId = $response['request_id'] ?? null;
    if (!is_string($requestId)) {
      return;
    }

    if (!array_key_exists($requestId, $this->pendingControlResults)) {
      $this->pendingControlResults[$requestId] = $response;
      return;
    }

    if (($response['subtype'] ?? null) === 'error') {
      $this->pendingControlResults[$requestId] = new RuntimeException((string) ($response['error'] ?? 'Unknown error'));
      return;
    }

    $this->pendingControlResults[$requestId] = $response;
  }

  private function handleControlRequest(array $message): void {
    $requestId = $message['request_id'] ?? null;
    $request = $message['request'] ?? null;
    if (!is_string($requestId) || !is_array($request)) {
      return;
    }

    $subtype = $request['subtype'] ?? null;
    $responseData = [];

    try {
      if ($subtype === 'can_use_tool') {
        $responseData = $this->handleCanUseTool($request);
      }
      elseif ($subtype === 'hook_callback') {
        $responseData = $this->handleHookCallback($request);
      }
      elseif ($subtype === 'mcp_message') {
        $responseData = $this->handleMcpMessage($request);
      }
      else {
        throw new RuntimeException('Unsupported control request subtype: ' . (string) $subtype);
      }

      $successResponse = [
        'type' => 'control_response',
        'response' => [
          'subtype' => 'success',
          'request_id' => $requestId,
          'response' => $responseData,
        ],
      ];
      $this->transport?->write(json_encode($successResponse) . "\n");
    }
    catch (Throwable $e) {
      $errorResponse = [
        'type' => 'control_response',
        'response' => [
          'subtype' => 'error',
          'request_id' => $requestId,
          'error' => $e->getMessage(),
        ],
      ];
      $this->transport?->write(json_encode($errorResponse) . "\n");
    }
  }

  private function handleCanUseTool(array $request): array {
    $options = $this->options ?? new ClaudeAgentOptions();
    if (!$options->canUseTool) {
      throw new RuntimeException('canUseTool callback is not provided.');
    }

    $toolName = (string) ($request['tool_name'] ?? '');
    $input = $request['input'] ?? [];

    $context = new ToolPermissionContext(
      suggestions: $request['permission_suggestions'] ?? [],
      blockedPath: $request['blocked_path'] ?? null,
    );

    $result = ($options->canUseTool)($toolName, $input, $context);

    if ($result instanceof PermissionResultAllow) {
      $response = [
        'behavior' => 'allow',
        'updatedInput' => $result->updatedInput ?? $input,
      ];
      if ($result->updatedPermissions !== null) {
        $response['updatedPermissions'] = $result->updatedPermissions;
      }
      return $response;
    }

    if ($result instanceof PermissionResultDeny) {
      $response = [
        'behavior' => 'deny',
        'message' => $result->message,
      ];
      if ($result->interrupt) {
        $response['interrupt'] = true;
      }
      return $response;
    }

    if (is_array($result)) {
      return $result;
    }

    throw new RuntimeException('canUseTool must return PermissionResultAllow, PermissionResultDeny, or array.');
  }

  private function handleHookCallback(array $request): array {
    $callbackId = $request['callback_id'] ?? null;
    if (!is_string($callbackId)) {
      throw new RuntimeException('Hook callback missing callback_id.');
    }

    $callback = $this->hookCallbacks[$callbackId] ?? null;
    if (!$callback) {
      throw new RuntimeException('No hook callback found for ID: ' . $callbackId);
    }

    $input = $request['input'] ?? null;
    $toolUseId = $request['tool_use_id'] ?? null;

    $output = $callback($input, $toolUseId, ['signal' => null]);
    if (!is_array($output)) {
      throw new RuntimeException('Hook callback must return an array.');
    }

    return $this->convertHookOutputForCli($output);
  }

  private function handleMcpMessage(array $request): array {
    $options = $this->options ?? new ClaudeAgentOptions();
    $serverName = $request['server_name'] ?? null;
    $message = $request['message'] ?? null;

    if (!is_string($serverName) || !is_array($message)) {
      throw new RuntimeException('Invalid mcp_message request.');
    }

    if ($options->mcpMessageHandler) {
      $response = ($options->mcpMessageHandler)($serverName, $message);
      if (!is_array($response)) {
        throw new RuntimeException('mcpMessageHandler must return an array.');
      }
      return ['mcp_response' => $response];
    }

    $sdkServer = $this->resolveSdkMcpServer($options, $serverName);
    if ($sdkServer instanceof \Claude\AgentSdk\SdkMcpServer) {
      $response = $sdkServer->handleMessage($message);
      return ['mcp_response' => $response];
    }

    throw new RuntimeException('mcpMessageHandler callback is not provided.');
  }

  private function resolveSdkMcpServer(ClaudeAgentOptions $options, string $serverName): ?\Claude\AgentSdk\SdkMcpServer {
    $servers = $options->mcpServers;
    if (!is_array($servers)) {
      return null;
    }
    if (!isset($servers[$serverName]) || !is_array($servers[$serverName])) {
      return null;
    }
    $server = $servers[$serverName];
    if (($server['type'] ?? null) !== 'sdk') {
      return null;
    }
    $instance = $server['instance'] ?? null;
    if ($instance instanceof \Claude\AgentSdk\SdkMcpServer) {
      return $instance;
    }
    return null;
  }

  private function buildHooksConfig(): array {
    $options = $this->options ?? new ClaudeAgentOptions();
    $hooks = $options->hooks ?? [];
    if (!is_array($hooks)) {
      return [];
    }

    $hooksConfig = [];
    foreach ($hooks as $event => $matchers) {
      if (!is_array($matchers)) {
        continue;
      }

      $hooksConfig[$event] = [];
      foreach ($matchers as $matcher) {
        if (!is_array($matcher)) {
          continue;
        }

        $callbackIds = [];
        $callbacks = $matcher['hooks'] ?? [];
        if (is_array($callbacks)) {
          foreach ($callbacks as $callback) {
            if (!is_callable($callback)) {
              continue;
            }
            $callbackId = 'hook_' . $this->nextCallbackId++;
            $this->hookCallbacks[$callbackId] = $callback;
            $callbackIds[] = $callbackId;
          }
        }

        $hookMatcherConfig = [
          'matcher' => $matcher['matcher'] ?? null,
          'hookCallbackIds' => $callbackIds,
        ];
        if (isset($matcher['timeout'])) {
          $hookMatcherConfig['timeout'] = $matcher['timeout'];
        }

        $hooksConfig[$event][] = $hookMatcherConfig;
      }
    }

    return $hooksConfig;
  }

  private function convertHookOutputForCli(array $hookOutput): array {
    $converted = [];
    foreach ($hookOutput as $key => $value) {
      if ($key === 'async_') {
        $converted['async'] = $value;
      }
      elseif ($key === 'continue_') {
        $converted['continue'] = $value;
      }
      else {
        $converted[$key] = $value;
      }
    }
    return $converted;
  }

  private function getInitializeTimeout(): float {
    $options = $this->options ?? new ClaudeAgentOptions();
    if ($options->initializeTimeout !== null) {
      return $options->initializeTimeout;
    }

    $env = getenv('CLAUDE_CODE_STREAM_CLOSE_TIMEOUT');
    if ($env === false) {
      return 60.0;
    }

    $ms = (int) $env;
    if ($ms <= 0) {
      return 60.0;
    }

    return max($ms / 1000.0, 60.0);
  }

}
