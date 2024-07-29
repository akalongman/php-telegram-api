<?php

/**
 * This file is part of the TelegramBot package.
 *
 * (c) Avtandil Kikabidze aka LONGMAN <akalongman@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 * Written by Marco Boretto <marco.bore@gmail.com>
 */

namespace Longman\TelegramBot;

use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Entities\Chat;
use Longman\TelegramBot\Entities\ChatBoostRemoved;
use Longman\TelegramBot\Entities\ChatBoostUpdated;
use Longman\TelegramBot\Entities\ChatJoinRequest;
use Longman\TelegramBot\Entities\ChatMemberUpdated;
use Longman\TelegramBot\Entities\ChosenInlineResult;
use Longman\TelegramBot\Entities\InlineQuery;
use Longman\TelegramBot\Entities\Message;
use Longman\TelegramBot\Entities\MessageOrigin\MessageOriginChannel;
use Longman\TelegramBot\Entities\MessageOrigin\MessageOriginChat;
use Longman\TelegramBot\Entities\MessageOrigin\MessageOriginHiddenUser;
use Longman\TelegramBot\Entities\MessageOrigin\MessageOriginUser;
use Longman\TelegramBot\Entities\MessageReactionCountUpdated;
use Longman\TelegramBot\Entities\MessageReactionUpdated;
use Longman\TelegramBot\Entities\Payments\PreCheckoutQuery;
use Longman\TelegramBot\Entities\Payments\ShippingQuery;
use Longman\TelegramBot\Entities\Poll;
use Longman\TelegramBot\Entities\PollAnswer;
use Longman\TelegramBot\Entities\Update;
use Longman\TelegramBot\Entities\User;
use Longman\TelegramBot\Exception\TelegramException;
use PDO;
use PDOException;

class DB
{
    /**
     * MySQL credentials
     *
     * @var array
     */
    protected static $mysql_credentials = [];

    /**
     * PDO object
     *
     * @var PDO
     */
    protected static $pdo;

    /**
     * Table prefix
     *
     * @var string
     */
    protected static $table_prefix;

    /**
     * Telegram class object
     *
     * @var Telegram
     */
    protected static $telegram;

    /**
     * Initialize
     *
     * @param array    $credentials  Database connection details
     * @param Telegram $telegram     Telegram object to connect with this object
     * @param string   $table_prefix Table prefix
     * @param string   $encoding     Database character encoding
     *
     * @return PDO PDO database object
     * @throws TelegramException
     */
    public static function initialize(
        array $credentials,
        Telegram $telegram,
        $table_prefix = '',
        $encoding = 'utf8mb4',
    ): PDO {
        if (empty($credentials)) {
            throw new TelegramException('MySQL credentials not provided!');
        }
        if (isset($credentials['unix_socket'])) {
            $dsn = 'mysql:unix_socket=' . $credentials['unix_socket'];
        } else {
            $dsn = 'mysql:host=' . $credentials['host'];
        }
        $dsn .= ';dbname=' . $credentials['database'];

        if (!empty($credentials['port'])) {
            $dsn .= ';port=' . $credentials['port'];
        }

        $options = [PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES ' . $encoding];
        try {
            $pdo = new PDO($dsn, $credentials['user'], $credentials['password'], $options);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_WARNING);
        } catch (PDOException $e) {
            throw new TelegramException($e->getMessage());
        }

        self::$pdo               = $pdo;
        self::$telegram          = $telegram;
        self::$mysql_credentials = $credentials;
        self::$table_prefix      = $table_prefix;

        self::defineTables();

        return self::$pdo;
    }

    /**
     * External Initialize
     *
     * Let you use the class with an external already existing Pdo Mysql connection.
     *
     * @param PDO      $external_pdo_connection PDO database object
     * @param Telegram $telegram                Telegram object to connect with this object
     * @param string   $table_prefix            Table prefix
     *
     * @return PDO PDO database object
     * @throws TelegramException
     */
    public static function externalInitialize(
        PDO $external_pdo_connection,
        Telegram $telegram,
        string $table_prefix = '',
    ): PDO {
        if ($external_pdo_connection === null) {
            throw new TelegramException('MySQL external connection not provided!');
        }

        self::$pdo               = $external_pdo_connection;
        self::$telegram          = $telegram;
        self::$mysql_credentials = [];
        self::$table_prefix      = $table_prefix;

        self::defineTables();

        return self::$pdo;
    }

    /**
     * Define all the tables with the proper prefix
     */
    protected static function defineTables(): void
    {
        $tables = [
            'callback_query',
            'chat',
            'chat_boost_updated',
            'chat_boost_removed',
            'chat_join_request',
            'chat_member_updated',
            'chosen_inline_result',
            'edited_message',
            'inline_query',
            'message',
            'message_reaction',
            'message_reaction_count',
            'poll',
            'poll_answer',
            'pre_checkout_query',
            'request_limiter',
            'shipping_query',
            'telegram_update',
            'user',
            'user_chat',
        ];
        foreach ($tables as $table) {
            $table_name = 'TB_' . strtoupper($table);
            if (!defined($table_name)) {
                define($table_name, self::$table_prefix . $table);
            }
        }
    }

    /**
     * Check if database connection has been created
     *
     * @return bool
     */
    public static function isDbConnected(): bool
    {
        return self::$pdo !== null;
    }

    /**
     * Get the PDO object of the connected database
     *
     * @return PDO|null
     */
    public static function getPdo(): ?PDO
    {
        return self::$pdo;
    }

    /**
     * Fetch update(s) from DB
     *
     * @param int    $limit Limit the number of updates to fetch
     * @param string $id    Check for unique update id
     *
     * @return array|bool Fetched data or false if not connected
     * @throws TelegramException
     */
    public static function selectTelegramUpdate(int $limit = 0, string $id = '')
    {
        if (!self::isDbConnected()) {
            return false;
        }

        try {
            $sql = '
                SELECT `id`
                FROM `' . TB_TELEGRAM_UPDATE . '`
            ';

            if ($id !== '') {
                $sql .= ' WHERE `id` = :id';
            } else {
                $sql .= ' ORDER BY `id` DESC';
            }

            if ($limit > 0) {
                $sql .= ' LIMIT :limit';
            }

            $sth = self::$pdo->prepare($sql);

            if ($limit > 0) {
                $sth->bindValue(':limit', $limit, PDO::PARAM_INT);
            }
            if ($id !== '') {
                $sth->bindValue(':id', $id);
            }

            $sth->execute();

            return $sth->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            throw new TelegramException($e->getMessage());
        }
    }

    /**
     * Fetch message(s) from DB
     *
     * @param int $limit Limit the number of messages to fetch
     *
     * @return array|bool Fetched data or false if not connected
     * @throws TelegramException
     */
    public static function selectMessages(int $limit = 0)
    {
        if (!self::isDbConnected()) {
            return false;
        }

        try {
            $sql = '
                SELECT *
                FROM `' . TB_MESSAGE . '`
                ORDER BY `id` DESC
            ';

            if ($limit > 0) {
                $sql .= ' LIMIT :limit';
            }

            $sth = self::$pdo->prepare($sql);

            if ($limit > 0) {
                $sth->bindValue(':limit', $limit, PDO::PARAM_INT);
            }

            $sth->execute();

            return $sth->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            throw new TelegramException($e->getMessage());
        }
    }

    /**
     * Convert from unix timestamp to timestamp
     *
     * @param ?int $unixtime Unix timestamp (if empty, current timestamp is used)
     *
     * @return string
     */
    protected static function getTimestamp(?int $unixtime = null): string
    {
        return date('Y-m-d H:i:s', $unixtime ?? time());
    }

    /**
     * Convert array of Entity items to a JSON array
     *
     * @param array $entities
     * @param mixed $default
     *
     * @return mixed
     * @todo Find a better way, as json_* functions are very heavy
     *
     */
    public static function entitiesArrayToJson(array $entities, $default = null)
    {
        if (empty($entities)) {
            return $default;
        }

        // Convert each Entity item into an object based on its JSON reflection
        $json_entities = array_map(function ($entity) {
            return json_decode($entity, true);
        }, $entities);

        return json_encode($json_entities);
    }

