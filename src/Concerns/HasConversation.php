<?php

declare(strict_types=1);

namespace Prism\Prism\Concerns;

trait HasConversation
{
    protected ?string $conversationId = null;

    protected bool $storeResponse = true;

    /**
     * Set the conversation ID for maintaining conversation state.
     * This allows the provider to maintain context across multiple requests.
     */
    public function withConversation(?string $conversationId): self
    {
        $this->conversationId = $conversationId;

        return $this;
    }

    /**
     * Get the conversation ID.
     */
    public function conversationId(): ?string
    {
        return $this->conversationId;
    }

    /**
     * Set whether to store the response for future reference.
     * Defaults to true when using conversations.
     */
    public function withStore(bool $store): self
    {
        $this->storeResponse = $store;

        return $this;
    }

    /**
     * Get whether to store the response.
     */
    public function shouldStore(): bool
    {
        // When using conversations, we should store by default
        return $this->conversationId !== null ? true : $this->storeResponse;
    }
}
