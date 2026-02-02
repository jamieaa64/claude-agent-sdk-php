<?php

declare(strict_types=1);

namespace Claude\AgentSdk\Transport;

use Claude\AgentSdk\ClaudeAgentOptions;
use Claude\AgentSdk\Exceptions\CLIConnectionError;
use Claude\AgentSdk\Exceptions\CLIJSONDecodeError;
use Claude\AgentSdk\Exceptions\CLINotFoundError;
use Claude\AgentSdk\Exceptions\ProcessError;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Throwable;

final class SubprocessCLITransport implements TransportInterface {

  private const DEFAULT_MAX_BUFFER_SIZE = 1048576;

  private ?Process $process = null;
  private ?InputStream $inputStream = null;
  private string $stdoutBuffer = '';
  private bool $isStreaming;
  private int $maxBufferSize;

  public function __construct(
    private readonly string|iterable $prompt,
    private readonly ClaudeAgentOptions $options,
  ) {
    $this->isStreaming = !is_string($prompt);
    $this->maxBufferSize = $options->maxBufferSize ?? self::DEFAULT_MAX_BUFFER_SIZE;
  }

  public function connect(): void {
    if ($this->process) {
      return;
    }

    if ($this->options->cliPath !== null && !file_exists((string) $this->options->cliPath)) {
      throw new CLINotFoundError('Claude Code not found at: ' . (string) $this->options->cliPath);
    }

    $cmd = $this->buildCommand();

    $env = $this->buildEnv();

    $cwd = $this->options->cwd ? (string) $this->options->cwd : null;

    try {
      $this->process = new Process($cmd, $cwd, $env);
      $this->process->setTimeout(null);

      if ($this->isStreaming) {
        $this->inputStream = new InputStream();
        $this->process->setInput($this->inputStream);
      }
      else {
        $this->process->setInput((string) $this->prompt);
      }

      $this->process->start();
    }
    catch (Throwable $e) {
      if ($this->options->cliPath !== null && !file_exists((string) $this->options->cliPath)) {
        throw new CLINotFoundError('Claude Code not found at: ' . (string) $this->options->cliPath);
      }
      throw new CLIConnectionError('Failed to start Claude Code: ' . $e->getMessage());
    }
  }

  public function readMessages(): iterable {
    if (!$this->process) {
      return;
    }

    foreach ($this->process->getIterator(Process::ITER_SKIP_ERR) as $chunk) {
      $this->stdoutBuffer .= $chunk;
      $this->guardBufferSize();
      yield from $this->drainBuffer();
    }

    yield from $this->drainBuffer();
    yield from $this->flushBuffer();

    if (!$this->process->isSuccessful()) {
      $exitCode = $this->process->getExitCode() ?? 1;
      throw new ProcessError('Claude Code exited with code: ' . $exitCode, $exitCode);
    }
  }

  public function write(string $data): void {
    if (!$this->inputStream) {
      return;
    }
    $this->inputStream->write($data);
  }

  public function closeInput(): void {
    if ($this->inputStream) {
      $this->inputStream->close();
      $this->inputStream = null;
    }
  }

  public function close(): void {
    if ($this->inputStream) {
      $this->inputStream->close();
      $this->inputStream = null;
    }
    if ($this->process) {
      $this->process->stop();
      $this->process = null;
    }
  }

  private function drainBuffer(): iterable {
    while (($pos = strpos($this->stdoutBuffer, "\n")) !== false) {
      $line = substr($this->stdoutBuffer, 0, $pos);
      $this->stdoutBuffer = substr($this->stdoutBuffer, $pos + 1);
      $line = trim($line);
      if ($line === '') {
        continue;
      }
      $decoded = json_decode($line, true);
      if (!is_array($decoded)) {
        throw new CLIJSONDecodeError('Failed to decode JSON from CLI output.', $line);
      }
      yield $decoded;
    }
  }

  private function flushBuffer(): iterable {
    $remaining = trim($this->stdoutBuffer);
    if ($remaining === '') {
      return;
    }

    $decoded = json_decode($remaining, true);
    if (!is_array($decoded)) {
      throw new CLIJSONDecodeError('Failed to decode JSON from CLI output.', $remaining);
    }
    $this->stdoutBuffer = '';
    yield $decoded;
  }