    /**
     * Insert entry to telegram_update table
     *
     * @throws TelegramException
     */
    protected static function insertTelegramUpdate(
        int $update_id,
        ?int $chat_id = null,
        ?int $message_id = null,
        ?int $edited_message_id = null,
        ?int $channel_post_id = null,
        ?int $edited_channel_post_id = null,
        ?string $message_reaction_id = null,
        ?string $message_reaction_count_id = null,
        ?string $inline_query_id = null,
        ?string $chosen_inline_result_id = null,
        ?string $callback_query_id = null,
        ?string $shipping_query_id = null,
        ?string $pre_checkout_query_id = null,
        ?string $poll_id = null,
        ?string $poll_answer_poll_id = null,
        ?string $my_chat_member_updated_id = null,
        ?string $chat_member_updated_id = null,
        ?string $chat_join_request_id = null,
        ?string $chat_boost_updated_id = null,
        ?string $chat_boost_removed_id = null,
    ): ?bool {
        if ($message_id === null && $edited_message_id === null && $channel_post_id === null && $edited_channel_post_id === null && $message_reaction_id === null && $message_reaction_count_id === null && $inline_query_id === null && $chosen_inline_result_id === null && $callback_query_id === null && $shipping_query_id === null && $pre_checkout_query_id === null && $poll_id === null && $poll_answer_poll_id === null && $my_chat_member_updated_id === null && $chat_member_updated_id === null && $chat_join_request_id === null && $chat_boost_updated_id === null && $chat_boost_removed_id === null) {
            throw new TelegramException('message_id, edited_message_id, channel_post_id, edited_channel_post_id, message_reaction_id, message_reaction_count_id, inline_query_id, chosen_inline_result_id, callback_query_id, shipping_query_id, pre_checkout_query_id, poll_id, poll_answer_poll_id, my_chat_member_updated_id, chat_member_updated_id, chat_join_request_id, chat_boost_updated_id, chat_boost_removed_id are all null');
        }

        if (!self::isDbConnected()) {
            return false;
        }

        try {
            $sth = self::$pdo->prepare('
                INSERT IGNORE INTO `' . TB_TELEGRAM_UPDATE . '`
                (
                    `id`, `chat_id`, `message_id`, `edited_message_id`,
                    `channel_post_id`, `edited_channel_post_id`, `message_reaction_id`, `message_reaction_count_id`,
                    `inline_query_id`, `chosen_inline_result_id`,
                    `callback_query_id`, `shipping_query_id`, `pre_checkout_query_id`,
                    `poll_id`, `poll_answer_poll_id`, `my_chat_member_updated_id`, `chat_member_updated_id`,
                    `chat_join_request_id`, `chat_boost_updated_id`, `chat_boost_removed_id`
                ) VALUES (
                    :id, :chat_id, :message_id, :edited_message_id,
                    :channel_post_id, :edited_channel_post_id, :message_reaction_id, :message_reaction_count_id,
                    :inline_query_id, :chosen_inline_result_id,
                    :callback_query_id, :shipping_query_id, :pre_checkout_query_id,
                    :poll_id, :poll_answer_poll_id, :my_chat_member_updated_id, :chat_member_updated_id,
                    :chat_join_request_id, :chat_boost_updated_id, :chat_boost_removed_id
                )
            ');

            $sth->bindValue(':id', $update_id);
            $sth->bindValue(':chat_id', $chat_id);
            $sth->bindValue(':message_id', $message_id);
            $sth->bindValue(':edited_message_id', $edited_message_id);
            $sth->bindValue(':channel_post_id', $channel_post_id);
            $sth->bindValue(':edited_channel_post_id', $edited_channel_post_id);
            $sth->bindValue(':message_reaction_id', $message_reaction_id);
            $sth->bindValue(':message_reaction_count_id', $message_reaction_count_id);
            $sth->bindValue(':inline_query_id', $inline_query_id);
            $sth->bindValue(':chosen_inline_result_id', $chosen_inline_result_id);
            $sth->bindValue(':callback_query_id', $callback_query_id);
            $sth->bindValue(':shipping_query_id', $shipping_query_id);
            $sth->bindValue(':pre_checkout_query_id', $pre_checkout_query_id);
            $sth->bindValue(':poll_id', $poll_id);
            $sth->bindValue(':poll_answer_poll_id', $poll_answer_poll_id);
            $sth->bindValue(':my_chat_member_updated_id', $my_chat_member_updated_id);
            $sth->bindValue(':chat_member_updated_id', $chat_member_updated_id);
            $sth->bindValue(':chat_join_request_id', $chat_join_request_id);
            $sth->bindValue(':chat_boost_updated_id', $chat_boost_updated_id);
            $sth->bindValue(':chat_boost_removed_id', $chat_boost_removed_id);

            return $sth->execute();
        } catch (PDOException $e) {
            throw new TelegramException($e->getMessage());
        }
    }

    /**
     * Insert users and save their connection to chats
     *
     * @param User        $user
     * @param string|null $date
     * @param Chat|null   $chat
     *
     * @return bool If the insert was successful
     * @throws TelegramException
     */
    public static function insertUser(User $user, ?string $date = null, ?Chat $chat = null): bool
    {
        if (!self::isDbConnected()) {
            return false;
        }

        try {
            $sth = self::$pdo->prepare('
                INSERT INTO `' . TB_USER . '`
                (`id`, `is_bot`, `username`, `first_name`, `last_name`, `language_code`, `is_premium`, `added_to_attachment_menu`, `created_at`, `updated_at`)
                VALUES
                (:id, :is_bot, :username, :first_name, :last_name, :language_code, :is_premium, :added_to_attachment_menu, :created_at, :updated_at)
                ON DUPLICATE KEY UPDATE
                    `is_bot`                   = VALUES(`is_bot`),
                    `username`                 = VALUES(`username`),
                    `first_name`               = VALUES(`first_name`),
                    `last_name`                = VALUES(`last_name`),
                    `language_code`            = VALUES(`language_code`),
                    `is_premium`               = VALUES(`is_premium`),
                    `added_to_attachment_menu` = VALUES(`added_to_attachment_menu`),
                    `updated_at`               = VALUES(`updated_at`)
            ');

            $sth->bindValue(':id', $user->getId());
            $sth->bindValue(':is_bot', $user->getIsBot(), PDO::PARAM_INT);
            $sth->bindValue(':username', $user->getUsername());
            $sth->bindValue(':first_name', $user->getFirstName());
            $sth->bindValue(':last_name', $user->getLastName());
            $sth->bindValue(':language_code', $user->getLanguageCode());
            $sth->bindValue(':is_premium', $user->getIsPremium(), PDO::PARAM_INT);
            $sth->bindValue(':added_to_attachment_menu', $user->getAddedToAttachmentMenu(), PDO::PARAM_INT);
            $date = $date ?: self::getTimestamp();
            $sth->bindValue(':created_at', $date);
            $sth->bindValue(':updated_at', $date);

            $status = $sth->execute();
        } catch (PDOException $e) {
            throw new TelegramException($e->getMessage());
        }

        // Also insert the relationship to the chat into the user_chat table
        if ($chat) {
            try {
                $sth = self::$pdo->prepare('
                    INSERT IGNORE INTO `' . TB_USER_CHAT . '`
                    (`user_id`, `chat_id`)
                    VALUES
                    (:user_id, :chat_id)
                ');

                $sth->bindValue(':user_id', $user->getId());
                $sth->bindValue(':chat_id', $chat->getId());

                $status = $sth->execute();
            } catch (PDOException $e) {
                throw new TelegramException($e->getMessage());
            }
        }

        return $status;
    }

    /**
     * Insert chat
     *
     * @param Chat        $chat
     * @param string|null $date
     * @param int|null    $migrate_to_chat_id
     *
     * @return bool If the insert was successful
     * @throws TelegramException
     */
    public static function insertChat(Chat $chat, ?string $date = null, ?int $migrate_to_chat_id = null): ?bool
    {
        if (!self::isDbConnected()) {
            return false;
        }

        try {
            $sth = self::$pdo->prepare('
                INSERT IGNORE INTO `' . TB_CHAT . '`
                (`id`, `type`, `title`, `username`, `first_name`, `last_name`, `is_forum`, `created_at` ,`updated_at`, `old_id`)
                VALUES
                (:id, :type, :title, :username, :first_name, :last_name, :is_forum, :created_at, :updated_at, :old_id)
                ON DUPLICATE KEY UPDATE
                    `type`                           = VALUES(`type`),
                    `title`                          = VALUES(`title`),
                    `username`                       = VALUES(`username`),
                    `first_name`                     = VALUES(`first_name`),
                    `last_name`                      = VALUES(`last_name`),
                    `is_forum`                       = VALUES(`is_forum`),
                    `updated_at`                     = VALUES(`updated_at`)
            ');

            $chat_id   = $chat->getId();
            $chat_type = $chat->getType();

            if ($migrate_to_chat_id !== null) {
                $chat_type = 'supergroup';

                $sth->bindValue(':id', $migrate_to_chat_id);
                $sth->bindValue(':old_id', $chat_id);
            } else {
                $sth->bindValue(':id', $chat_id);
                $sth->bindValue(':old_id', $migrate_to_chat_id);
            }

            $sth->bindValue(':type', $chat_type);
            $sth->bindValue(':title', $chat->getTitle());
            $sth->bindValue(':username', $chat->getUsername());
            $sth->bindValue(':first_name', $chat->getFirstName());
            $sth->bindValue(':last_name', $chat->getLastName());
            $sth->bindValue(':is_forum', $chat->getIsForum());
            $date = $date ?: self::getTimestamp();
            $sth->bindValue(':created_at', $date);
            $sth->bindValue(':updated_at', $date);

            return $sth->execute();
        } catch (PDOException $e) {
            throw new TelegramException($e->getMessage());
        }
    }

    /**
     * Insert request into database
     *
     * @param Update $update
     *
     * @return bool
     * @throws TelegramException
     * @todo self::$pdo->lastInsertId() - unsafe usage if expected previous insert fails?
     *
     */
    public static function insertRequest(Update $update): bool
    {
        if (!self::isDbConnected()) {
            return false;
        }

        $chat_id                   = null;
        $message_id                = null;
        $edited_message_id         = null;
        $channel_post_id           = null;
        $edited_channel_post_id    = null;
        $message_reaction_id       = null;
        $message_reaction_count_id = null;
        $inline_query_id           = null;
        $chosen_inline_result_id   = null;
        $callback_query_id         = null;
        $shipping_query_id         = null;
        $pre_checkout_query_id     = null;
        $poll_id                   = null;
        $poll_answer_poll_id       = null;
        $my_chat_member_updated_id = null;
        $chat_member_updated_id    = null;
        $chat_join_request_id      = null;
        $chat_boost_updated_id     = null;
        $chat_boost_removed_id     = null;

        if (($message = $update->getMessage()) && self::insertMessageRequest($message)) {
            $chat_id    = $message->getChat()->getId();
            $message_id = $message->getMessageId();
        } elseif (($edited_message = $update->getEditedMessage()) && self::insertEditedMessageRequest($edited_message)) {
            $chat_id           = $edited_message->getChat()->getId();
            $edited_message_id = (int) self::$pdo->lastInsertId();
        } elseif (($channel_post = $update->getChannelPost()) && self::insertMessageRequest($channel_post)) {
            $chat_id         = $channel_post->getChat()->getId();
            $channel_post_id = $channel_post->getMessageId();
        } elseif (($edited_channel_post = $update->getEditedChannelPost()) && self::insertEditedMessageRequest($edited_channel_post)) {
            $chat_id                = $edited_channel_post->getChat()->getId();
            $edited_channel_post_id = (int) self::$pdo->lastInsertId();
        } elseif (($message_reaction = $update->getMessageReaction()) && self::insertMessageReaction($message_reaction)) {
            $chat_id             = $message_reaction->getChat()->getId();
            $message_id          = $message_reaction->getMessageId();
            $message_reaction_id = self::$pdo->lastInsertId();
        } elseif (($message_reaction_count = $update->getMessageReactionCount()) && self::insertMessageReactionCount($message_reaction_count)) {
            $chat_id                   = $message_reaction_count->getChat()->getId();
            $message_id                = $message_reaction_count->getMessageId();
            $message_reaction_count_id = self::$pdo->lastInsertId();
        } elseif (($inline_query = $update->getInlineQuery()) && self::insertInlineQueryRequest($inline_query)) {
            $inline_query_id = $inline_query->getId();
        } elseif (($chosen_inline_result = $update->getChosenInlineResult()) && self::insertChosenInlineResultRequest($chosen_inline_result)) {
            $chosen_inline_result_id = self::$pdo->lastInsertId();
        } elseif (($callback_query = $update->getCallbackQuery()) && self::insertCallbackQueryRequest($callback_query)) {
            $callback_query_id = $callback_query->getId();
        } elseif (($shipping_query = $update->getShippingQuery()) && self::insertShippingQueryRequest($shipping_query)) {
            $shipping_query_id = $shipping_query->getId();
        } elseif (($pre_checkout_query = $update->getPreCheckoutQuery()) && self::insertPreCheckoutQueryRequest($pre_checkout_query)) {
            $pre_checkout_query_id = $pre_checkout_query->getId();
        } elseif (($poll = $update->getPoll()) && self::insertPollRequest($poll)) {
            $poll_id = $poll->getId();
        } elseif (($poll_answer = $update->getPollAnswer()) && self::insertPollAnswerRequest($poll_answer)) {
            $poll_answer_poll_id = $poll_answer->getPollId();
        } elseif (($my_chat_member = $update->getMyChatMember()) && self::insertChatMemberUpdatedRequest($my_chat_member)) {
            $my_chat_member_updated_id = self::$pdo->lastInsertId();
        } elseif (($chat_member = $update->getChatMember()) && self::insertChatMemberUpdatedRequest($chat_member)) {
            $chat_member_updated_id = self::$pdo->lastInsertId();
        } elseif (($chat_join_request = $update->getChatJoinRequest()) && self::insertChatJoinRequestRequest($chat_join_request)) {
            $chat_join_request_id = self::$pdo->lastInsertId();
        } elseif (($chat_boost_updated = $update->getChatBoost()) && self::insertChatBoostUpdatedRequest($chat_boost_updated)) {
            $chat_boost_updated_id = self::$pdo->lastInsertId();
        } elseif (($chat_boost_removed = $update->getRemovedChatBoost()) && self::insertChatBoostRemovedRequest($chat_boost_removed)) {
            $chat_boost_removed_id = self::$pdo->lastInsertId();
        } else {
            return false;
        }

        return self::insertTelegramUpdate(
            $update->getUpdateId(),
            $chat_id,
            $message_id,
            $edited_message_id,
            $channel_post_id,
            $edited_channel_post_id,
            $message_reaction_id,
            $message_reaction_count_id,
            $inline_query_id,
            $chosen_inline_result_id,
            $callback_query_id,
            $shipping_query_id,
            $pre_checkout_query_id,
            $poll_id,
            $poll_answer_poll_id,
            $my_chat_member_updated_id,
            $chat_member_updated_id,
            $chat_join_request_id,
            $chat_boost_updated_id,
            $chat_boost_removed_id,
        );
    }

    public static function insertMessageReaction(MessageReactionUpdated $message_reaction): bool
    {
        if (!self::isDbConnected()) {
            return false;
        }

        try {
            $sth = self::$pdo->prepare('
                INSERT IGNORE INTO `' . TB_MESSAGE_REACTION . '`
                (`chat_id`, `message_id`, `user_id`, `actor_chat_id`, `old_reaction`, `new_reaction`, `created_at`)
                VALUES
                (:chat_id, :message_id, :user_id, :actor_chat_id, :old_reaction, :new_reaction, :created_at)
            ');

            $date          = self::getTimestamp($message_reaction->getDate());
            $chat_id       = null;
            $user_id       = null;
            $actor_chat_id = null;

            if ($chat = $message_reaction->getChat()) {
                $chat_id = $chat->getId();
                self::insertChat($chat, $date);
            }
            if ($user = $message_reaction->getUser()) {
                $user_id = $user->getId();
                self::insertUser($user, $date);
            }
            if ($actor_chat = $message_reaction->getActorChat()) {
                $actor_chat_id = $actor_chat->getId();
                self::insertChat($actor_chat, $date);
            }

            $sth->bindValue(':chat_id', $chat_id);
            $sth->bindValue(':message_id', $message_reaction->getMessageId());
            $sth->bindValue(':user_id', $user_id);
            $sth->bindValue(':actor_chat_id', $actor_chat_id);
            $sth->bindValue(':old_reaction', self::entitiesArrayToJson($message_reaction->getOldReaction() ?: []));
            $sth->bindValue(':new_reaction', self::entitiesArrayToJson($message_reaction->getNewReaction() ?: []));
            $sth->bindValue(':created_at', $date);

            return $sth->execute();
        } catch (PDOException $e) {
            throw new TelegramException($e->getMessage());
        }
    }

    public static function insertMessageReactionCount(MessageReactionCountUpdated $message_reaction_count): bool
    {
        if (!self::isDbConnected()) {
            return false;
        }

        try {
            $sth = self::$pdo->prepare('
                INSERT IGNORE INTO `' . TB_MESSAGE_REACTION_COUNT . '`
                (`chat_id`, `message_id`, `reactions`, `created_at`)
                VALUES
                (:chat_id, :message_id, :reactions, :created_at)
            ');

            $date    = self::getTimestamp($message_reaction_count->getDate());
            $chat_id = null;

            if ($chat = $message_reaction->getChat()) {
                $chat_id = $chat->getId();
                self::insertChat($chat, $date);
            }

            $sth->bindValue(':chat_id', $chat_id);
            $sth->bindValue(':message_id', $message_reaction_count->getMessageId());
            $sth->bindValue(':reactions', $message_reaction_count->getReactions());
            $sth->bindValue(':created_at', $date);

            return $sth->execute();
        } catch (PDOException $e) {
            throw new TelegramException($e->getMessage());
        }
    }

    /**
     * Insert inline query request into database
     *
     * @param InlineQuery $inline_query
     *
     * @return bool If the insert was successful
     * @throws TelegramException
     */
    public static function insertInlineQueryRequest(InlineQuery $inline_query): bool
    {
        if (!self::isDbConnected()) {
            return false;
        }

        try {
            $sth = self::$pdo->prepare('
                INSERT IGNORE INTO `' . TB_INLINE_QUERY . '`
                (`id`, `user_id`, `location`, `query`, `offset`, `chat_type`, `created_at`)
                VALUES
                (:id, :user_id, :location, :query, :offset, :chat_type, :created_at)
            ');

            $date    = self::getTimestamp();
            $user_id = null;

            if ($user = $inline_query->getFrom()) {
                $user_id = $user->getId();
                self::insertUser($user, $date);
            }

            $sth->bindValue(':id', $inline_query->getId());
            $sth->bindValue(':user_id', $user_id);
            $sth->bindValue(':location', $inline_query->getLocation());
            $sth->bindValue(':query', $inline_query->getQuery());
            $sth->bindValue(':offset', $inline_query->getOffset());
            $sth->bindValue(':chat_type', $inline_query->getChatType());
            $sth->bindValue(':created_at', $date);

            return $sth->execute();
        } catch (PDOException $e) {
            throw new TelegramException($e->getMessage());
        }
    }

    /**
     * Insert chosen inline result request into database
     *
     * @param ChosenInlineResult $chosen_inline_result
     *
     * @return bool If the insert was successful
     * @throws TelegramException
     */
    public static function insertChosenInlineResultRequest(ChosenInlineResult $chosen_inline_result): bool
    {
        if (!self::isDbConnected()) {
            return false;
        }

        try {
            $sth = self::$pdo->prepare('
                INSERT INTO `' . TB_CHOSEN_INLINE_RESULT . '`
                (`result_id`, `user_id`, `location`, `inline_message_id`, `query`, `created_at`)
                VALUES
                (:result_id, :user_id, :location, :inline_message_id, :query, :created_at)
            ');

            $date    = self::getTimestamp();
            $user_id = null;

            if ($user = $chosen_inline_result->getFrom()) {
                $user_id = $user->getId();
                self::insertUser($user, $date);
            }

            $sth->bindValue(':result_id', $chosen_inline_result->getResultId());
            $sth->bindValue(':user_id', $user_id);
            $sth->bindValue(':location', $chosen_inline_result->getLocation());
            $sth->bindValue(':inline_message_id', $chosen_inline_result->getInlineMessageId());
            $sth->bindValue(':query', $chosen_inline_result->getQuery());
            $sth->bindValue(':created_at', $date);

            return $sth->execute();
        } catch (PDOException $e) {
            throw new TelegramException($e->getMessage());
        }
    }

    /**
     * Insert callback query request into database
     *
     * @param CallbackQuery $callback_query
     *
     * @return bool If the insert was successful
     * @throws TelegramException
     */
    public static function insertCallbackQueryRequest(CallbackQuery $callback_query): bool
    {
        if (!self::isDbConnected()) {
            return false;
        }

        try {
            $sth = self::$pdo->prepare('
                INSERT IGNORE INTO `' . TB_CALLBACK_QUERY . '`
                (`id`, `user_id`, `chat_id`, `message_id`, `inline_message_id`, `chat_instance`, `data`, `game_short_name`, `created_at`)
                VALUES
                (:id, :user_id, :chat_id, :message_id, :inline_message_id, :chat_instance, :data, :game_short_name, :created_at)
            ');

            $date    = self::getTimestamp();
            $user_id = null;

            if ($user = $callback_query->getFrom()) {
                $user_id = $user->getId();
                self::insertUser($user, $date);
            }

            $chat_id    = null;
            $message_id = null;
            if ($message = $callback_query->getMessage()) {
                $chat_id    = $message->getChat()->getId();
                $message_id = $message->getMessageId();

                $is_message = self::$pdo->query('
                    SELECT *
                    FROM `' . TB_MESSAGE . '`
                    WHERE `id` = ' . $message_id . '
                      AND `chat_id` = ' . $chat_id . '
                    LIMIT 1
                ')->rowCount();

                if ($is_message) {
                    self::insertEditedMessageRequest($message);
                } else {
                    self::insertMessageRequest($message);
                }
            }

            $sth->bindValue(':id', $callback_query->getId());
            $sth->bindValue(':user_id', $user_id);
            $sth->bindValue(':chat_id', $chat_id);
            $sth->bindValue(':message_id', $message_id);
            $sth->bindValue(':inline_message_id', $callback_query->getInlineMessageId());
            $sth->bindValue(':chat_instance', $callback_query->getChatInstance());
            $sth->bindValue(':data', $callback_query->getData());
            $sth->bindValue(':game_short_name', $callback_query->getGameShortName());
            $sth->bindValue(':created_at', $date);

            return $sth->execute();
        } catch (PDOException $e) {
            throw new TelegramException($e->getMessage());
        }
    }

    /**
     * Insert shipping query request into database
     *
     * @param ShippingQuery $shipping_query
     *
     * @return bool If the insert was successful
     * @throws TelegramException
     */
    public static function insertShippingQueryRequest(ShippingQuery $shipping_query): bool
    {
        if (!self::isDbConnected()) {
            return false;
        }

        try {
            $sth = self::$pdo->prepare('
                INSERT IGNORE INTO `' . TB_SHIPPING_QUERY . '`
                (`id`, `user_id`, `invoice_payload`, `shipping_address`, `created_at`)
                VALUES
                (:id, :user_id, :invoice_payload, :shipping_address, :created_at)
            ');

            $date    = self::getTimestamp();
            $user_id = null;

            if ($user = $shipping_query->getFrom()) {
                $user_id = $user->getId();
                self::insertUser($user, $date);
            }

            $sth->bindValue(':id', $shipping_query->getId());
            $sth->bindValue(':user_id', $user_id);
            $sth->bindValue(':invoice_payload', $shipping_query->getInvoicePayload());
            $sth->bindValue(':shipping_address', $shipping_query->getShippingAddress());
            $sth->bindValue(':created_at', $date);

            return $sth->execute();
        } catch (PDOException $e) {
            throw new TelegramException($e->getMessage());
        }
    }

    /**
     * Insert pre checkout query request into database
     *
     * @param PreCheckoutQuery $pre_checkout_query
     *
     * @return bool If the insert was successful
     * @throws TelegramException
     */
    public static function insertPreCheckoutQueryRequest(PreCheckoutQuery $pre_checkout_query): bool
    {
        if (!self::isDbConnected()) {
            return false;
        }

        try {
            $sth = self::$pdo->prepare('
                INSERT IGNORE INTO `' . TB_PRE_CHECKOUT_QUERY . '`
                (`id`, `user_id`, `currency`, `total_amount`, `invoice_payload`, `shipping_option_id`, `order_info`, `created_at`)
                VALUES
                (:id, :user_id, :currency, :total_amount, :invoice_payload, :shipping_option_id, :order_info, :created_at)
            ');

            $date    = self::getTimestamp();
            $user_id = null;

            if ($user = $pre_checkout_query->getFrom()) {
                $user_id = $user->getId();
                self::insertUser($user, $date);
            }

            $sth->bindValue(':id', $pre_checkout_query->getId());
            $sth->bindValue(':user_id', $user_id);
            $sth->bindValue(':currency', $pre_checkout_query->getCurrency());
            $sth->bindValue(':total_amount', $pre_checkout_query->getTotalAmount());
            $sth->bindValue(':invoice_payload', $pre_checkout_query->getInvoicePayload());
            $sth->bindValue(':shipping_option_id', $pre_checkout_query->getShippingOptionId());
            $sth->bindValue(':order_info', $pre_checkout_query->getOrderInfo());
            $sth->bindValue(':created_at', $date);

            return $sth->execute();
        } catch (PDOException $e) {
            throw new TelegramException($e->getMessage());
        }
    }

    /**
     * Insert poll request into database
     *
     * @param Poll $poll
     *
     * @return bool If the insert was successful
     * @throws TelegramException
     */
    public static function insertPollRequest(Poll $poll): bool
    {
        if (!self::isDbConnected()) {
            return false;
        }

        try {
            $sth = self::$pdo->prepare('
                INSERT INTO `' . TB_POLL . '`
                (`id`, `question`, `options`, `total_voter_count`, `is_closed`, `is_anonymous`, `type`, `allows_multiple_answers`, `correct_option_id`, `explanation`, `explanation_entities`, `open_period`, `close_date`, `created_at`)
                VALUES
                (:id, :question, :options, :total_voter_count, :is_closed, :is_anonymous, :type, :allows_multiple_answers, :correct_option_id, :explanation, :explanation_entities, :open_period, :close_date, :created_at)
                ON DUPLICATE KEY UPDATE
                    `options`                 = VALUES(`options`),
                    `total_voter_count`       = VALUES(`total_voter_count`),
                    `is_closed`               = VALUES(`is_closed`),
                    `is_anonymous`            = VALUES(`is_anonymous`),
                    `type`                    = VALUES(`type`),
                    `allows_multiple_answers` = VALUES(`allows_multiple_answers`),
                    `correct_option_id`       = VALUES(`correct_option_id`),
                    `explanation`             = VALUES(`explanation`),
                    `explanation_entities`    = VALUES(`explanation_entities`),
                    `open_period`             = VALUES(`open_period`),
                    `close_date`              = VALUES(`close_date`)
            ');

            $sth->bindValue(':id', $poll->getId());
            $sth->bindValue(':question', $poll->getQuestion());
            $sth->bindValue(':options', self::entitiesArrayToJson($poll->getOptions() ?: []));
            $sth->bindValue(':total_voter_count', $poll->getTotalVoterCount());
            $sth->bindValue(':is_closed', $poll->getIsClosed(), PDO::PARAM_INT);
            $sth->bindValue(':is_anonymous', $poll->getIsAnonymous(), PDO::PARAM_INT);
            $sth->bindValue(':type', $poll->getType());
            $sth->bindValue(':allows_multiple_answers', $poll->getAllowsMultipleAnswers(), PDO::PARAM_INT);
            $sth->bindValue(':correct_option_id', $poll->getCorrectOptionId());
            $sth->bindValue(':explanation', $poll->getExplanation());
            $sth->bindValue(':explanation_entities', self::entitiesArrayToJson($poll->getExplanationEntities() ?: []));
            $sth->bindValue(':open_period', $poll->getOpenPeriod());
            $sth->bindValue(':close_date', self::getTimestamp($poll->getCloseDate()));
            $sth->bindValue(':created_at', self::getTimestamp());

            return $sth->execute();
        } catch (PDOException $e) {
            throw new TelegramException($e->getMessage());
        }
    }

    /**
     * Insert poll answer request into database
     *
     * @param PollAnswer $poll_answer
     *
     * @return bool If the insert was successful
     * @throws TelegramException
     */
    public static function insertPollAnswerRequest(PollAnswer $poll_answer): bool
    {
        if (!self::isDbConnected()) {
            return false;
        }

        try {
            $sth = self::$pdo->prepare('
                INSERT INTO `' . TB_POLL_ANSWER . '`
                (`poll_id`, `user_id`, `option_ids`, `created_at`)
                VALUES
                (:poll_id, :user_id, :option_ids, :created_at)
                ON DUPLICATE KEY UPDATE
                    `option_ids` = VALUES(`option_ids`)
            ');

            $date    = self::getTimestamp();
            $user_id = null;

            if ($user = $poll_answer->getUser()) {
                $user_id = $user->getId();
                self::insertUser($user, $date);
            }

            $sth->bindValue(':poll_id', $poll_answer->getPollId());
            $sth->bindValue(':user_id', $user_id);
            $sth->bindValue(':option_ids', json_encode($poll_answer->getOptionIds()));
            $sth->bindValue(':created_at', $date);

            return $sth->execute();
        } catch (PDOException $e) {
            throw new TelegramException($e->getMessage());
        }
    }

    /**
     * Insert chat member updated request into database
     *
     * @param ChatMemberUpdated $chat_member_updated
     *
     * @return bool If the insert was successful
     * @throws TelegramException
     */
    public static function insertChatMemberUpdatedRequest(ChatMemberUpdated $chat_member_updated): bool
    {
        if (!self::isDbConnected()) {
            return false;
        }

        try {
            $sth = self::$pdo->prepare('
                INSERT INTO `' . TB_CHAT_MEMBER_UPDATED . '`
                (`chat_id`, `user_id`, `date`, `old_chat_member`, `new_chat_member`, `invite_link`, `created_at`)
                VALUES
                (:chat_id, :user_id, :date, :old_chat_member, :new_chat_member, :invite_link, :created_at)
            ');

            $date    = self::getTimestamp();
            $chat_id = null;
            $user_id = null;

            if ($chat = $chat_member_updated->getChat()) {
                $chat_id = $chat->getId();
                self::insertChat($chat, $date);
            }
            if ($user = $chat_member_updated->getFrom()) {
                $user_id = $user->getId();
                self::insertUser($user, $date);
            }

            $sth->bindValue(':chat_id', $chat_id);
            $sth->bindValue(':user_id', $user_id);
            $sth->bindValue(':date', self::getTimestamp($chat_member_updated->getDate()));
            $sth->bindValue(':old_chat_member', $chat_member_updated->getOldChatMember());
            $sth->bindValue(':new_chat_member', $chat_member_updated->getNewChatMember());
            $sth->bindValue(':invite_link', $chat_member_updated->getInviteLink());
            $sth->bindValue(':created_at', $date);

            return $sth->execute();
        } catch (PDOException $e) {
            throw new TelegramException($e->getMessage());
        }
    }

    /**
     * Insert chat join request into database
     *
     * @param ChatJoinRequest $chat_join_request
     *
     * @return bool If the insert was successful
     * @throws TelegramException
     */
    public static function insertChatJoinRequestRequest(ChatJoinRequest $chat_join_request): bool
    {
        if (!self::isDbConnected()) {
            return false;
        }

        try {
            $sth = self::$pdo->prepare('
                INSERT INTO `' . TB_CHAT_JOIN_REQUEST . '`
                (`chat_id`, `user_id`, `date`, `bio`, `invite_link`, `created_at`)
                VALUES
                (:chat_id, :user_id, :date, :bio, :invite_link, :created_at)
            ');

            $date    = self::getTimestamp();
            $chat_id = null;
            $user_id = null;

            if ($chat = $chat_join_request->getChat()) {
                $chat_id = $chat->getId();
                self::insertChat($chat, $date);
            }
            if ($user = $chat_join_request->getFrom()) {
                $user_id = $user->getId();
                self::insertUser($user, $date);
            }

            $sth->bindValue(':chat_id', $chat_id);
            $sth->bindValue(':user_id', $user_id);
            $sth->bindValue(':date', self::getTimestamp($chat_join_request->getDate()));
            $sth->bindValue(':bio', $chat_join_request->getBio());
            $sth->bindValue(':invite_link', $chat_join_request->getInviteLink());
            $sth->bindValue(':created_at', $date);

            return $sth->execute();
        } catch (PDOException $e) {
            throw new TelegramException($e->getMessage());
        }
    }

    /**
     * Insert chat boost updated into database
     *
     * @param ChatBoostUpdated $chat_boost_updated
     *
     * @return bool If the insert was successful
     * @throws TelegramException
     */
    public static function insertChatBoostUpdatedRequest(ChatBoostUpdated $chat_boost_updated): bool
    {
        if (!self::isDbConnected()) {
            return false;
        }

        try {
            $sth = self::$pdo->prepare('
                INSERT INTO `' . TB_CHAT_BOOST_UPDATED . '`
                (`chat_id`, `boost`, `created_at`)
                VALUES
                (:chat_id, :boost, :created_at)
            ');

            $date    = self::getTimestamp();
            $chat_id = null;

            if ($chat = $chat_boost_updated->getChat()) {
                $chat_id = $chat->getId();
                self::insertChat($chat, $date);
            }

            $sth->bindValue(':chat_id', $chat_id);
            $sth->bindValue(':boost', $chat_boost_updated->getBoost());
            $sth->bindValue(':created_at', $date);

            return $sth->execute();
        } catch (PDOException $e) {
            throw new TelegramException($e->getMessage());
        }
    }

    /**
     * Insert chat boost removed into database
     *
     * @param ChatBoostRemoved $chat_boost_removed
     *
     * @return bool If the insert was successful
     * @throws TelegramException
     */
    public static function insertChatBoostRemovedRequest(ChatBoostRemoved $chat_boost_removed): bool
    {
        if (!self::isDbConnected()) {
            return false;
        }

        try {
            $sth = self::$pdo->prepare('
                INSERT INTO `' . TB_CHAT_BOOST_REMOVED . '`
                (`chat_id`, `boost_id`, `remove_date`, `source`, `created_at`)
                VALUES
                (:chat_id, :boost_id, :remove_date, :source, :created_at)
            ');

            $date    = self::getTimestamp();
            $chat_id = null;

            if ($chat = $chat_boost_removed->getChat()) {
                $chat_id = $chat->getId();
                self::insertChat($chat, $date);
            }

            $sth->bindValue(':chat_id', $chat_id);
            $sth->bindValue(':boost_id', $chat_boost_removed->getBoostId());
            $sth->bindValue(':remove_date', self::getTimestamp($chat_boost_removed->getRemoveDate()));
            $sth->bindValue(':source', $chat_boost_removed->getSource());
            $sth->bindValue(':created_at', $date);

            return $sth->execute();
        } catch (PDOException $e) {
            throw new TelegramException($e->getMessage());
        }
    }

    /**
     * Insert Message request in db
     *
     * @param Message $message
     *
     * @return bool If the insert was successful
     * @throws TelegramException
     */
    public static function insertMessageRequest(Message $message): bool
    {
        if (!self::isDbConnected()) {
            return false;
        }

        $date = self::getTimestamp($message->getDate());

        // Insert chat, update chat id in case it migrated
        $chat = $message->getChat();
        self::insertChat($chat, $date, $message->getMigrateToChatId());

        $sender_chat_id = null;
        if ($sender_chat = $message->getSenderChat()) {
            self::insertChat($sender_chat);
            $sender_chat_id = $sender_chat->getId();
        }

        // Insert user and the relation with the chat
        if ($user = $message->getFrom()) {
            self::insertUser($user, $date, $chat);
        }

        // Insert the forwarded message user in users table
        $forward_from = null;
        $forward_from_chat = null;
        $forward_from_message_id = null;
        $forward_signature = null;
        $forward_sender_name = null;
        $forward_date = null;

        if ($forward_origin = $message->getForwardOrigin()) {
            $forward_date = self::getTimestamp($forward_origin->getDate());

            if ($forward_origin instanceof MessageOriginUser) {
                self::insertUser($forward_origin->getSenderUser());
                $forward_from = $forward_origin->getSenderUser()->getId();
            } elseif ($forward_origin instanceof MessageOriginHiddenUser) {
                $forward_sender_name = $forward_origin->getSenderUserName();
            } elseif ($forward_origin instanceof MessageOriginChat) {
                self::insertChat($forward_origin->getSenderChat());
                $forward_from_chat = $forward_origin->getSenderChat()->getId();
                $forward_signature = $forward_origin->getAuthorSignature();
            } elseif ($forward_origin instanceof MessageOriginChannel) {
                self::insertChat($forward_origin->getChat());
                $forward_from_chat = $forward_origin->getChat()->getId();
                $forward_from_message_id = $forward_origin->getMessageId();
                $forward_signature = $forward_origin->getAuthorSignature();
            }
        }

        $via_bot_id = null;
        if ($via_bot = $message->getViaBot()) {
            self::insertUser($via_bot);
            $via_bot_id = $via_bot->getId();
        }

        // New and left chat member
        $new_chat_members_ids = null;
        $left_chat_member_id  = null;

        $new_chat_members = $message->getNewChatMembers();
        $left_chat_member = $message->getLeftChatMember();
        if (!empty($new_chat_members)) {
            foreach ($new_chat_members as $new_chat_member) {
                if ($new_chat_member instanceof User) {
                    // Insert the new chat user
                    self::insertUser($new_chat_member, $date, $chat);
                    $new_chat_members_ids[] = $new_chat_member->getId();
                }
            }
            $new_chat_members_ids = implode(',', $new_chat_members_ids);
        } elseif ($left_chat_member) {
            // Insert the left chat user
            self::insertUser($left_chat_member, $date, $chat);
            $left_chat_member_id = $left_chat_member->getId();
        }

        try {
            $sth = self::$pdo->prepare('
                INSERT IGNORE INTO `' . TB_MESSAGE . '`
                (
                    `id`, `user_id`, `chat_id`, `message_thread_id`, `sender_chat_id`, `sender_boost_count`, `date`, `forward_from`, `forward_from_chat`, `forward_from_message_id`,
                    `forward_signature`, `forward_sender_name`, `forward_date`, `is_topic_message`,
                    `reply_to_chat`, `reply_to_message`, `external_reply`, `quote`, `reply_to_story`, `via_bot`, `link_preview_options`, `edit_date`, `media_group_id`, `author_signature`, `text`, `entities`, `caption_entities`,
                    `audio`, `document`, `animation`, `game`, `photo`, `sticker`, `story`, `video`, `voice`, `video_note`, `caption`, `has_media_spoiler`, `contact`,
                    `location`, `venue`, `poll`, `dice`, `new_chat_members`, `left_chat_member`,
                    `new_chat_title`, `new_chat_photo`, `delete_chat_photo`, `group_chat_created`,
                    `supergroup_chat_created`, `channel_chat_created`, `message_auto_delete_timer_changed`, `migrate_to_chat_id`, `migrate_from_chat_id`,
                    `pinned_message`, `invoice`, `successful_payment`, `users_shared`, `chat_shared`, `connected_website`, `write_access_allowed`, `passport_data`, `proximity_alert_triggered`, `boost_added`,
                    `forum_topic_created`, `forum_topic_edited`, `forum_topic_closed`, `forum_topic_reopened`, `general_forum_topic_hidden`, `general_forum_topic_unhidden`,
                    `video_chat_scheduled`, `video_chat_started`, `video_chat_ended`, `video_chat_participants_invited`, `web_app_data`, `reply_markup`
                ) VALUES (
                    :message_id, :user_id, :chat_id, :message_thread_id, :sender_chat_id, :sender_boost_count, :date, :forward_from, :forward_from_chat, :forward_from_message_id,
                    :forward_signature, :forward_sender_name, :forward_date, :is_topic_message,
                    :reply_to_chat, :reply_to_message, :external_reply, :quote, :reply_to_story, :via_bot, :link_preview_options, :edit_date, :media_group_id, :author_signature, :text, :entities, :caption_entities,
                    :audio, :document, :animation, :game, :photo, :sticker, :story, :video, :voice, :video_note, :caption, :has_media_spoiler, :contact,
                    :location, :venue, :poll, :dice, :new_chat_members, :left_chat_member,
                    :new_chat_title, :new_chat_photo, :delete_chat_photo, :group_chat_created,
                    :supergroup_chat_created, :channel_chat_created, :message_auto_delete_timer_changed, :migrate_to_chat_id, :migrate_from_chat_id,
                    :pinned_message, :invoice, :successful_payment, :users_shared, :chat_shared, :connected_website, :write_access_allowed, :passport_data, :proximity_alert_triggered, :boost_added,
                    :forum_topic_created, :forum_topic_edited, :forum_topic_closed, :forum_topic_reopened, :general_forum_topic_hidden, :general_forum_topic_unhidden,
                    :video_chat_scheduled, :video_chat_started, :video_chat_ended, :video_chat_participants_invited, :web_app_data, :reply_markup
                )
            ');

            $user_id = $user ? $user->getId() : null;
            $chat_id = $chat->getId();

            $reply_to_message_id = null;
            if ($reply_to_message = $message->getReplyToMessage()) {
                $reply_to_message_id = $reply_to_message->getMessageId();
                // please notice that, as explained in the documentation, reply_to_message don't contain other
                // reply_to_message field so recursion deep is 1
                self::insertMessageRequest($reply_to_message);
            }

            $sth->bindValue(':message_id', $message->getMessageId());
            $sth->bindValue(':chat_id', $chat_id);
            $sth->bindValue(':sender_chat_id', $sender_chat_id);
            $sth->bindValue(':message_thread_id', $message->getMessageThreadId());
            $sth->bindValue(':user_id', $user_id);
            $sth->bindValue(':sender_boost_count', $message->getSenderBoostCount());
            $sth->bindValue(':date', $date);
            $sth->bindValue(':forward_from', $forward_from);
            $sth->bindValue(':forward_from_chat', $forward_from_chat);
            $sth->bindValue(':forward_from_message_id', $forward_from_message_id);
            $sth->bindValue(':forward_signature', $forward_signature);
            $sth->bindValue(':forward_sender_name', $forward_sender_name);
            $sth->bindValue(':forward_date', $forward_date);
            $sth->bindValue(':is_topic_message', $message->getIsTopicMessage());

            $reply_to_chat_id = null;
            if ($reply_to_message_id !== null) {
                $reply_to_chat_id = $chat_id;
            }
            $sth->bindValue(':reply_to_chat', $reply_to_chat_id);
            $sth->bindValue(':reply_to_message', $reply_to_message_id);
            $sth->bindValue(':external_reply', $message->getExternalReply());

            $sth->bindValue(':quote', $message->getQuote());
            $sth->bindValue(':reply_to_story', $message->getReplyToStory());
            $sth->bindValue(':via_bot', $via_bot_id);
            $sth->bindValue(':link_preview_options', $message->getLinkPreviewOptions());
            $sth->bindValue(':edit_date', self::getTimestamp($message->getEditDate()));
            $sth->bindValue(':media_group_id', $message->getMediaGroupId());
            $sth->bindValue(':author_signature', $message->getAuthorSignature());
            $sth->bindValue(':text', $message->getText());
            $sth->bindValue(':entities', self::entitiesArrayToJson($message->getEntities() ?: []));
            $sth->bindValue(':caption_entities', self::entitiesArrayToJson($message->getCaptionEntities() ?: []));
            $sth->bindValue(':audio', $message->getAudio());
            $sth->bindValue(':document', $message->getDocument());
            $sth->bindValue(':animation', $message->getAnimation());
            $sth->bindValue(':game', $message->getGame());
            $sth->bindValue(':photo', self::entitiesArrayToJson($message->getPhoto() ?: []));
            $sth->bindValue(':sticker', $message->getSticker());
            $sth->bindValue(':story', $message->getStory());
            $sth->bindValue(':video', $message->getVideo());
            $sth->bindValue(':voice', $message->getVoice());
            $sth->bindValue(':video_note', $message->getVideoNote());
            $sth->bindValue(':caption', $message->getCaption());
            $sth->bindValue(':has_media_spoiler', $message->getHasMediaSpoiler());
            $sth->bindValue(':contact', $message->getContact());
            $sth->bindValue(':location', $message->getLocation());
            $sth->bindValue(':venue', $message->getVenue());
            $sth->bindValue(':poll', $message->getPoll());
            $sth->bindValue(':dice', $message->getDice());
            $sth->bindValue(':new_chat_members', $new_chat_members_ids);
            $sth->bindValue(':left_chat_member', $left_chat_member_id);
            $sth->bindValue(':new_chat_title', $message->getNewChatTitle());
            $sth->bindValue(':new_chat_photo', self::entitiesArrayToJson($message->getNewChatPhoto() ?: []));
            $sth->bindValue(':delete_chat_photo', $message->getDeleteChatPhoto());
            $sth->bindValue(':group_chat_created', $message->getGroupChatCreated());
            $sth->bindValue(':supergroup_chat_created', $message->getSupergroupChatCreated());
            $sth->bindValue(':channel_chat_created', $message->getChannelChatCreated());
            $sth->bindValue(':message_auto_delete_timer_changed', $message->getMessageAutoDeleteTimerChanged());
            $sth->bindValue(':migrate_to_chat_id', $message->getMigrateToChatId());
            $sth->bindValue(':migrate_from_chat_id', $message->getMigrateFromChatId());
            $sth->bindValue(':pinned_message', $message->getPinnedMessage());
            $sth->bindValue(':invoice', $message->getInvoice());
            $sth->bindValue(':successful_payment', $message->getSuccessfulPayment());
            $sth->bindValue(':users_shared', $message->getUsersShared());
            $sth->bindValue(':chat_shared', $message->getChatShared());
            $sth->bindValue(':connected_website', $message->getConnectedWebsite());
            $sth->bindValue(':write_access_allowed', $message->getWriteAccessAllowed());
            $sth->bindValue(':passport_data', $message->getPassportData());
            $sth->bindValue(':proximity_alert_triggered', $message->getProximityAlertTriggered());
            $sth->bindValue(':boost_added', $message->getBoostAdded());
            $sth->bindValue(':forum_topic_created', $message->getForumTopicCreated());
            $sth->bindValue(':forum_topic_edited', $message->getForumTopicEdited());
            $sth->bindValue(':forum_topic_closed', $message->getForumTopicClosed());
            $sth->bindValue(':forum_topic_reopened', $message->getForumTopicReopened());
            $sth->bindValue(':general_forum_topic_hidden', $message->getGeneralForumTopicHidden());
            $sth->bindValue(':general_forum_topic_unhidden', $message->getGeneralForumTopicUnhidden());
            $sth->bindValue(':video_chat_scheduled', $message->getVideoChatScheduled());
            $sth->bindValue(':video_chat_started', $message->getVideoChatStarted());
            $sth->bindValue(':video_chat_ended', $message->getVideoChatEnded());
            $sth->bindValue(':video_chat_participants_invited', $message->getVideoChatParticipantsInvited());
            $sth->bindValue(':web_app_data', $message->getWebAppData());
            $sth->bindValue(':reply_markup', $message->getReplyMarkup());

            return $sth->execute();
        } catch (PDOException $e) {
            throw new TelegramException($e->getMessage());
        }
    }

    /**
     * Insert Edited Message request in db
     *
     * @param Message $edited_message
     *
     * @return bool If the insert was successful
     * @throws TelegramException
     */
    public static function insertEditedMessageRequest(Message $edited_message): bool
    {
        if (!self::isDbConnected()) {
            return false;
        }

        try {
            $edit_date = self::getTimestamp($edited_message->getEditDate());

            // Insert chat
            $chat = $edited_message->getChat();
            self::insertChat($chat, $edit_date);

            // Insert user and the relation with the chat
            if ($user = $edited_message->getFrom()) {
                self::insertUser($user, $edit_date, $chat);
            }

            $sth = self::$pdo->prepare('
                INSERT IGNORE INTO `' . TB_EDITED_MESSAGE . '`
                (`chat_id`, `message_id`, `user_id`, `edit_date`, `text`, `entities`, `caption`)
                VALUES
                (:chat_id, :message_id, :user_id, :edit_date, :text, :entities, :caption)
            ');

            $user_id = $user ? $user->getId() : null;

            $sth->bindValue(':chat_id', $chat->getId());
            $sth->bindValue(':message_id', $edited_message->getMessageId());
            $sth->bindValue(':user_id', $user_id);
            $sth->bindValue(':edit_date', $edit_date);
            $sth->bindValue(':text', $edited_message->getText());
            $sth->bindValue(':entities', self::entitiesArrayToJson($edited_message->getEntities() ?: []));
            $sth->bindValue(':caption', $edited_message->getCaption());

            return $sth->execute();
        } catch (PDOException $e) {
            throw new TelegramException($e->getMessage());
        }
    }

    /**
     * Select Groups, Supergroups, Channels and/or single user Chats (also by ID or text)
     *
     * @param $select_chats_params
     *
     * @return array|bool
     * @throws TelegramException
     */
    public static function selectChats($select_chats_params)
    {
        if (!self::isDbConnected()) {
            return false;
        }

        // Set defaults for omitted values.
        $select = array_merge([
            'groups'      => true,
            'supergroups' => true,
            'channels'    => true,
            'users'       => true,
            'date_from'   => null,
            'date_to'     => null,
            'chat_id'     => null,
            'text'        => null,
            'language'    => null,
        ], $select_chats_params);

        if (!$select['groups'] && !$select['users'] && !$select['supergroups'] && !$select['channels']) {
            return false;
        }

        try {
            $query = '
                SELECT * ,
                ' . TB_CHAT . '.`id` AS `chat_id`,
                ' . TB_CHAT . '.`username` AS `chat_username`,
                ' . TB_CHAT . '.`created_at` AS `chat_created_at`,
                ' . TB_CHAT . '.`updated_at` AS `chat_updated_at`
            ';
            if ($select['users']) {
                $query .= '
                    , ' . TB_USER . '.`id` AS `user_id`
                    FROM `' . TB_CHAT . '`
                    LEFT JOIN `' . TB_USER . '`
                    ON ' . TB_CHAT . '.`id`=' . TB_USER . '.`id`
                ';
            } else {
                $query .= 'FROM `' . TB_CHAT . '`';
            }

            // Building parts of query
            $where  = [];
            $tokens = [];

            if (!$select['groups'] || !$select['users'] || !$select['supergroups'] || !$select['channels']) {
                $chat_or_user = [];

                $select['groups'] && $chat_or_user[] = TB_CHAT . '.`type` = "group"';
                $select['supergroups'] && $chat_or_user[] = TB_CHAT . '.`type` = "supergroup"';
                $select['channels'] && $chat_or_user[] = TB_CHAT . '.`type` = "channel"';
                $select['users'] && $chat_or_user[] = TB_CHAT . '.`type` = "private"';

                $where[] = '(' . implode(' OR ', $chat_or_user) . ')';
            }

            if (null !== $select['date_from']) {
                $where[]              = TB_CHAT . '.`updated_at` >= :date_from';
                $tokens[':date_from'] = $select['date_from'];
            }

            if (null !== $select['date_to']) {
                $where[]            = TB_CHAT . '.`updated_at` <= :date_to';
                $tokens[':date_to'] = $select['date_to'];
            }

            if (null !== $select['chat_id']) {
                $where[]            = TB_CHAT . '.`id` = :chat_id';
                $tokens[':chat_id'] = $select['chat_id'];
            }

            if ($select['users'] && null !== $select['language']) {
                $where[]             = TB_USER . '.`language_code` = :language';
                $tokens[':language'] = $select['language'];
            }

            if (null !== $select['text']) {
                $text_like = '%' . strtolower($select['text']) . '%';
                if ($select['users']) {
                    $where[]          = '(
                        LOWER(' . TB_CHAT . '.`title`) LIKE :text1
                        OR LOWER(' . TB_USER . '.`first_name`) LIKE :text2
                        OR LOWER(' . TB_USER . '.`last_name`) LIKE :text3
                        OR LOWER(' . TB_USER . '.`username`) LIKE :text4
                    )';
                    $tokens[':text1'] = $text_like;
                    $tokens[':text2'] = $text_like;
                    $tokens[':text3'] = $text_like;
                    $tokens[':text4'] = $text_like;
                } else {
                    $where[]         = 'LOWER(' . TB_CHAT . '.`title`) LIKE :text';
                    $tokens[':text'] = $text_like;
                }
            }

            if (!empty($where)) {
                $query .= ' WHERE ' . implode(' AND ', $where);
            }

            $query .= ' ORDER BY ' . TB_CHAT . '.`updated_at` ASC';

            $sth = self::$pdo->prepare($query);
            $sth->execute($tokens);

            return $sth->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            throw new TelegramException($e->getMessage());
        }
    }

    /**
     * Get Telegram API request count for current chat / message
     *
     * @param int|string|null $chat_id
     * @param string|null     $inline_message_id
     *
     * @return array|bool Array containing TOTAL and CURRENT fields or false on invalid arguments
     * @throws TelegramException
     */
    public static function getTelegramRequestCount($chat_id = null, string $inline_message_id = null)
    {
        if (!self::isDbConnected()) {
            return false;
        }

        try {
            $sth = self::$pdo->prepare('SELECT
                (SELECT COUNT(DISTINCT `chat_id`) FROM `' . TB_REQUEST_LIMITER . '` WHERE `created_at` >= :created_at_1) AS LIMIT_PER_SEC_ALL,
                (SELECT COUNT(*) FROM `' . TB_REQUEST_LIMITER . '` WHERE `created_at` >= :created_at_2 AND ((`chat_id` = :chat_id_1 AND `inline_message_id` IS NULL) OR (`inline_message_id` = :inline_message_id AND `chat_id` IS NULL))) AS LIMIT_PER_SEC,
                (SELECT COUNT(*) FROM `' . TB_REQUEST_LIMITER . '` WHERE `created_at` >= :created_at_minute AND `chat_id` = :chat_id_2) AS LIMIT_PER_MINUTE
            ');

            $date        = self::getTimestamp();
            $date_minute = self::getTimestamp(strtotime('-1 minute'));

            $sth->bindValue(':chat_id_1', $chat_id);
            $sth->bindValue(':chat_id_2', $chat_id);
            $sth->bindValue(':inline_message_id', $inline_message_id);
            $sth->bindValue(':created_at_1', $date);
            $sth->bindValue(':created_at_2', $date);
            $sth->bindValue(':created_at_minute', $date_minute);

            $sth->execute();

            return $sth->fetch();
        } catch (PDOException $e) {
            throw new TelegramException($e->getMessage());
        }
    }

    /**
     * Insert Telegram API request in db
     *
     * @param string $method
     * @param array  $data
     *
     * @return bool If the insert was successful
     * @throws TelegramException
     */
    public static function insertTelegramRequest(string $method, array $data): bool
    {
        if (!self::isDbConnected()) {
            return false;
        }

        try {
            $sth = self::$pdo->prepare('INSERT INTO `' . TB_REQUEST_LIMITER . '`
                (`method`, `chat_id`, `inline_message_id`, `created_at`)
                VALUES
                (:method, :chat_id, :inline_message_id, :created_at);
            ');

            $chat_id           = $data['chat_id'] ?? null;
            $inline_message_id = $data['inline_message_id'] ?? null;

            $sth->bindValue(':chat_id', $chat_id);
            $sth->bindValue(':inline_message_id', $inline_message_id);
            $sth->bindValue(':method', $method);
            $sth->bindValue(':created_at', self::getTimestamp());

            return $sth->execute();
        } catch (PDOException $e) {
            throw new TelegramException($e->getMessage());
        }
    }

    /**
     * Bulk update the entries of any table
     *
     * @param string $table
     * @param array  $fields_values
     * @param array  $where_fields_values
     *
     * @return bool
     * @throws TelegramException
     */
    public static function update(string $table, array $fields_values, array $where_fields_values): bool
    {
        if (empty($fields_values) || !self::isDbConnected()) {
            return false;
        }

        try {
            // Building parts of query
            $tokens = $fields = $where = [];

            // Fields with values to update
            foreach ($fields_values as $field => $value) {
                $token          = ':' . count($tokens);
                $fields[]       = "`{$field}` = {$token}";
                $tokens[$token] = $value;
            }

            // Where conditions
            foreach ($where_fields_values as $field => $value) {
                $token          = ':' . count($tokens);
                $where[]        = "`{$field}` = {$token}";
                $tokens[$token] = $value;
            }

            $sql = 'UPDATE `' . $table . '` SET ' . implode(', ', $fields);
            $sql .= count($where) > 0 ? ' WHERE ' . implode(' AND ', $where) : '';

            return self::$pdo->prepare($sql)->execute($tokens);
        } catch (PDOException $e) {
            throw new TelegramException($e->getMessage());
        }
    }
}
