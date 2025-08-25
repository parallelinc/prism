<?php

namespace Prism\Prism\Providers\OpenAI\Handlers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Support\Arr;
use Prism\Prism\Concerns\CallsTools;
use Prism\Prism\Enums\StructuredMode;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Providers\OpenAI\Concerns\MapsFinishReason;
use Prism\Prism\Providers\OpenAI\Concerns\ProcessRateLimits;
use Prism\Prism\Providers\OpenAI\Concerns\ValidatesResponse;
use Prism\Prism\Providers\OpenAI\Maps\MessageMap;
use Prism\Prism\Providers\OpenAI\Maps\ToolCallMap;
use Prism\Prism\Providers\OpenAI\Maps\ToolChoiceMap;
use Prism\Prism\Providers\OpenAI\Maps\ToolMap;
use Prism\Prism\Providers\OpenAI\Support\StructuredModeResolver;
use Prism\Prism\Structured\Request;
use Prism\Prism\Structured\Response as StructuredResponse;
use Prism\Prism\Structured\ResponseBuilder;
use Prism\Prism\Structured\Step;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\ProviderTool;
use Prism\Prism\ValueObjects\Usage;

class Structured
{
    use CallsTools;
    use MapsFinishReason;
    use ProcessRateLimits;
    use ValidatesResponse;

    protected ResponseBuilder $responseBuilder;

    public function __construct(protected PendingRequest $client)
    {
        $this->responseBuilder = new ResponseBuilder;
    }