  private function guardBufferSize(): void {
    if (strlen($this->stdoutBuffer) <= $this->maxBufferSize) {
      return;
    }

    if (strpos($this->stdoutBuffer, "\n") !== false) {
      return;
    }

    throw new CLIJSONDecodeError(
      'JSON decode exceeded maximum buffer size of ' . $this->maxBufferSize . ' bytes.',
      $this->stdoutBuffer
    );
  }

  private function buildCommand(): array {
    $cliPath = $this->options->cliPath ? (string) $this->options->cliPath : $this->findCli();

    $cmd = [$cliPath, '--output-format', 'stream-json', '--verbose'];

    if ($this->options->systemPrompt === null) {
      $cmd[] = '--system-prompt';
      $cmd[] = '';
    }
    elseif (is_string($this->options->systemPrompt)) {
      $cmd[] = '--system-prompt';
      $cmd[] = $this->options->systemPrompt;
    }
    elseif (is_array($this->options->systemPrompt)) {
      if (($this->options->systemPrompt['type'] ?? null) === 'preset' && isset($this->options->systemPrompt['append'])) {
        $cmd[] = '--append-system-prompt';
        $cmd[] = (string) $this->options->systemPrompt['append'];
      }
    }

    if ($this->options->tools !== null) {
      if (is_array($this->options->tools)) {
        if (($this->options->tools['type'] ?? null) === 'preset') {
          $cmd[] = '--tools';
          $cmd[] = 'default';
        }
        else {
          $cmd[] = '--tools';
          $cmd[] = empty($this->options->tools) ? '' : implode(',', $this->options->tools);
        }
      }
      else {
        $cmd[] = '--tools';
        $cmd[] = 'default';
      }
    }

    if (!empty($this->options->allowedTools)) {
      $cmd[] = '--allowedTools';
      $cmd[] = implode(',', $this->options->allowedTools);
    }

    if (!empty($this->options->disallowedTools)) {
      $cmd[] = '--disallowedTools';
      $cmd[] = implode(',', $this->options->disallowedTools);
    }

    if ($this->options->maxTurns !== null) {
      $cmd[] = '--max-turns';
      $cmd[] = (string) $this->options->maxTurns;
    }

    if ($this->options->maxBudgetUsd !== null) {
      $cmd[] = '--max-budget-usd';
      $cmd[] = (string) $this->options->maxBudgetUsd;
    }

    if ($this->options->model) {
      $cmd[] = '--model';
      $cmd[] = $this->options->model;
    }

    if ($this->options->fallbackModel) {
      $cmd[] = '--fallback-model';
      $cmd[] = $this->options->fallbackModel;
    }

    if (!empty($this->options->betas)) {
      $cmd[] = '--betas';
      $cmd[] = implode(',', $this->options->betas);
    }

    if ($this->options->permissionPromptToolName) {
      $cmd[] = '--permission-prompt-tool';
      $cmd[] = $this->options->permissionPromptToolName;
    }

    if ($this->options->permissionMode) {
      $cmd[] = '--permission-mode';
      $cmd[] = $this->options->permissionMode;
    }

    if ($this->options->continueConversation) {
      $cmd[] = '--continue';
    }

    if ($this->options->resume) {
      $cmd[] = '--resume';
      $cmd[] = $this->options->resume;
    }

    $settingsValue = $this->buildSettingsValue();
    if ($settingsValue) {
      $cmd[] = '--settings';
      $cmd[] = $settingsValue;
    }

    if (!empty($this->options->addDirs)) {
      foreach ($this->options->addDirs as $dir) {
        $cmd[] = '--add-dir';
        $cmd[] = (string) $dir;
      }
    }

    if ($this->options->mcpServers !== null) {
      if (is_array($this->options->mcpServers)) {
        $mcpServers = $this->filterExternalMcpServers($this->options->mcpServers);
        if (!empty($mcpServers)) {
          $cmd[] = '--mcp-config';
          $cmd[] = json_encode(['mcpServers' => $mcpServers]);
        }
      }
      else {
        $cmd[] = '--mcp-config';
        $cmd[] = (string) $this->options->mcpServers;
      }
    }

    if ($this->options->includePartialMessages) {
      $cmd[] = '--include-partial-messages';
    }

    if ($this->options->forkSession) {
      $cmd[] = '--fork-session';
    }

    if (!empty($this->options->agents)) {
      $cmd[] = '--agents';
      $cmd[] = json_encode($this->options->agents);
    }

    if ($this->options->settingSources !== null) {
      $cmd[] = '--setting-sources';
      $cmd[] = implode(',', $this->options->settingSources);
    }

    if (!empty($this->options->plugins)) {
      foreach ($this->options->plugins as $plugin) {
        if (($plugin['type'] ?? null) === 'local') {
          $cmd[] = '--plugin-dir';
          $cmd[] = $plugin['path'];
        }
      }
    }

    if ($this->options->maxThinkingTokens !== null) {
      $cmd[] = '--max-thinking-tokens';
      $cmd[] = (string) $this->options->maxThinkingTokens;
    }

    if (is_array($this->options->outputFormat) && ($this->options->outputFormat['type'] ?? null) === 'json_schema') {
      $schema = $this->options->outputFormat['schema'] ?? null;
      if ($schema !== null) {
        $cmd[] = '--json-schema';
        $cmd[] = json_encode($schema);
      }
    }

    foreach ($this->options->extraArgs as $flag => $value) {
      if ($value === null) {
        $cmd[] = '--' . $flag;
      }
      else {
        $cmd[] = '--' . $flag;
        $cmd[] = (string) $value;
      }
    }

    if ($this->isStreaming) {
      $cmd[] = '--input-format';
      $cmd[] = 'stream-json';
    }
    else {
      $cmd[] = '--print';
      $cmd[] = '--';
      $cmd[] = (string) $this->prompt;
    }

    return $cmd;
  }

