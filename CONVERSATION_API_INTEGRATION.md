# OpenAI Conversation API Integration

This document describes the implementation of OpenAI's Conversation API in Prism, which significantly reduces token usage for multi-turn conversations.

## What Changed

### 1. New Trait: `HasConversation`

- Adds `withConversation(?string $conversationId)` method to set conversation ID
- Adds `withStore(bool $store)` method to control response storage
- Automatically enables storage when using conversations

### 2. Updated Request Classes

Both `Text\Request` and `Structured\Request` now accept:

- `conversationId` - For maintaining conversation state
- `storeResponse` - To control response persistence

### 3. Enhanced OpenAI Handlers

Both Text and Structured handlers now:

- Automatically create conversations when `maxSteps > 1`
- Send only new messages when using conversation IDs
- Track message counts properly across multi-step responses
- Support metadata when creating conversations

### 4. Response Metadata

The `Meta` value object now includes `conversationId` to track conversations in responses.

## How It Works

1. **First Request**: When `maxSteps > 1`, Prism automatically creates a conversation and sends the full message history
2. **Subsequent Requests**: Using the conversation ID, only new messages are sent
3. **Message Tracking**: Prism tracks which messages have been sent to avoid duplicates
4. **Multi-Step Handling**: Correctly handles responses with multiple steps (e.g., tool calls)

## Usage Examples

### Basic Multi-Turn Conversation

```php
// First turn - automatically creates conversation
$response1 = Prism::text()
    ->using('openai', 'gpt-4o-mini')
    ->withMaxSteps(2)  // Triggers conversation creation
    ->withMessages([['role' => 'user', 'content' => 'Hello']])
    ->asText();

// Get the conversation ID
$conversationId = $response1->meta->conversationId;

// Second turn - uses existing conversation
$response2 = Prism::text()
    ->using('openai', 'gpt-4o-mini')
    ->withConversation($conversationId)
    ->withMessages([
        // Include full history locally
        ['role' => 'user', 'content' => 'Hello'],
        ['role' => 'assistant', 'content' => $response1->text],
        ['role' => 'user', 'content' => 'How are you?']
    ])
    ->asText();
```

### With Custom Metadata

```php
$response = Prism::text()
    ->using('openai', 'gpt-4o-mini')
    ->withMaxSteps(2)
    ->withProviderOptions(['metadata' => ['user_id' => '123']])
    ->withMessages($messages)
    ->asText();
```

### Disable Response Storage

```php
$response = Prism::text()
    ->using('openai', 'gpt-4o-mini')
    ->withStore(false)  // Response won't be stored for 30 days
    ->withMessages($messages)
    ->asText();
```

## Token Savings

With conversations, only new messages count toward input tokens:

- First turn: All messages are sent (e.g., 15 tokens)
- Second turn: Only new messages are sent (e.g., 5 tokens instead of 20)
- Third turn: Again, only new messages (e.g., 8 tokens instead of 33)

This results in significant cost savings for longer conversations.

## Important Notes

- Conversation objects persist beyond the 30-day response retention
- You still need to maintain the full message history locally for context
- The API still bills for the full conversation context, but not as input tokens
- Single-turn requests (default) don't create conversations
