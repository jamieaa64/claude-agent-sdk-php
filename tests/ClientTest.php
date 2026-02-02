<?php

declare(strict_types=1);

namespace Claude\AgentSdk\Tests;

use Claude\AgentSdk\ClaudeAgentOptions;
use Claude\AgentSdk\Query;
use Claude\AgentSdk\Tests\Support\FakeTransport;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase {

  public function testQuerySinglePrompt(): void {
    $transport = new FakeTransport([
      [
        'type' => 'assistant',
        'message' => [
          'content' => [
            ['type' => 'text', 'text' => '4'],
          ],
        ],
      ],
    ]);

    $messages = iterator_to_array(Query::query('What is 2+2?', new ClaudeAgentOptions(), $transport));
    $this->assertCount(1, $messages);
    $this->assertSame('assistant', $messages[0]->getType());
  }

  public function testQueryWithOptions(): void {
    $transport = new FakeTransport([
      [
        'type' => 'assistant',
        'message' => [
          'content' => [
            ['type' => 'text', 'text' => 'Hello!'],
          ],
        ],
      ],
    ]);

    $options = new ClaudeAgentOptions(
      allowedTools: ['Read', 'Write'],
      systemPrompt: 'You are helpful',
      permissionMode: 'acceptEdits',
      maxTurns: 5,
    );

    $messages = iterator_to_array(Query::query('Hi', $options, $transport));
    $this->assertCount(1, $messages);
  }

}
