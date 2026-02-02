<?php

declare(strict_types=1);

namespace Claude\AgentSdk\Types;

use Claude\AgentSdk\Exceptions\MessageParseError;

interface ContentBlockInterface {
  public function getType(): string;
}

final class TextBlock implements ContentBlockInterface {
  public function __construct(public readonly string $text) {}

  public function getType(): string {
    return 'text';
  }
}

final class ThinkingBlock implements ContentBlockInterface {
  public function __construct(
    public readonly string $thinking,
    public readonly string $signature,
  ) {}

  public function getType(): string {
    return 'thinking';
  }
}

final class ToolUseBlock implements ContentBlockInterface {
  public function __construct(
    public readonly string $id,
    public readonly string $name,
    public readonly array $input,
  ) {}

  public function getType(): string {
    return 'tool_use';
  }
}

final class ToolResultBlock implements ContentBlockInterface {
  public function __construct(
    public readonly string $toolUseId,
    public readonly mixed $content = null,
    public readonly ?bool $isError = null,
  ) {}

  public function getType(): string {
    return 'tool_result';
  }
}

final class ContentBlockFactory {

  public static function fromArray(array $block): ContentBlockInterface {
    $type = $block['type'] ?? null;
    if (!is_string($type)) {
      throw new MessageParseError('Content block missing type.', $block);
    }

    return match ($type) {
      'text' => new TextBlock((string) ($block['text'] ?? '')),
      'thinking' => new ThinkingBlock(
        (string) ($block['thinking'] ?? ''),
        (string) ($block['signature'] ?? '')
      ),
      'tool_use' => new ToolUseBlock(
        (string) ($block['id'] ?? ''),
        (string) ($block['name'] ?? ''),
        is_array($block['input'] ?? null) ? $block['input'] : []
      ),
      'tool_result' => new ToolResultBlock(
        (string) ($block['tool_use_id'] ?? ''),
        $block['content'] ?? null,
        isset($block['is_error']) ? (bool) $block['is_error'] : null
      ),
      default => new class($type) implements ContentBlockInterface {
        public function __construct(private readonly string $type) {}
        public function getType(): string {
          return $this->type;
        }
      },
    };
  }

}
