<?php

namespace Longman\TelegramBot\Entities\MessageOrigin;

use Longman\TelegramBot\Entities\Chat;
use Longman\TelegramBot\Entities\Entity;

/**
 * The message was originally sent on behalf of a chat to a group chat.
 *
 * @link https://core.telegram.org/bots/api#messageoriginchat
 *
 * @method string getType()            Type of the message origin, always “chat”
 * @method int    getDate()            Date the message was sent originally in Unix time
 * @method Chat   getSenderChat()            Chat that sent the message originally
 * @method string getAuthorSignature() Optional. For messages originally sent by an anonymous chat administrator, original message author signature
 */
class MessageOriginChat extends Entity implements MessageOrigin
{
    protected function subEntities(): array
    {
        return [
            'sender_chat' => Chat::class,
        ];
    }
}
