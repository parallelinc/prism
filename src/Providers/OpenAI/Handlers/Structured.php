<?php

namespace Prism\Prism\Providers\OpenAI\Handlers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Event;
use Prism\Prism\Concerns\CallsTools;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Providers\OpenAI\Concerns\BuildsTools;
use Prism\Prism\Providers\OpenAI\Concerns\MapsFinishReason;
use Prism\Prism\Providers\OpenAI\Concerns\ProcessRateLimits;
use Prism\Prism\Providers\OpenAI\Concerns\ValidatesResponse;
use Prism\Prism\Providers\OpenAI\Events\OpenAIRequestSent;
use Prism\Prism\Providers\OpenAI\Events\OpenAIResponseReceived;
use Prism\Prism\Providers\OpenAI\Maps\MessageMap;
use Prism\Prism\Providers\OpenAI\Maps\ToolCallMap;
use Prism\Prism\Providers\OpenAI\Maps\ToolChoiceMap;
use Prism\Prism\Structured\Request;
use Prism\Prism\Structured\Response as StructuredResponse;
use Prism\Prism\Structured\ResponseBuilder;
use Prism\Prism\Structured\Step;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;

class Structured
{
    use BuildsTools;
    use CallsTools;
    use MapsFinishReason;
    use ProcessRateLimits;
    use ValidatesResponse;

    protected ResponseBuilder $responseBuilder;

    protected array $toolCallTypes = ['function_call', 'web_search_call'];

    public function __construct(protected PendingRequest $client)
    {
        $this->responseBuilder = new ResponseBuilder;
    }

    public function handle(Request $request): StructuredResponse
    {
        $response = $this->sendRequest($request);

        Event::dispatch(new OpenAIResponseReceived($response, 'structured'));

        $this->validateResponse($response);

        $data = $response->json();

        $this->handleRefusal(data_get($data, 'output.{last}.content.0', []));

        $responseMessage = new AssistantMessage(
            data_get($data, 'output.{last}.content.0.text') ?? '',
            ToolCallMap::map(data_get($data, 'output', []))
        );

        $request->addMessage($responseMessage);

        return match ($this->mapFinishReason($data)) {
            FinishReason::ToolCalls => $this->handleToolCalls($data, $request, $response),
            FinishReason::Stop => $this->handleStop($data, $request, $response),
            FinishReason::Length => throw new PrismException('OpenAI: max tokens exceeded'),
            default => throw new PrismException('OpenAI: unknown finish reason'),
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
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

        if ($this->shouldContinue($request)) {
            return $this->handle($request);
        }

        return $this->responseBuilder->toResponse();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleStop(array $data, Request $request, ClientResponse $clientResponse): StructuredResponse
    {
        $this->addStep($data, $request, $clientResponse);

        return $this->responseBuilder->toResponse();
    }

    protected function shouldContinue(Request $request): bool
    {
        return $this->responseBuilder->steps->count() < $request->maxSteps();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function addStep(
        array $data,
        Request $request,
        ClientResponse $clientResponse,
        array $toolResults = []
    ): void {
        $toolCalls = ToolCallMap::map(array_filter(
            data_get($data, 'output', []),
            fn (array $output): bool => in_array($output['type'], $this->toolCallTypes))
        );

        $this->responseBuilder->addStep(new Step(
            text: data_get($data, 'output.{last}.content.0.text') ?? '',
            finishReason: $this->mapFinishReason($data),
            toolCalls: $toolCalls,
            toolResults: $toolResults,
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
        ));
    }

    protected function sendRequest(Request $request): ClientResponse
    {
        $responseFormat = Arr::whereNotNull([
            'type' => 'json_schema',
            'name' => $request->schema()->name(),
            'schema' => $request->schema()->toArray(),
            'strict' => is_null($request->providerOptions('schema.strict'))
                ? null
                : $request->providerOptions('schema.strict'),
        ]);

        $payload = array_merge([
            'model' => $request->model(),
            'input' => (new MessageMap($request->messages(), $request->systemPrompts()))(),
            'max_output_tokens' => $request->maxTokens(),
        ], Arr::whereNotNull([
            'temperature' => $request->temperature(),
            'top_p' => $request->topP(),
            'metadata' => $request->providerOptions('metadata'),
            'parallel_tool_calls' => $request->providerOptions('parallel_tool_calls'),
            'max_tool_calls' => $request->providerOptions('max_tool_calls'),
            'prompt_cache_key' => $request->providerOptions('prompt_cache_key'),
            'previous_response_id' => $request->providerOptions('previous_response_id'),
            'truncation' => $request->providerOptions('truncation'),
            'reasoning' => $request->providerOptions('reasoning'),
            'tools' => $this->buildTools($request),
            'tool_choice' => ToolChoiceMap::map($request->toolChoice()),
            'text' => [
                'format' => $responseFormat,
            ],
        ]));

        Event::dispatch(new OpenAIRequestSent($request, 'structured', $payload));

        return $this->client->post(
            'responses',
            $payload
        );
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
}
