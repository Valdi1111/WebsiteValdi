<?php

namespace App\CoreBundle\Service\Chat;

use App\CoreBundle\Attribute\AsChatAction;
use App\CoreBundle\Exception\InvalidChatActionRefException;
use App\CoreBundle\Model\Chat\ChatActionHandlerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Contracts\Service\ServiceProviderInterface;

/**
 * The handlers of the chat buttons (#[AsChatAction]) by name, the first part of an encoded ChatActionRef.
 */
class ChatActionRegistry
{
    /**
     * @param ServiceProviderInterface<ChatActionHandlerInterface> $handlers
     */
    public function __construct(
        #[AutowireLocator(AsChatAction::TAG, indexAttribute: 'name')]
        private readonly ServiceProviderInterface $handlers,
    ) {
    }

    public function has(string $name): bool
    {
        return $this->handlers->has($name);
    }

    /**
     * @throws InvalidChatActionRefException when the service is not a chat action handler
     */
    public function get(string $name): ChatActionHandlerInterface
    {
        $handler = $this->handlers->get($name);
        if (!$handler instanceof ChatActionHandlerInterface) {
            throw new InvalidChatActionRefException(sprintf('Chat action "%s" (%s) does not implement %s.', $name, get_debug_type($handler), ChatActionHandlerInterface::class));
        }

        return $handler;
    }

    /**
     * @return list<string> sorted
     */
    public function getNames(): array
    {
        $names = array_map(strval(...), array_keys($this->handlers->getProvidedServices()));
        sort($names);

        return $names;
    }
}