  private function buildSettingsValue(): ?string {
    $hasSettings = $this->options->settings !== null;
    $hasSandbox = $this->options->sandbox !== null;

    if (!$hasSettings && !$hasSandbox) {
      return null;
    }

    if ($hasSettings && !$hasSandbox) {
      return $this->options->settings;
    }

    $settingsObj = [];
    if ($hasSettings) {
      $settingsStr = trim((string) $this->options->settings);
      if ($settingsStr !== '' && $settingsStr[0] === '{') {
        $decoded = json_decode($settingsStr, true);
        if (is_array($decoded)) {
          $settingsObj = $decoded;
        }
      }
      elseif (is_string($this->options->settings) && is_file($this->options->settings)) {
        $fileData = file_get_contents($this->options->settings);
        $decoded = json_decode($fileData ?: '', true);
        if (is_array($decoded)) {
          $settingsObj = $decoded;
        }
      }
    }

    if ($hasSandbox) {
      $settingsObj['sandbox'] = $this->options->sandbox;
    }

    return json_encode($settingsObj);
  }

  private function findCli(): string {
    $candidates = [
      'claude',
      getenv('HOME') . '/.npm-global/bin/claude',
      '/usr/local/bin/claude',
      getenv('HOME') . '/.local/bin/claude',
      getenv('HOME') . '/node_modules/.bin/claude',
      getenv('HOME') . '/.yarn/bin/claude',
      getenv('HOME') . '/.claude/local/claude',
    ];

    foreach ($candidates as $candidate) {
      if ($candidate && is_file($candidate) && is_executable($candidate)) {
        return $candidate;
      }
      if ($candidate === 'claude') {
        $path = trim((string) shell_exec('command -v claude'));
        if ($path !== '' && is_file($path)) {
          return $path;
        }
      }
    }

    throw new CLINotFoundError('Claude Code CLI not found. Provide cliPath in options.');
  }

  private function buildEnv(): array {
    $base = getenv();
    if (!is_array($base)) {
      $base = [];
    }
    $env = array_merge($base, $this->options->env);

    $env['CLAUDE_CODE_ENTRYPOINT'] = $env['CLAUDE_CODE_ENTRYPOINT'] ?? 'sdk-php';
    if ($this->options->enableFileCheckpointing) {
      $env['CLAUDE_CODE_ENABLE_SDK_FILE_CHECKPOINTING'] = 'true';
    }

    return $env;
  }

  private function filterExternalMcpServers(array $mcpServers): array {
    $filtered = [];
    foreach ($mcpServers as $name => $server) {
      if (!is_array($server)) {
        $filtered[$name] = $server;
        continue;
      }
      if (($server['type'] ?? null) === 'sdk') {
        continue;
      }
      if (isset($server['instance'])) {
        continue;
      }
      $filtered[$name] = $server;
    }
    return $filtered;
  }

}
