<?php

declare(strict_types=1);

namespace Claude\AgentSdk\Tests;

use Claude\AgentSdk\ClaudeAgentOptions;
use Claude\AgentSdk\Exceptions\CLINotFoundError;
use Claude\AgentSdk\Transport\SubprocessCLITransport;
use PHPUnit\Framework\TestCase;

final class TransportTest extends TestCase {

  public function testFindCliNotFound(): void {
    $this->expectException(CLINotFoundError::class);
    $transport = new SubprocessCLITransport('test', new ClaudeAgentOptions(cliPath: '/path/does/not/exist'));
    $transport->connect();
  }

  public function testBuildCommandBasic(): void {
    $transport = new SubprocessCLITransport('Hello', new ClaudeAgentOptions(cliPath: '/usr/bin/claude'));
    $cmd = $this->invokePrivateMethod($transport, 'buildCommand');
    $this->assertContains('/usr/bin/claude', $cmd);
    $this->assertContains('--output-format', $cmd);
    $this->assertContains('stream-json', $cmd);
    $this->assertContains('--print', $cmd);
    $this->assertContains('Hello', $cmd);
    $this->assertContains('--system-prompt', $cmd);
    $this->assertSame('', $cmd[array_search('--system-prompt', $cmd) + 1]);
  }

  public function testBuildCommandSystemPromptString(): void {
    $transport = new SubprocessCLITransport('test', new ClaudeAgentOptions(cliPath: '/usr/bin/claude', systemPrompt: 'Be helpful'));
    $cmd = $this->invokePrivateMethod($transport, 'buildCommand');
    $this->assertContains('--system-prompt', $cmd);
    $this->assertContains('Be helpful', $cmd);
  }

  public function testBuildCommandSystemPromptPresetAppend(): void {
    $transport = new SubprocessCLITransport('test', new ClaudeAgentOptions(
      cliPath: '/usr/bin/claude',
      systemPrompt: ['type' => 'preset', 'preset' => 'claude_code', 'append' => 'Be concise.']
    ));
    $cmd = $this->invokePrivateMethod($transport, 'buildCommand');
    $this->assertNotContains('--system-prompt', $cmd);
    $this->assertContains('--append-system-prompt', $cmd);
    $this->assertContains('Be concise.', $cmd);
  }

  public function testBuildCommandSystemPromptPresetNoAppend(): void {
    $transport = new SubprocessCLITransport('test', new ClaudeAgentOptions(
      cliPath: '/usr/bin/claude',
      systemPrompt: ['type' => 'preset', 'preset' => 'claude_code'],
    ));
    $cmd = $this->invokePrivateMethod($transport, 'buildCommand');
    $this->assertNotContains('--system-prompt', $cmd);
    $this->assertNotContains('--append-system-prompt', $cmd);
  }

  public function testBuildCommandOptions(): void {
    $transport = new SubprocessCLITransport('test', new ClaudeAgentOptions(
      cliPath: '/usr/bin/claude',
      allowedTools: ['Read', 'Write'],
      disallowedTools: ['Bash'],
      model: 'claude-sonnet-4-5',
      permissionMode: 'acceptEdits',
      maxTurns: 5,
    ));
    $cmd = $this->invokePrivateMethod($transport, 'buildCommand');
    $this->assertContains('--allowedTools', $cmd);
    $this->assertContains('Read,Write', $cmd);
    $this->assertContains('--disallowedTools', $cmd);
    $this->assertContains('Bash', $cmd);
    $this->assertContains('--model', $cmd);
    $this->assertContains('claude-sonnet-4-5', $cmd);
    $this->assertContains('--permission-mode', $cmd);
    $this->assertContains('acceptEdits', $cmd);
    $this->assertContains('--max-turns', $cmd);
    $this->assertContains('5', $cmd);
  }

  public function testBuildCommandFallbackModel(): void {
    $transport = new SubprocessCLITransport('test', new ClaudeAgentOptions(
      cliPath: '/usr/bin/claude',
      model: 'opus',
      fallbackModel: 'sonnet',
    ));
    $cmd = $this->invokePrivateMethod($transport, 'buildCommand');
    $this->assertContains('--model', $cmd);
    $this->assertContains('opus', $cmd);
    $this->assertContains('--fallback-model', $cmd);
    $this->assertContains('sonnet', $cmd);
  }

