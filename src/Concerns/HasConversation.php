<?php

declare(strict_types=1);

namespace Prism\Prism\Concerns;

trait HasConversation
{
    protected ?string $conversationId = null;

    public function useConversation(?string $conversation = null): self
    {
        if (is_null($conversation) && $this->provider) {
            $conversation = $this->provider->createConversation();
        }

        $this->conversationId = $conversation;

        return $this;
    }
}
