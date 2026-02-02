<?php

declare(strict_types=1);

namespace Claude\AgentSdk\Tests;

use Claude\AgentSdk\ClaudeAgentOptions;
use Claude\AgentSdk\Types\TextBlock;
use Claude\AgentSdk\Types\ThinkingBlock;
use Claude\AgentSdk\Types\ToolResultBlock;
use Claude\AgentSdk\Types\ToolUseBlock;
use PHPUnit\Framework\TestCase;

final class TypesTest extends TestCase {

  public function testTextBlockType(): void {
    $block = new TextBlock('hi');
    $this->assertSame('text', $block->getType());
  }

  public function testThinkingBlock(): void {
    $block = new ThinkingBlock('thinking', 'sig-123');
    $this->assertSame('thinking', $block->getType());
    $this->assertSame('thinking', $block->thinking);
    $this->assertSame('sig-123', $block->signature);
  }

  public function testToolUseBlock(): void {
    $block = new ToolUseBlock('tool-123', 'Read', ['file_path' => '/test.txt']);
    $this->assertSame('tool_use', $block->getType());
    $this->assertSame('Read', $block->name);
  }

  public function testToolResultBlock(): void {
    $block = new ToolResultBlock('tool-123', 'File contents', false);
    $this->assertSame('tool_result', $block->getType());
    $this->assertSame('tool-123', $block->toolUseId);
    $this->assertFalse($block->isError);
  }

  public function testDefaultOptions(): void {
    $options = new ClaudeAgentOptions();
    $this->assertNull($options->systemPrompt);
    $this->assertNull($options->permissionMode);
    $this->assertFalse($options->continueConversation);
  }

  public function testOptionsWithTools(): void {
    $options = new ClaudeAgentOptions(allowedTools: ['Read', 'Write'], disallowedTools: ['Bash']);
    $this->assertSame(['Read', 'Write'], $options->allowedTools);
    $this->assertSame(['Bash'], $options->disallowedTools);
  }

  public function testOptionsWithPermissionMode(): void {
    $options = new ClaudeAgentOptions(permissionMode: 'acceptEdits');
    $this->assertSame('acceptEdits', $options->permissionMode);
  }

  public function testOptionsWithSystemPromptPreset(): void {
    $options = new ClaudeAgentOptions(systemPrompt: ['type' => 'preset', 'preset' => 'claude_code']);
    $this->assertSame(['type' => 'preset', 'preset' => 'claude_code'], $options->systemPrompt);
  }

  public function testOptionsSessionContinuation(): void {
    $options = new ClaudeAgentOptions(continueConversation: true, resume: 'session-123');
    $this->assertTrue($options->continueConversation);
    $this->assertSame('session-123', $options->resume);
  }

}