  public function testBuildCommandMaxThinkingTokens(): void {
    $transport = new SubprocessCLITransport('test', new ClaudeAgentOptions(
      cliPath: '/usr/bin/claude',
      maxThinkingTokens: 5000,
    ));
    $cmd = $this->invokePrivateMethod($transport, 'buildCommand');
    $this->assertContains('--max-thinking-tokens', $cmd);
    $this->assertContains('5000', $cmd);
  }

  public function testBuildCommandAddDirs(): void {
    $transport = new SubprocessCLITransport('test', new ClaudeAgentOptions(
      cliPath: '/usr/bin/claude',
      addDirs: ['/path/to/dir1', '/path/to/dir2'],
    ));
    $cmd = $this->invokePrivateMethod($transport, 'buildCommand');
    $this->assertContains('--add-dir', $cmd);
  }

  public function testSessionContinuation(): void {
    $transport = new SubprocessCLITransport('Continue', new ClaudeAgentOptions(
      cliPath: '/usr/bin/claude',
      continueConversation: true,
      resume: 'session-123',
    ));
    $cmd = $this->invokePrivateMethod($transport, 'buildCommand');
    $this->assertContains('--continue', $cmd);
    $this->assertContains('--resume', $cmd);
    $this->assertContains('session-123', $cmd);
  }

  public function testBuildCommandSettingsJson(): void {
    $settingsJson = '{"permissions": {"allow": ["Bash(ls:*)"]}}';
    $transport = new SubprocessCLITransport('test', new ClaudeAgentOptions(
      cliPath: '/usr/bin/claude',
      settings: $settingsJson,
    ));
    $cmd = $this->invokePrivateMethod($transport, 'buildCommand');
    $this->assertContains('--settings', $cmd);
    $this->assertContains($settingsJson, $cmd);
  }

  public function testBuildCommandSettingsFilePath(): void {
    $transport = new SubprocessCLITransport('test', new ClaudeAgentOptions(
      cliPath: '/usr/bin/claude',
      settings: '/path/to/settings.json',
    ));
    $cmd = $this->invokePrivateMethod($transport, 'buildCommand');
    $this->assertContains('--settings', $cmd);
    $this->assertContains('/path/to/settings.json', $cmd);
  }

  public function testBuildCommandExtraArgs(): void {
    $transport = new SubprocessCLITransport('test', new ClaudeAgentOptions(
      cliPath: '/usr/bin/claude',
      extraArgs: [
        'new-flag' => 'value',
        'boolean-flag' => null,
      ],
    ));
    $cmd = $this->invokePrivateMethod($transport, 'buildCommand');
    $cmdStr = implode(' ', $cmd);
    $this->assertStringContainsString('--new-flag value', $cmdStr);
    $this->assertContains('--boolean-flag', $cmd);
  }

  public function testBuildCommandMcpServers(): void {
    $mcpServers = [
      'test-server' => [
        'type' => 'stdio',
        'command' => '/path/to/server',
        'args' => ['--option', 'value'],
      ],
    ];

    $transport = new SubprocessCLITransport('test', new ClaudeAgentOptions(
      cliPath: '/usr/bin/claude',
      mcpServers: $mcpServers,
    ));

    $cmd = $this->invokePrivateMethod($transport, 'buildCommand');
    $this->assertContains('--mcp-config', $cmd);
  }

  public function testBuildCommandMcpServersAsFilePath(): void {
    $transport = new SubprocessCLITransport('test', new ClaudeAgentOptions(
      cliPath: '/usr/bin/claude',
      mcpServers: '/path/to/mcp-config.json',
    ));
    $cmd = $this->invokePrivateMethod($transport, 'buildCommand');
    $this->assertContains('--mcp-config', $cmd);
    $this->assertContains('/path/to/mcp-config.json', $cmd);
  }

  public function testBuildCommandMcpServersAsJsonString(): void {
    $jsonConfig = '{\"mcpServers\": {\"server\": {\"type\": \"stdio\", \"command\": \"test\"}}}';
    $transport = new SubprocessCLITransport('test', new ClaudeAgentOptions(
      cliPath: '/usr/bin/claude',
      mcpServers: $jsonConfig,
    ));
    $cmd = $this->invokePrivateMethod($transport, 'buildCommand');
    $this->assertContains('--mcp-config', $cmd);
    $this->assertContains($jsonConfig, $cmd);
  }

