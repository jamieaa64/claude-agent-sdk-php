<?php

declare(strict_types=1);

namespace Claude\AgentSdk;

use Stringable;

final class ClaudeAgentOptions {

  public readonly ?\Closure $canUseTool;
  public readonly ?\Closure $mcpMessageHandler;

  public function __construct(
    public readonly string|Stringable|null $cliPath = null,
    public readonly string|Stringable|null $cwd = null,
    public readonly string|array|null $systemPrompt = null,
    public readonly array|string|null $tools = null,
    public readonly ?array $allowedTools = null,
    public readonly ?array $disallowedTools = null,
    public readonly ?int $maxTurns = null,
    public readonly ?float $maxBudgetUsd = null,
    public readonly ?string $model = null,
    public readonly ?string $fallbackModel = null,
    public readonly ?array $betas = null,
    public readonly ?string $permissionPromptToolName = null,
    public readonly ?string $permissionMode = null,
    public readonly bool $continueConversation = false,
    public readonly ?string $resume = null,
    public readonly ?string $settings = null,
    public readonly ?array $sandbox = null,
    public readonly ?array $addDirs = null,
    public readonly array|string|null $mcpServers = null,
    public readonly bool $includePartialMessages = false,
    public readonly bool $forkSession = false,
    public readonly ?array $agents = null,
    public readonly ?array $settingSources = null,
    public readonly ?array $plugins = null,
    public readonly ?int $maxThinkingTokens = null,
    public readonly ?array $outputFormat = null,
    public readonly ?int $maxBufferSize = null,
    public readonly bool $enableFileCheckpointing = false,
    public readonly ?array $hooks = null,
    callable|\Closure|null $canUseTool = null,
    callable|\Closure|null $mcpMessageHandler = null,
    public readonly bool $skipInitialize = false,
    public readonly ?float $initializeTimeout = null,
    public readonly ?string $user = null,
    public readonly array $env = [],
    public readonly array $extraArgs = [],
  ) {
    $this->canUseTool = $canUseTool ? \Closure::fromCallable($canUseTool) : null;
    $this->mcpMessageHandler = $mcpMessageHandler ? \Closure::fromCallable($mcpMessageHandler) : null;
  }

  public function with(array $overrides): self {
    return new self(
      cliPath: $overrides['cliPath'] ?? $this->cliPath,
      cwd: $overrides['cwd'] ?? $this->cwd,
      systemPrompt: $overrides['systemPrompt'] ?? $this->systemPrompt,
      tools: $overrides['tools'] ?? $this->tools,
      allowedTools: $overrides['allowedTools'] ?? $this->allowedTools,
      disallowedTools: $overrides['disallowedTools'] ?? $this->disallowedTools,
      maxTurns: $overrides['maxTurns'] ?? $this->maxTurns,
      maxBudgetUsd: $overrides['maxBudgetUsd'] ?? $this->maxBudgetUsd,
      model: $overrides['model'] ?? $this->model,
      fallbackModel: $overrides['fallbackModel'] ?? $this->fallbackModel,
      betas: $overrides['betas'] ?? $this->betas,
      permissionPromptToolName: $overrides['permissionPromptToolName'] ?? $this->permissionPromptToolName,
      permissionMode: $overrides['permissionMode'] ?? $this->permissionMode,
      continueConversation: $overrides['continueConversation'] ?? $this->continueConversation,
      resume: $overrides['resume'] ?? $this->resume,
      settings: $overrides['settings'] ?? $this->settings,
      sandbox: $overrides['sandbox'] ?? $this->sandbox,
      addDirs: $overrides['addDirs'] ?? $this->addDirs,
      mcpServers: $overrides['mcpServers'] ?? $this->mcpServers,
      includePartialMessages: $overrides['includePartialMessages'] ?? $this->includePartialMessages,
      forkSession: $overrides['forkSession'] ?? $this->forkSession,
      agents: $overrides['agents'] ?? $this->agents,
      settingSources: $overrides['settingSources'] ?? $this->settingSources,
      plugins: $overrides['plugins'] ?? $this->plugins,
      maxThinkingTokens: $overrides['maxThinkingTokens'] ?? $this->maxThinkingTokens,
      outputFormat: $overrides['outputFormat'] ?? $this->outputFormat,
      maxBufferSize: $overrides['maxBufferSize'] ?? $this->maxBufferSize,
      enableFileCheckpointing: $overrides['enableFileCheckpointing'] ?? $this->enableFileCheckpointing,
      hooks: $overrides['hooks'] ?? $this->hooks,
      canUseTool: $overrides['canUseTool'] ?? $this->canUseTool,
      mcpMessageHandler: $overrides['mcpMessageHandler'] ?? $this->mcpMessageHandler,
      skipInitialize: $overrides['skipInitialize'] ?? $this->skipInitialize,
      initializeTimeout: $overrides['initializeTimeout'] ?? $this->initializeTimeout,
      user: $overrides['user'] ?? $this->user,
      env: $overrides['env'] ?? $this->env,
      extraArgs: $overrides['extraArgs'] ?? $this->extraArgs,
    );
  }

}
