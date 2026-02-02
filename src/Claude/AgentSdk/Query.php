<?php

declare(strict_types=1);

namespace Claude\AgentSdk;

use Claude\AgentSdk\Transport\TransportInterface;
use Claude\AgentSdk\Transport\SubprocessCLITransport;
use Claude\AgentSdk\Types\MessageFactory;
use Claude\AgentSdk\Types\MessageInterface;

final class Query {

  /**
   * @return iterable<MessageInterface>
   */
  public static function query(string|iterable $prompt, ?ClaudeAgentOptions $options = null, ?TransportInterface $transport = null): iterable {
    $options ??= new ClaudeAgentOptions();
    $transport ??= new SubprocessCLITransport($prompt, $options);

    $transport->connect();

    if (is_iterable($prompt) && !is_string($prompt)) {
      foreach ($prompt as $message) {
        $transport->write(json_encode($message) . "\n");
      }
      $transport->closeInput();
    }

    foreach ($transport->readMessages() as $data) {
      yield MessageFactory::fromArray($data);
    }

    $transport->close();
  }

}
