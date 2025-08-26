<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\OpenAI\Handlers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Event;
use Prism\Prism\Audio\AudioResponse;
use Prism\Prism\Audio\SpeechToTextRequest;
use Prism\Prism\Audio\TextResponse;
use Prism\Prism\Audio\TextToSpeechRequest;
use Prism\Prism\Providers\Anthropic\Concerns\ProcessesRateLimits as ConcernsProcessesRateLimits;
use Prism\Prism\Providers\OpenAI\Concerns\ValidatesResponse;
use Prism\Prism\Providers\OpenAI\Events\OpenAIRequestSent;
use Prism\Prism\Providers\OpenAI\Events\OpenAIResponseReceived;
use Prism\Prism\Providers\OpenAI\Maps\TextToSpeechRequestMapper;
use Prism\Prism\ValueObjects\GeneratedAudio;
use Prism\Prism\ValueObjects\Usage;

class Audio
{
    use ConcernsProcessesRateLimits;
    use ValidatesResponse;

    public function __construct(protected PendingRequest $client) {}

    public function handleTextToSpeech(TextToSpeechRequest $request): AudioResponse
    {
        $mapper = new TextToSpeechRequestMapper($request);

        Event::dispatch(new OpenAIRequestSent($request, 'audio_text_to_speech'));

        $response = $this->client->post('audio/speech', $mapper->toPayload());

        Event::dispatch(new OpenAIResponseReceived($response, 'audio_text_to_speech'));

        if (! $response->successful()) {
            throw new \Exception('Failed to generate audio: '.$response->body());
        }

        $audioContent = $response->body();
        $base64Audio = base64_encode($audioContent);

        return new AudioResponse(
            audio: new GeneratedAudio(
                base64: $base64Audio,
            ),
        );
    }

    public function handleSpeechToText(SpeechToTextRequest $request): TextResponse
    {
        Event::dispatch(new OpenAIRequestSent($request, 'audio_speech_to_text'));

        $response = $this
            ->client
            ->attach(
                'file',
                $request->input()->resource(),
                'audio',
                ['Content-Type' => $request->input()->mimeType()]
            )
            ->post('audio/transcriptions', Arr::whereNotNull([
                'model' => $request->model(),
                'language' => $request->providerOptions('language') ?? null,
                'prompt' => $request->providerOptions('prompt') ?? null,
                'response_format' => $request->providerOptions('response_format') ?? null,
                'temperature' => $request->providerOptions('temperature') ?? null,
            ]));

        Event::dispatch(new OpenAIResponseReceived($response, 'audio_speech_to_text'));

        if (json_validate($response->body())) {
            $data = $response->json();

            $this->validateResponse($response);

            return new TextResponse(
                text: $data['text'] ?? '',
                usage: isset($data['usage'])
                    ? new Usage(
                        promptTokens: $data['usage']['input_tokens'] ?? 0,
                        completionTokens: $data['usage']['total_tokens'] ?? 0,
                    )
                    : null,
                additionalContent: $data,
            );
        }

        // Handle other response formats like vtt
        return new TextResponse(
            text: $response->body(),
        );
    }
}
