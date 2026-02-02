<?php

declare(strict_types=1);

namespace Claude\AgentSdk\Types;

use Claude\AgentSdk\Exceptions\MessageParseError;

interface MessageInterface {
  public function getType(): string;

  public function getRaw(): array;
}

abstract class AbstractMessage implements MessageInterface {

  public function __construct(
    protected readonly string $type,
    protected readonly array $raw,
  ) {
  }

  public function getType(): string {
    return $this->type;
  }

  public function getRaw(): array {
    return $this->raw;
  }

}

final class UserMessage extends AbstractMessage {

  public function __construct(
    array $raw,
    public readonly mixed $content,
    public readonly ?string $uuid = null,
    public readonly ?string $parentToolUseId = null,
    public readonly mixed $toolUseResult = null,
  ) {
    parent::__construct('user', $raw);
  }

}

final class AssistantMessage extends AbstractMessage {

  /**
   * @param ContentBlockInterface[] $content
   */
  public function __construct(
    array $raw,
    public readonly array $content,
    public readonly ?string $model = null,
    public readonly ?string $parentToolUseId = null,
    public readonly ?string $error = null,
  ) {
    parent::__construct('assistant', $raw);
  }

}

final class SystemMessage extends AbstractMessage {

  public function __construct(
    array $raw,
    public readonly string $subtype,
  ) {
    parent::__construct('system', $raw);
  }

}

final class ResultMessage extends AbstractMessage {

  public function __construct(
    array $raw,
    public readonly string $subtype,
    public readonly int $durationMs,
    public readonly int $durationApiMs,
    public readonly bool $isError,
    public readonly int $numTurns,
    public readonly string $sessionId,
    public readonly ?float $totalCostUsd = null,
    public readonly mixed $usage = null,
    public readonly mixed $result = null,
    public readonly mixed $structuredOutput = null,
  ) {
    parent::__construct('result', $raw);
  }

}

final class StreamEvent extends AbstractMessage {

  public function __construct(
    array $raw,
    public readonly string $uuid,
    public readonly string $sessionId,
    public readonly string $event,
    public readonly ?string $parentToolUseId = null,
  ) {
    parent::__construct('stream_event', $raw);
  }

}

final class MessageFactory {

  public static function fromArray(array $data): MessageInterface {
    $type = $data['type'] ?? null;
    if (!is_string($type)) {
      throw new MessageParseError('Message missing type field.', $data);
    }

    return match ($type) {
      'user' => self::parseUser($data),
      'assistant' => self::parseAssistant($data),
      'system' => self::parseSystem($data),
      'result' => self::parseResult($data),
      'stream_event' => self::parseStreamEvent($data),
      default => new class($type, $data) extends AbstractMessage {
        public function __construct(string $type, array $raw) {
          parent::__construct($type, $raw);
        }
      },
    };
  }

  private static function parseUser(array $data): UserMessage {
    $message = $data['message'] ?? [];
    $content = $message['content'] ?? '';

    if (is_array($content)) {
      $blocks = [];
      foreach ($content as $block) {
        $blocks[] = ContentBlockFactory::fromArray($block);
      }
      $content = $blocks;
    }

    return new UserMessage(
      raw: $data,
      content: $content,
      uuid: $data['uuid'] ?? null,
      parentToolUseId: $data['parent_tool_use_id'] ?? null,
      toolUseResult: $data['tool_use_result'] ?? null,
    );
  }

  private static function parseAssistant(array $data): AssistantMessage {
    $message = $data['message'] ?? [];
    $content = $message['content'] ?? [];

    $blocks = [];
    if (is_array($content)) {
      foreach ($content as $block) {
        $blocks[] = ContentBlockFactory::fromArray($block);
      }
    }

    return new AssistantMessage(
      raw: $data,
      content: $blocks,
      model: $message['model'] ?? null,
      parentToolUseId: $data['parent_tool_use_id'] ?? null,
      error: $message['error'] ?? null,
    );
  }

  private static function parseSystem(array $data): SystemMessage {
    $subtype = $data['subtype'] ?? null;
    if (!is_string($subtype)) {
      throw new MessageParseError('System message missing subtype.', $data);
    }
    return new SystemMessage($data, $subtype);
  }

  private static function parseResult(array $data): ResultMessage {
    foreach (['subtype', 'duration_ms', 'duration_api_ms', 'is_error', 'num_turns', 'session_id'] as $field) {
      if (!array_key_exists($field, $data)) {
        throw new MessageParseError('Result message missing field: ' . $field, $data);
      }
    }

    return new ResultMessage(
      raw: $data,
      subtype: (string) $data['subtype'],
      durationMs: (int) $data['duration_ms'],
      durationApiMs: (int) $data['duration_api_ms'],
      isError: (bool) $data['is_error'],
      numTurns: (int) $data['num_turns'],
      sessionId: (string) $data['session_id'],
      totalCostUsd: isset($data['total_cost_usd']) ? (float) $data['total_cost_usd'] : null,
      usage: $data['usage'] ?? null,
      result: $data['result'] ?? null,
      structuredOutput: $data['structured_output'] ?? null,
    );
  }

  private static function parseStreamEvent(array $data): StreamEvent {
    foreach (['uuid', 'session_id', 'event'] as $field) {
      if (!array_key_exists($field, $data)) {
        throw new MessageParseError('Stream event missing field: ' . $field, $data);
      }
    }

    return new StreamEvent(
      raw: $data,
      uuid: (string) $data['uuid'],
      sessionId: (string) $data['session_id'],
      event: (string) $data['event'],
      parentToolUseId: $data['parent_tool_use_id'] ?? null,
    );
  }

}
