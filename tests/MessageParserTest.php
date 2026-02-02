<?php

declare(strict_types=1);

namespace Claude\AgentSdk\Tests;

use Claude\AgentSdk\Exceptions\MessageParseError;
use Claude\AgentSdk\Types\ContentBlockFactory;
use Claude\AgentSdk\Types\MessageFactory;
use Claude\AgentSdk\Types\TextBlock;
use Claude\AgentSdk\Types\ToolResultBlock;
use Claude\AgentSdk\Types\ToolUseBlock;
use PHPUnit\Framework\TestCase;

final class MessageParserTest extends TestCase {

  public function testParseValidUserMessage(): void {
    $data = [
      'type' => 'user',
      'message' => [
        'content' => [
          ['type' => 'text', 'text' => 'Hello'],
        ],
      ],
    ];

    $message = MessageFactory::fromArray($data);
    $this->assertSame('user', $message->getType());
  }

  public function testParseUserMessageWithUuid(): void {
    $data = [
      'type' => 'user',
      'uuid' => 'msg-abc123-def456',
      'message' => [
        'content' => [
          ['type' => 'text', 'text' => 'Hello'],
        ],
      ],
    ];

    $message = MessageFactory::fromArray($data);
    $this->assertSame('user', $message->getType());
    $this->assertSame('msg-abc123-def456', $message->uuid);
  }

  public function testParseUserMessageWithToolUse(): void {
    $data = [
      'type' => 'user',
      'message' => [
        'content' => [
          ['type' => 'text', 'text' => 'Let me read this file'],
          ['type' => 'tool_use', 'id' => 'tool_456', 'name' => 'Read', 'input' => ['file_path' => '/example.txt']],
        ],
      ],
    ];

    $message = MessageFactory::fromArray($data);
    $this->assertSame(2, count($message->content));
    $this->assertInstanceOf(TextBlock::class, $message->content[0]);
    $this->assertInstanceOf(ToolUseBlock::class, $message->content[1]);
  }

  public function testParseUserMessageWithToolResult(): void {
    $data = [
      'type' => 'user',
      'message' => [
        'content' => [
          ['type' => 'tool_result', 'tool_use_id' => 'tool_789', 'content' => 'File contents here'],
        ],
      ],
    ];

    $message = MessageFactory::fromArray($data);
    $this->assertInstanceOf(ToolResultBlock::class, $message->content[0]);
  }

  public function testParseUserMessageInsideSubagent(): void {
    $data = [
      'type' => 'user',
      'message' => [
        'content' => [
          ['type' => 'text', 'text' => 'Hello'],
        ],
      ],
      'parent_tool_use_id' => 'toolu_01Xrwd5Y13sEHtzScxR77So8',
    ];

    $message = MessageFactory::fromArray($data);
    $this->assertSame('toolu_01Xrwd5Y13sEHtzScxR77So8', $message->parentToolUseId);
  }

  public function testContentBlockMissingType(): void {
    $this->expectException(MessageParseError::class);
    ContentBlockFactory::fromArray(['text' => 'missing']);
  }

  public function testParseSystemMessageRequiresSubtype(): void {
    $this->expectException(MessageParseError::class);
    MessageFactory::fromArray(['type' => 'system']);
  }

  public function testParseResultMessageRequiresFields(): void {
    $this->expectException(MessageParseError::class);
    MessageFactory::fromArray(['type' => 'result']);
  }

  public function testParseStreamEventRequiresFields(): void {
    $this->expectException(MessageParseError::class);
    MessageFactory::fromArray(['type' => 'stream_event']);
  }

}
