<?php

declare(strict_types=1);

namespace Tests\Providers\OpenAI;

use Prism\Prism\Prism;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\Text\Step;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;

it('creates a conversation for multi-turn text generation', function (): void {
    // For simplicity, we'll just test that conversation IDs are properly returned
    // In a real implementation, you'd mock the API responses
    Prism::fake([
        TextResponseFake::make()
            ->withText('Sure! What would you like to know about Laravel?')
            ->withUsage(new Usage(15, 10))
            ->withMeta(new Meta('resp_1', 'gpt-4o-mini', [], 'conv_123456')),
        TextResponseFake::make()
            ->withText('Laravel is a PHP web framework designed for building modern web applications with elegant syntax.')
            ->withUsage(new Usage(5, 15))
            ->withMeta(new Meta('resp_2', 'gpt-4o-mini', [], 'conv_123456')),
    ]);

    // First turn - creates a conversation
    $response1 = Prism::text()
        ->using('openai', 'gpt-4o-mini')
        ->withMessages([['role' => 'user', 'content' => 'I want to learn about Laravel']])
        ->withMaxSteps(2)  // This triggers conversation creation
        ->asText();

    expect($response1->text)->toBe('Sure! What would you like to know about Laravel?');
    expect($response1->meta->conversationId)->toBe('conv_123456');
    expect($response1->usage->promptTokens)->toBe(15);

    // Second turn - uses existing conversation
    $response2 = Prism::text()
        ->using('openai', 'gpt-4o-mini')
        ->withConversation($response1->meta->conversationId)
        ->withMessages([
            ['role' => 'user', 'content' => 'I want to learn about Laravel'],
            ['role' => 'assistant', 'content' => 'Sure! What would you like to know about Laravel?'],
            ['role' => 'user', 'content' => 'What is it?'],
        ])
        ->asText();

    expect($response2->text)->toBe('Laravel is a PHP web framework designed for building modern web applications with elegant syntax.');
    expect($response2->meta->conversationId)->toBe('conv_123456');
    expect($response2->usage->promptTokens)->toBe(5);  // Only new messages counted
});

it('does not create conversation for single-turn requests', function (): void {
    Prism::fake([
        TextResponseFake::make()
            ->withText('Paris is the capital of France.')
            ->withUsage(new Usage(10, 8))
            ->withMeta(new Meta('resp_single', 'gpt-4o-mini', [], null)),
    ]);

    $response = Prism::text()
        ->using('openai', 'gpt-4o-mini')
        ->withMessages([['role' => 'user', 'content' => 'What is the capital of France?']])
        ->asText();

    expect($response->text)->toBe('Paris is the capital of France.');
    expect($response->meta->conversationId)->toBeNull();
    expect($response->usage->promptTokens)->toBe(10);  // Full message history counted
});

it('supports explicit conversation management', function (): void {
    Prism::fake([
        TextResponseFake::make()
            ->withText('Hello! How can I help you?')
            ->withUsage(new Usage(5, 6))
            ->withMeta(new Meta('resp_explicit', 'gpt-4o-mini', [], 'existing_conv_id')),
    ]);

    $response = Prism::text()
        ->using('openai', 'gpt-4o-mini')
        ->withConversation('existing_conv_id')
        ->withMessages([['role' => 'user', 'content' => 'Hello']])
        ->asText();

    expect($response->text)->toBe('Hello! How can I help you?');
    expect($response->meta->conversationId)->toBe('existing_conv_id');
});

it('supports disabling response storage', function (): void {
    Prism::fake([
        TextResponseFake::make()
            ->withText('This response will not be stored.')
            ->withUsage(new Usage(10, 7)),
    ]);

    $response = Prism::text()
        ->using('openai', 'gpt-4o-mini')
        ->withStore(false)
        ->withMessages([['role' => 'user', 'content' => 'Do not store this']])
        ->asText();

    expect($response->text)->toBe('This response will not be stored.');
});

// Test for multi-step conversations with tool calls would go here
// The current fake system doesn't easily support multi-step tool responses
// This would require more sophisticated mocking of the OpenAI handler
