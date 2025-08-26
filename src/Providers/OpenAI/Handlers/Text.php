<?php

declare(strict_types=1);

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
use Prism\Prism\Text\Request;
use Prism\Prism\Text\Response;
use Prism\Prism\Text\ResponseBuilder;
use Prism\Prism\Text\Step;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\ToolResult;
use Prism\Prism\ValueObjects\Usage;

class Text
{
    use BuildsTools;
    use CallsTools;
    use MapsFinishReason;
    use ProcessRateLimits;
    use ValidatesResponse;

    protected ResponseBuilder $responseBuilder;

    protected array $toolCallTypes = ['function_call', 'web_search_call'];

    protected ?string $conversationId = null;

    protected int $lastSentMessageCount = 0;

    public function __construct(protected PendingRequest $client)
    {
        $this->responseBuilder = new ResponseBuilder;
    }

    public function handle(Request $request): Response
    {
        // Create conversation if not provided
        if ($request->conversationId() === null && $request->maxSteps() > 1) {
            $this->conversationId = $this->createConversation($request);
        } else {
            $this->conversationId = $request->conversationId();
        }

        Event::dispatch(new OpenAIRequestSent($request, 'text'));

        $response = $this->sendRequest($request);

        Event::dispatch(new OpenAIResponseReceived($response, 'text'));

        $this->validateResponse($response);

        $data = $response->json();

        $responseMessage = new AssistantMessage(
            data_get($data, 'output.{last}.content.0.text') ?? '',
            ToolCallMap::map(data_get($data, 'output', []))
        );

        $request->addMessage($responseMessage);

        return match ($this->mapFinishReason($data)) {
            FinishReason::ToolCalls => $this->handleToolCalls($data, $request, $response),
            FinishReason::Stop => $this->handleStop($data, $request, $response),
            FinishReason::Length => throw new PrismException('OpenAI: max tokens excceded'),
            default => throw new PrismException('OpenAI: unknown finish reason'),
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleToolCalls(array $data, Request $request, ClientResponse $clientResponse): Response
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
    protected function handleStop(array $data, Request $request, ClientResponse $clientResponse): Response
    {
        $this->addStep($data, $request, $clientResponse);

        return $this->responseBuilder->toResponse();
    }

    protected function shouldContinue(Request $request): bool
    {
        return $this->responseBuilder->steps->count() < $request->maxSteps();
    }

    protected function sendRequest(Request $request): ClientResponse
    {
        $payload = array_merge([
            'model' => $request->model(),
            'max_output_tokens' => $request->maxTokens(),
        ], Arr::whereNotNull([
            'temperature' => $request->temperature(),
            'top_p' => $request->topP(),
            'metadata' => $request->providerOptions('metadata'),
            'tools' => $this->buildTools($request),
            'tool_choice' => ToolChoiceMap::map($request->toolChoice()),
            'truncation' => $request->providerOptions('truncation'),
            'reasoning' => $request->providerOptions('reasoning'),
            'store' => $request->storeResponse(),
        ]));

        // If we have a conversation ID, use it instead of full message history
        if ($this->conversationId !== null) {
            $payload['conversation'] = $this->conversationId;
            // Only send the most recent messages that haven't been sent to the conversation yet
            $payload['input'] = $this->getUnsentMessages($request);
        } else {
            // Send full message history for single-turn conversations
            $payload['input'] = (new MessageMap($request->messages(), $request->systemPrompts()))();
        }

        return $this->client->post('responses', $payload);
    }

    /**
     * Create a new conversation and return the conversation ID
     */
    protected function createConversation(Request $request): string
    {
        $metadata = $request->providerOptions('metadata');

        $response = $this->client->post('conversations', $metadata ? ['metadata' => $metadata] : []);

        return data_get($response->json(), 'id');
    }

    /**
     * Get only the messages that haven't been sent to the conversation yet.
     * For multi-turn conversations, this will typically be just the latest user message
     * and any tool results from the current turn.
     */
    protected function getUnsentMessages(Request $request): array
    {
        $messages = $request->messages();
        $systemPrompts = $request->systemPrompts();

        // For the initial request, we need to include system prompts
        if ($this->lastSentMessageCount === 0) {
            // Track how many messages we're sending
            $this->lastSentMessageCount = count($messages);

            return (new MessageMap($messages, $systemPrompts))();
        }

        // For subsequent requests, only send new messages
        // This handles multi-step responses correctly by tracking the count
        // of messages we last sent to the API
        $newMessages = array_slice($messages, $this->lastSentMessageCount);

        // Update the count for next time
        $this->lastSentMessageCount = count($messages);

        return (new MessageMap($newMessages, []))();
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  ToolResult[]  $toolResults
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
                conversationId: $this->conversationId,
            ),
            messages: $request->messages(),
            additionalContent: [],
            systemPrompts: $request->systemPrompts(),
        ));
    }
}
