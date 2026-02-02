<?php

declare(strict_types=1);

namespace Claude\AgentSdk\Tests;

use Claude\AgentSdk\ClaudeAgentOptions;
use Claude\AgentSdk\Query;
use Claude\AgentSdk\Tests\Support\FakeTransport;
use Claude\AgentSdk\Types\ToolUseBlock;
use PHPUnit\Framework\TestCase;

final class IntegrationTest extends TestCase {

  public function testSimpleQueryResponse(): void {
    $transport = new FakeTransport([
      [
        'type' => 'assistant',
        'message' => [
          'content' => [
            ['type' => 'text', 'text' => '2 + 2 equals 4'],
          ],
        ],
      ],
      [
        'type' => 'result',
        'subtype' => 'success',
        'duration_ms' => 1000,
        'duration_api_ms' => 800,
        'is_error' => false,
        'num_turns' => 1,
        'session_id' => 'test-session',
        'total_cost_usd' => 0.001,
      ],
    ]);

    $messages = iterator_to_array(Query::query('What is 2 + 2?', new ClaudeAgentOptions(), $transport));
    $this->assertCount(2, $messages);
    $this->assertSame('assistant', $messages[0]->getType());
    $this->assertSame('result', $messages[1]->getType());
  }

  public function testQueryWithToolUse(): void {
    $transport = new FakeTransport([
      [
        'type' => 'assistant',
        'message' => [
          'content' => [
            ['type' => 'text', 'text' => 'Let me read that file for you.'],
            ['type' => 'tool_use', 'id' => 'tool-123', 'name' => 'Read', 'input' => ['file_path' => '/test.txt']],
          ],
        ],
      ],
      [
        'type' => 'result',
        'subtype' => 'success',
        'duration_ms' => 1500,
        'duration_api_ms' => 1200,
        'is_error' => false,
        'num_turns' => 1,
        'session_id' => 'test-session-2',
        'total_cost_usd' => 0.002,
      ],
    ]);

    $messages = iterator_to_array(Query::query('Read /test.txt', new ClaudeAgentOptions(allowedTools: ['Read']), $transport));
    $this->assertCount(2, $messages);
    $assistant = $messages[0];
    $this->assertSame('assistant', $assistant->getType());
    $this->assertInstanceOf(ToolUseBlock::class, $assistant->content[1]);
  }

  public function testContinuationOption(): void {
    $transport = new FakeTransport([
      [
        'type' => 'assistant',
        'message' => [
          'content' => [
            ['type' => 'text', 'text' => 'Continuing from previous conversation'],
          ],
        ],
      ],
    ]);

    $messages = iterator_to_array(Query::query('Continue', new ClaudeAgentOptions(continueConversation: true), $transport));
    $this->assertCount(1, $messages);
  }

  public function testMaxBudgetUsdOption(): void {
    $transport = new FakeTransport([
      [
        'type' => 'assistant',
        'message' => [
          'content' => [
            ['type' => 'text', 'text' => 'Starting to read...'],
          ],
        ],
      ],
      [
        'type' => 'result',
        'subtype' => 'error_max_budget_usd',
        'duration_ms' => 500,
        'duration_api_ms' => 400,
        'is_error' => false,
        'num_turns' => 1,
        'session_id' => 'test-session-budget',
        'total_cost_usd' => 0.0002,
        'usage' => ['input_tokens' => 100, 'output_tokens' => 50],
      ],
    ]);

    $messages = iterator_to_array(Query::query('Read the readme', new ClaudeAgentOptions(maxBudgetUsd: 0.0001), $transport));
    $this->assertCount(2, $messages);
    $this->assertSame('error_max_budget_usd', $messages[1]->subtype);
  }

  public function testQueryWithIterableUsesStdin(): void {
    if (stripos(PHP_OS, 'WIN') === 0) {
      $this->markTestSkipped('CLI integration test skipped on Windows.');
    }
    if (!class_exists(\Symfony\Component\Process\Process::class)) {
      $this->markTestSkipped('symfony/process not installed.');
    }

    $script = sys_get_temp_dir() . '/claude_sdk_php_test_cli.php';
    $scriptContents = <<<'SCRIPT'
#!/usr/bin/env php
<?php
$messages = [];
while (($line = fgets(STDIN)) !== false) {
  $line = trim($line);
  if ($line === '') {
    continue;
  }
  $messages[] = $line;
}
if (count($messages) < 2) {
  fwrite(STDERR, "Expected two messages\n");
  exit(1);
}
$result = [
  'type' => 'result',
  'subtype' => 'success',
  'duration_ms' => 100,
  'duration_api_ms' => 50,
  'is_error' => false,
  'num_turns' => 1,
  'session_id' => 'test',
  'total_cost_usd' => 0.001,
];
print(json_encode($result) . "\n");
SCRIPT;
    file_put_contents($script, $scriptContents);
    chmod($script, 0755);

    $stream = (function (): iterable {
      yield ['type' => 'user', 'message' => ['role' => 'user', 'content' => 'First']];
      yield ['type' => 'user', 'message' => ['role' => 'user', 'content' => 'Second']];
    })();

    $options = new ClaudeAgentOptions(cliPath: $script);

    $messages = iterator_to_array(Query::query($stream, $options));
    $this->assertCount(1, $messages);
    $this->assertSame('result', $messages[0]->getType());

    @unlink($script);
  }

}
