<?php

declare(strict_types=1);

namespace Prism\Prism\Structured;

use Closure;
use Prism\Prism\Concerns\ChecksSelf;
use Prism\Prism\Concerns\HasProviderOptions;
use Prism\Prism\Contracts\Message;
use Prism\Prism\Contracts\PrismRequest;
use Prism\Prism\Contracts\Schema;
use Prism\Prism\Enums\StructuredMode;
use Prism\Prism\Enums\ToolChoice;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\ProviderTool;

class Request implements PrismRequest
{
    use ChecksSelf, HasProviderOptions;

    /**
     * @param  SystemMessage[]  $systemPrompts
     * @param  array<int, Message>  $messages
     * @param  array<int, Tool>  $tools
     * @param  array<string, mixed>  $clientOptions
     * @param  array{0: array<int, int>|int, 1?: Closure|int, 2?: ?callable, 3?: bool}  $clientRetry
     * @param  array<string, mixed>  $providerOptions
     * @param  array<int, ProviderTool>  $providerTools
     */
    public function __construct(
        protected array $systemPrompts,
        protected string $model,
        protected string $providerKey,
        protected ?string $prompt,
        protected array $messages,
        protected ?int $maxTokens,
        protected int|float|null $temperature,
        protected int|float|null $topP,
        protected int $maxSteps,
        protected array $tools,
        protected array $clientOptions,
        protected array $clientRetry,
        protected Schema $schema,
        protected StructuredMode $mode,
        protected string|ToolChoice|null $toolChoice = null,
        protected array $providerTools = [],
        array $providerOptions = [],
    ) {
        $this->providerOptions = $providerOptions;
    }

    /**
     * @return SystemMessage[]
     */
    public function systemPrompts(): array
    {
        return $this->systemPrompts;
    }

    #[\Override]
    public function model(): string
    {
        return $this->model;
    }

    public function provider(): string
    {
        return $this->providerKey;
    }

    public function prompt(): ?string
    {
        return $this->prompt;
    }

    /**
     * @return array<int, Message>
     */
    public function messages(): array
    {
        return $this->messages;
    }

    public function maxTokens(): ?int
    {
        return $this->maxTokens;
    }

    public function maxSteps(): int
    {
        return $this->maxSteps;
    }

    /**
     * @return Tool[]
     */
    public function tools(): array
    {
        return $this->tools;
    }

    public function temperature(): int|float|null
    {
        return $this->temperature;
    }

    public function topP(): int|float|null
    {
        return $this->topP;
    }

    /**
     * @return array<string, mixed>
     */
    public function clientOptions(): array
    {
        return $this->clientOptions;
    }

    /**
     * @return array{0: array<int, int>|int, 1?: Closure|int, 2?: ?callable, 3?: bool}
     */
    public function clientRetry(): array
    {
        return $this->clientRetry;
    }

    /**
     * @return array<int,ProviderTool>
     */
    public function providerTools(): array
    {
        return $this->providerTools;
    }

    public function schema(): Schema
    {
        return $this->schema;
    }

    public function mode(): StructuredMode
    {
        return $this->mode;
    }

    public function toolChoice(): string|ToolChoice|null
    {
        return $this->toolChoice;
    }

    public function addMessage(Message $message): self
    {
        $this->messages = array_merge($this->messages, [$message]);

        return $this;
    }
}