  public function testBuildCommandSandboxMerged(): void {
    $settingsJson = '{"permissions": {"allow": ["Bash(ls:*)"]}, "verbose": true}';
    $sandbox = ['enabled' => true, 'excludedCommands' => ['git', 'docker']];

    $transport = new SubprocessCLITransport('test', new ClaudeAgentOptions(
      cliPath: '/usr/bin/claude',
      settings: $settingsJson,
      sandbox: $sandbox,
    ));

    $cmd = $this->invokePrivateMethod($transport, 'buildCommand');
    $settingsIdx = array_search('--settings', $cmd);
    $parsed = json_decode($cmd[$settingsIdx + 1], true);

    $this->assertSame(['allow' => ['Bash(ls:*)']], $parsed['permissions']);
    $this->assertTrue($parsed['verbose']);
    $this->assertSame($sandbox, $parsed['sandbox']);
  }

  public function testBuildCommandSandboxOnly(): void {
    $sandbox = ['enabled' => true, 'autoAllowBashIfSandboxed' => true];
    $transport = new SubprocessCLITransport('test', new ClaudeAgentOptions(
      cliPath: '/usr/bin/claude',
      sandbox: $sandbox,
    ));

    $cmd = $this->invokePrivateMethod($transport, 'buildCommand');
    $settingsIdx = array_search('--settings', $cmd);
    $parsed = json_decode($cmd[$settingsIdx + 1], true);

    $this->assertSame($sandbox, $parsed['sandbox']);
  }

  public function testBuildCommandToolsArray(): void {
    $transport = new SubprocessCLITransport('test', new ClaudeAgentOptions(
      cliPath: '/usr/bin/claude',
      tools: ['Read', 'Edit', 'Bash'],
    ));

    $cmd = $this->invokePrivateMethod($transport, 'buildCommand');
    $this->assertContains('--tools', $cmd);
    $toolsIdx = array_search('--tools', $cmd);
    $this->assertSame('Read,Edit,Bash', $cmd[$toolsIdx + 1]);
  }

  public function testBuildCommandToolsEmptyArray(): void {
    $transport = new SubprocessCLITransport('test', new ClaudeAgentOptions(
      cliPath: '/usr/bin/claude',
      tools: [],
    ));

    $cmd = $this->invokePrivateMethod($transport, 'buildCommand');
    $toolsIdx = array_search('--tools', $cmd);
    $this->assertSame('', $cmd[$toolsIdx + 1]);
  }

  public function testBuildCommandToolsPreset(): void {
    $transport = new SubprocessCLITransport('test', new ClaudeAgentOptions(
      cliPath: '/usr/bin/claude',
      tools: ['type' => 'preset', 'preset' => 'claude_code'],
    ));

    $cmd = $this->invokePrivateMethod($transport, 'buildCommand');
    $toolsIdx = array_search('--tools', $cmd);
    $this->assertSame('default', $cmd[$toolsIdx + 1]);
  }

  public function testBuildCommandWithoutTools(): void {
    $transport = new SubprocessCLITransport('test', new ClaudeAgentOptions(cliPath: '/usr/bin/claude'));
    $cmd = $this->invokePrivateMethod($transport, 'buildCommand');
    $this->assertNotContains('--tools', $cmd);
  }

  public function testBuildEnvIncludesCustomVars(): void {
    $options = new ClaudeAgentOptions(cliPath: '/usr/bin/claude', env: ['MY_TEST_VAR' => 'abc']);
    $transport = new SubprocessCLITransport('test', $options);
    $env = $this->invokePrivateMethod($transport, 'buildEnv');
    $this->assertSame('abc', $env['MY_TEST_VAR']);
    $this->assertSame('sdk-php', $env['CLAUDE_CODE_ENTRYPOINT']);
  }

  private function invokePrivateMethod(object $object, string $method): array {
    $ref = new \ReflectionClass($object);
    $methodRef = $ref->getMethod($method);
    $methodRef->setAccessible(true);
    return $methodRef->invoke($object);
  }

}
