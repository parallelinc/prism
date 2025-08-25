<?php

declare(strict_types=1);

namespace Prism\Prism\Structured;

use Illuminate\Http\Client\RequestException;
use Prism\Prism\Concerns\ConfiguresClient;
use Prism\Prism\Concerns\ConfiguresGeneration;
use Prism\Prism\Concerns\ConfiguresModels;
use Prism\Prism\Concerns\ConfiguresProviders;
use Prism\Prism\Concerns\ConfiguresStructuredOutput;
use Prism\Prism\Concerns\ConfiguresTools;
use Prism\Prism\Concerns\HasMessages;
use Prism\Prism\Concerns\HasPrompts;
use Prism\Prism\Concerns\HasProviderOptions;
use Prism\Prism\Concerns\HasProviderTools;
use Prism\Prism\Concerns\HasSchema;
use Prism\Prism\Concerns\HasTools;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Messages\UserMessage;

class PendingRequest
{
    use ConfiguresClient;
    use ConfiguresGeneration;
    use ConfiguresModels;
    use ConfiguresProviders;
    use ConfiguresStructuredOutput;
    use ConfiguresTools;
    use HasMessages;
    use HasPrompts;
    use HasProviderOptions;
    use HasProviderTools;
    use HasSchema;
    use HasTools;

    /**
     * @deprecated Use `asStructured` instead.
     */
    public function generate(): Response
    {
        return $this->asStructured();
    }

    public function asStructured(): Response
    {
        $request = $this->toRequest();

        try {
            return $this->provider->structured($request);
        } catch (RequestException $e) {
            $this->provider->handleRequestException($request->model(), $e);
        }
    }

    public function toRequest(): Request
    {
        if ($this->messages && $this->prompt) {
            throw PrismException::promptOrMessages();
        }

        $messages = $this->messages;

        if ($this->prompt) {
            $messages[] = new UserMessage($this->prompt, $this->additionalContent);
        }

        if (! $this->schema instanceof \Prism\Prism\Contracts\Schema) {
            throw new PrismException('A schema is required for structured output');
        }

        $tools = $this->tools;

        if (! $this->toolErrorHandlingEnabled && filled($tools)) {
            $tools = array_map(
                callback: fn ($tool): Tool => $tool instanceof Tool && ! is_null($tool->failedHandler()) ? $tool->withoutErrorHandling() : $tool,
                array: $tools
            );
        }

        return new Request(
            model: $this->model,
            providerKey: $this->providerKey(),
            systemPrompts: $this->systemPrompts,
            prompt: $this->prompt,
            messages: $messages,
            temperature: $this->temperature,
            maxTokens: $this->maxTokens,
            topP: $this->topP,
            maxSteps: $this->maxSteps,
            tools: $tools,
            clientOptions: $this->clientOptions,
            clientRetry: $this->clientRetry,
            providerOptions: $this->providerOptions,
            schema: $this->schema,
            mode: $this->structuredMode,
            toolChoice: $this->toolChoice,
            providerTools: $this->providerTools,
        );
    }
}