    public function handle(Request $request): StructuredResponse
    {
        $response = match ($request->mode()) {
            StructuredMode::Auto => $this->handleAutoMode($request),
            StructuredMode::Structured => $this->handleStructuredMode($request),
            StructuredMode::Json => $this->handleJsonMode($request),

        };

        $this->validateResponse($response);

        $data = $response->json();

        $this->handleRefusal(data_get($data, 'output.{last}.content.0', []));

        $functionCalls = array_filter(
            data_get($data, 'output', []),
            fn (array $output): bool => $output['type'] === 'function_call'
        );

        $reasonings = $request->providerTools() !== []
            ? null
            : array_filter(
                data_get($data, 'output', []),
                fn (array $output): bool => $output['type'] === 'reasoning'
            );

        $responseMessage = new AssistantMessage(
            data_get($data, 'output.{last}.content.0.text') ?? '',
            ToolCallMap::map($functionCalls, $reasonings),
        );

        $request->addMessage($responseMessage);

        return match ($this->mapFinishReason($data)) {
            \Prism\Prism\Enums\FinishReason::ToolCalls => $this->handleToolCalls($data, $request, $response),
            \Prism\Prism\Enums\FinishReason::Stop => $this->finalize($data, $request, $response),
            \Prism\Prism\Enums\FinishReason::Length => throw new PrismException('OpenAI: max tokens excceded'),
            default => throw new PrismException('OpenAI: unknown finish reason'),
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function addStep(array $data, Request $request, ClientResponse $clientResponse, array $toolResults = [], array $structured = []): void
    {
        $functionCalls = array_filter(
            data_get($data, 'output', []),
            fn (array $output): bool => $output['type'] === 'function_call'
        );

        $reasonings = $request->providerTools() !== []
            ? null
            : array_filter(
                data_get($data, 'output', []),
                fn (array $output): bool => $output['type'] === 'reasoning'
            );

        $this->responseBuilder->addStep(new Step(
            text: data_get($data, 'output.{last}.content.0.text') ?? '',
            finishReason: $this->mapFinishReason($data),
            usage: new Usage(
                promptTokens: data_get($data, 'usage.input_tokens', 0) - data_get($data, 'usage.input_tokens_details.cached_tokens', 0),
                completionTokens: data_get($data, 'usage.output_tokens'),
                cacheReadInputTokens: data_get($data, 'usage.input_tokens_details.cached_tokens'),
                thoughtTokens: data_get($data, 'usage.output_token_details.reasoning_tokens'),
            ),
            meta: new Meta(
                id: data_get($data, 'id'),
                model: data_get($data, 'model'),
                rateLimits: $this->processRateLimits($clientResponse),
            ),
            messages: $request->messages(),
            additionalContent: [],
            systemPrompts: $request->systemPrompts(),
            structured: $structured,
            toolCalls: ToolCallMap::map($functionCalls, $reasonings),
            toolResults: $toolResults,
        ));
    }

    /**
     * @param  array{type: 'json_schema', name: string, schema: array<mixed>, strict?: bool}|array{type: 'json_object'}  $responseFormat
     */
    protected function sendRequest(Request $request, array $responseFormat): ClientResponse
    {
        return $this->client->post(
            'responses',
            array_merge([
                'model' => $request->model(),
                'input' => (new MessageMap($request->messages(), $request->systemPrompts()))(),
                'max_output_tokens' => $request->maxTokens(),
            ], Arr::whereNotNull([
                'temperature' => $request->temperature(),
                'top_p' => $request->topP(),
                'metadata' => $request->providerOptions('metadata'),
                'previous_response_id' => $request->providerOptions('previous_response_id'),
                'truncation' => $request->providerOptions('truncation'),
                'reasoning' => $request->providerOptions('reasoning'),
                'tools' => $this->buildToolsArray($request),
                'tool_choice' => ToolChoiceMap::map($request->toolChoice()),
                'text' => [
                    'format' => $responseFormat,
                ],
            ]))
        );
    }

    protected function handleAutoMode(Request $request): ClientResponse
    {
        $mode = StructuredModeResolver::forModel($request->model());

        return match ($mode) {
            StructuredMode::Structured => $this->handleStructuredMode($request),
            StructuredMode::Json => $this->handleJsonMode($request),
            default => throw new PrismException('Could not determine structured mode for your request'),
        };
    }

    protected function handleStructuredMode(Request $request): ClientResponse
    {
        $mode = StructuredModeResolver::forModel($request->model());

        if ($mode !== StructuredMode::Structured) {
            throw new PrismException(sprintf('%s model does not support structured mode', $request->model()));
        }

        /** @var array{type: 'json_schema', name: string, schema: array<mixed>, strict?: bool} $responseFormat */
        $responseFormat = Arr::whereNotNull([
            'type' => 'json_schema',
            'name' => $request->schema()->name(),
            'schema' => $request->schema()->toArray(),
            'strict' => is_null($request->providerOptions('schema.strict'))
                ? null
                : $request->providerOptions('schema.strict'),
        ]);

        return $this->sendRequest($request, $responseFormat);
    }

    protected function handleJsonMode(Request $request): ClientResponse
    {
        $request = $this->appendMessageForJsonMode($request);

        return $this->sendRequest($request, [
            'type' => 'json_object',
        ]);
    }

    /**
     * @param  array<string, string>  $message
     */
    protected function handleRefusal(array $message): void
    {
        if (data_get($message, 'type') === 'refusal') {
            throw new PrismException(sprintf('OpenAI Refusal: %s', $message['refusal'] ?? 'Reason unknown.'));
        }
    }

    protected function appendMessageForJsonMode(Request $request): Request
    {
        return $request->addMessage(new SystemMessage(sprintf(
            "Respond with JSON that matches the following schema: \n %s",
            json_encode($request->schema()->toArray(), JSON_PRETTY_PRINT)
        )));
    }

    /**
     * @return array<int|string, mixed>|null
     */
    protected function buildToolsArray(Request $request): ?array
    {
        $tools = ToolMap::map($request->tools());

        if ($request->providerTools() === []) {
            return $tools;
        }

        $providerTools = array_map(
            fn (ProviderTool $tool): array => [
                'type' => $tool->type,
                ...$tool->options,
            ],
            $request->providerTools()
        );

        return array_merge($providerTools, $tools);
    }

    protected function handleToolCalls(array $data, Request $request, ClientResponse $clientResponse): StructuredResponse
    {
        $toolResults = $this->callTools(
            $request->tools(),
            ToolCallMap::map(array_filter(
                data_get($data, 'output', []),
                fn (array $output): bool => $output['type'] === 'function_call')
            ),
        );

        $request->addMessage(new ToolResultMessage($toolResults));

        $this->addStep($data, $request, $clientResponse, $toolResults);

        if ($this->responseBuilder->steps->count() < $request->maxSteps()) {
            return $this->handle($request);
        }

        return $this->responseBuilder->toResponse();
    }

    protected function finalize(array $data, Request $request, ClientResponse $clientResponse): StructuredResponse
    {
        // If structured output is included directly in parsed content, we can try decoding,
        // but ResponseBuilder already handles JSON decoding when stop.
        $this->addStep($data, $request, $clientResponse);

        return $this->responseBuilder->toResponse();
    }
}
