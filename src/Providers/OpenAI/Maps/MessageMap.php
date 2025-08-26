<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\OpenAI\Maps;

use Exception;
use Prism\Prism\Contracts\Message;
use Prism\Prism\ValueObjects\Media\Document;
use Prism\Prism\ValueObjects\Media\Image;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;

class MessageMap
{
    /** @var array<int, mixed> */
    protected array $mappedMessages = [];

    /**
     * @param  array<int, Message>  $messages
     * @param  SystemMessage[]  $systemPrompts
     */
    public function __construct(
        protected array $messages,
        protected array $systemPrompts,
        protected bool $usingConversation = false
    ) {
        $this->messages = array_merge(
            $this->systemPrompts,
            $this->messages
        );
    }

    /**
     * @return array<int, mixed>
     */
    public function __invoke(): array
    {
        array_map(
            fn (Message $message) => $this->mapMessage($message),
            $this->messages
        );

        return $this->mappedMessages;
    }

    protected function mapMessage(Message $message): void
    {
        if (! $this->shouldSend($message)) {
            return;
        }
        match ($message::class) {
            UserMessage::class => $this->mapUserMessage($message),
            AssistantMessage::class => $this->mapAssistantMessage($message),
            ToolResultMessage::class => $this->mapToolResultMessage($message),
            SystemMessage::class => $this->mapSystemMessage($message),
            default => throw new Exception('Could not map message type '.$message::class),
        };
    }

    protected function shouldSend(Message $message): bool
    {
        if (! $this->usingConversation) {
            return true;
        }

        // All Prism message value objects include a public `$send` flag; default true
        // but we guard in case of custom messages implementing the interface.
        return property_exists($message, 'send') ? (bool) $message->send : true;
    }

    protected function mapSystemMessage(SystemMessage $message): void
    {
        $this->mappedMessages[] = [
            'role' => 'system',
            'content' => $message->content,
        ];

        if ($this->usingConversation) {
            // System prompts are persistent once in a conversation; avoid resending
            $message->send = false;
        }
    }

    protected function mapToolResultMessage(ToolResultMessage $message): void
    {
        foreach ($message->toolResults as $toolResult) {
            $this->mappedMessages[] = [
                'type' => 'function_call_output',
                'call_id' => $toolResult->toolCallResultId,
                'output' => $toolResult->result,
            ];
        }

        if ($this->usingConversation) {
            // Tool outputs are ephemeral per turn; avoid resending after mapping
            $message->send = false;
        }
    }

    protected function mapUserMessage(UserMessage $message): void
    {
        $this->mappedMessages[] = [
            'role' => 'user',
            'content' => [
                ['type' => 'input_text', 'text' => $message->text()],
                ...self::mapImageParts($message->images()),
                ...self::mapDocumentParts($message->documents()),
            ],
            ...$message->additionalAttributes,
        ];

        if ($this->usingConversation) {
            // After mapping, ensure we don't resend the same user message in a conversation
            $message->send = false;
        }
    }

    /**
     * @param  Image[]  $images
     * @return array<int, mixed>
     */
    protected static function mapImageParts(array $images): array
    {
        return array_map(fn (Image $image): array => (new ImageMapper($image))->toPayload(), $images);
    }

    /**
     * @param  Document[]  $documents
     * @return array<int,mixed>
     */
    protected static function mapDocumentParts(array $documents): array
    {
        return array_map(fn (Document $document): array => (new DocumentMapper($document))->toPayload(), $documents);
    }

    protected function mapAssistantMessage(AssistantMessage $message): void
    {
        if ($message->content !== '' && $message->content !== '0') {
            $this->mappedMessages[] = [
                'role' => 'assistant',
                'content' => $message->content,
            ];
        }

        foreach ($message->toolCalls as $toolCall) {
            if (! is_null($toolCall->reasoningId)) {
                $this->mappedMessages[] = [
                    'type' => 'reasoning',
                    'id' => $toolCall->reasoningId,
                    'summary' => $toolCall->reasoningSummary,
                ];
            } elseif (! is_null($toolCall->webSearchId)) {
                $this->mappedMessages[] = [
                    'id' => $toolCall->id,
                    'status' => $toolCall->status,
                    'type' => 'web_search_call',
                    'action' => $toolCall->arguments(),
                ];
            } elseif (! is_null($toolCall->name)) {
                $this->mappedMessages[] = [
                    'id' => $toolCall->id,
                    'call_id' => $toolCall->resultId,
                    'type' => 'function_call',
                    'name' => $toolCall->name,
                    'arguments' => json_encode($toolCall->arguments()),
                ];
            }
        }

        if ($this->usingConversation) {
            // After mapping, do not resend prior assistant content in a conversation
            $message->send = false;
        }
    }
}
