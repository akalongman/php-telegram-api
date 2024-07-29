<?php

/**
 * This file is part of the TelegramBot package.
 *
 * (c) Avtandil Kikabidze aka LONGMAN <akalongman@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Longman\TelegramBot\Commands\AdminCommands;

use Longman\TelegramBot\Commands\AdminCommand;
use Longman\TelegramBot\DB;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\TelegramLog;
use PDOException;

/**
 * User "/cleanup" command
 *
 * Configuration options:
 *
 * $telegram->setCommandConfig('cleanup', [
 *     // Define which tables should be cleaned.
 *     'tables_to_clean' => [
 *         'message',
 *         'edited_message',
 *     ],
 *     // Define how old cleaned entries should be.
 *     'clean_older_than' => [
 *         'message'        => '7 days',
 *         'edited_message' => '30 days',
 *     ]
 * );
 */
class CleanupCommand extends AdminCommand
{
    /**
     * @var string
     */
    protected $name = 'cleanup';

    /**
     * @var string
     */
    protected $description = 'Clean up the database from old records';

    /**
     * @var string
     */
    protected $usage = '/cleanup [dry] <days> or /cleanup [dry] <count> <unit> (e.g. 3 weeks)';

    /**
     * @var string
     */
    protected $version = '1.1.0';

    /**
     * @var bool
     */
    protected $need_mysql = true;

    /**
     * Default tables to clean, cleaning 'chat', 'user' and 'user_chat' by default is bad practice!
     *
     * @var array
     */
    protected static array $default_tables_to_clean = [
        'callback_query',
        'chosen_inline_result',
        'conversation',
        'edited_message',
        'inline_query',
        'message',
        'request_limiter',
        'telegram_update',
    ];

    /**
     * By default, remove records older than X days/hours/anything from these tables.
     *
     * @var array
     */
    protected static array $default_clean_older_than = [
        'callback_query'       => '30 days',
        'chat'                 => '365 days',
        'chosen_inline_result' => '30 days',
        'conversation'         => '90 days',
        'edited_message'       => '30 days',
        'inline_query'         => '30 days',
        'message'              => '30 days',
        'poll'                 => '90 days',
        'request_limiter'      => '1 minute',
        'shipping_query'       => '90 days',
        'telegram_update'      => '30 days',
        'user'                 => '365 days',
        'user_chat'            => '365 days',
    ];

    /**
     * Set command config
     *
     * @param string $custom_time
     *
     * @return array
     */
    private function getSettings(string $custom_time = ''): array
    {
        $tables_to_clean      = self::$default_tables_to_clean;
        $user_tables_to_clean = $this->getConfig('tables_to_clean');
        if (is_array($user_tables_to_clean)) {
            $tables_to_clean = $user_tables_to_clean;
        }

        $clean_older_than      = self::$default_clean_older_than;
        $user_clean_older_than = $this->getConfig('clean_older_than');
        if (is_array($user_clean_older_than)) {
            $clean_older_than = array_merge($clean_older_than, $user_clean_older_than);
        }

        // Convert numeric-only values to days.
        array_walk($clean_older_than, function (&$time) use ($custom_time) {
            if (!empty($custom_time)) {
                $time = $custom_time;
            }
            if (is_numeric($time)) {
                $time .= ' days';
            }
        });

        return compact('tables_to_clean', 'clean_older_than');
    }

    /**
     * Get SQL queries array based on settings provided
     *
     * @param $settings
     *
     * @return array
     * @throws TelegramException
     */
    private function getQueries($settings): array
    {
        if (empty($settings) || !is_array($settings)) {
            throw new TelegramException('Settings variable is not an array or is empty!');
        }

        // Convert all clean_older_than times to correct format.
        $clean_older_than = $settings['clean_older_than'];
        foreach ($clean_older_than as $table => $time) {
            $clean_older_than[$table] = date('Y-m-d H:i:s', strtotime('-' . $time));
        }
        $tables_to_clean = $settings['tables_to_clean'];

        $queries = [];

        if (in_array('telegram_update', $tables_to_clean, true)) {
            $queries[] = sprintf(
                'DELETE FROM `%3$s`
                WHERE id IN (
                    SELECT id FROM (
                        SELECT id FROM `%3$s`
                        WHERE `id` != \'%1$s\'
                        AND `chat_id` NOT IN (
                          SELECT `id`
                          FROM `%4$s`
                          WHERE `%3$s`.`chat_id` = `id`
                          AND `updated_at` < \'%2$s\'
                        )
                        AND (
                          `message_id` IS NOT NULL
                          AND `message_id` IN (
                            SELECT `id`
                            FROM `%5$s`
                            WHERE `date` < \'%2$s\'
                          )
                        )
                        OR (
                          `channel_post_id` IS NOT NULL
                          AND `channel_post_id` IN (
                            SELECT `id`
                            FROM `%5$s`
                            WHERE `date` < \'%2$s\'
                          )
                        )
                        OR (
                          `edited_message_id` IS NOT NULL
                          AND `edited_message_id` IN (
                            SELECT `id`
                            FROM `%6$s`
                            WHERE `edit_date` < \'%2$s\'
                          )
                        )
                        OR (
                          `edited_channel_post_id` IS NOT NULL
                          AND `edited_channel_post_id` IN (
                            SELECT `id`
                            FROM `%6$s`
                            WHERE `edit_date` < \'%2$s\'
                          )
                        )
                        OR (
                          `inline_query_id` IS NOT NULL
                          AND `inline_query_id` IN (
                            SELECT `id`
                            FROM `%7$s`
                            WHERE `created_at` < \'%2$s\'
                          )
                        )
                        OR (
                          `chosen_inline_result_id` IS NOT NULL
                          AND `chosen_inline_result_id` IN (
                            SELECT `id`
                            FROM `%8$s`
                            WHERE `created_at` < \'%2$s\'
                          )
                        )
                        OR (
                          `callback_query_id` IS NOT NULL
                          AND `callback_query_id` IN (
                            SELECT `id`
                            FROM `%9$s`
                            WHERE `created_at` < \'%2$s\'
                          )
                        )
                    ) a
                )
            ',
                $this->getUpdate()->getUpdateId(),
                $clean_older_than['telegram_update'],
                TB_TELEGRAM_UPDATE,
                TB_CHAT,
                TB_MESSAGE,
                TB_EDITED_MESSAGE,
                TB_INLINE_QUERY,
                TB_CHOSEN_INLINE_RESULT,
                TB_CALLBACK_QUERY
            );
        }

        if (in_array('user_chat', $tables_to_clean, true)) {
            $queries[] = sprintf(
                'DELETE FROM `%1$s`
                WHERE `user_id` IN (
                  SELECT `id`
                  FROM `%2$s`
                  WHERE `updated_at` < \'%3$s\'
                )
            ',
                TB_USER_CHAT,
                TB_USER,
                $clean_older_than['chat']
            );
        }

        // Simple.
        $simple_tables = [
            'user'            => ['table' => TB_USER, 'field' => 'updated_at'],
            'chat'            => ['table' => TB_CHAT, 'field' => 'updated_at'],
            'conversation'    => ['table' => TB_CONVERSATION, 'field' => 'updated_at'],
            'poll'            => ['table' => TB_POLL, 'field' => 'created_at'],
            'request_limiter' => ['table' => TB_REQUEST_LIMITER, 'field' => 'created_at'],
            'shipping_query'  => ['table' => TB_SHIPPING_QUERY, 'field' => 'created_at'],
        ];

        foreach (array_intersect(array_keys($simple_tables), $tables_to_clean) as $table_to_clean) {
            $queries[] = sprintf(
                'DELETE FROM `%1$s`
                WHERE `%2$s` < \'%3$s\'
            ',
                $simple_tables[$table_to_clean]['table'],
                $simple_tables[$table_to_clean]['field'],
                $clean_older_than[$table_to_clean]
            );
        }

        // Queries.
        $query_tables = [
            'inline_query'         => ['table' => TB_INLINE_QUERY, 'field' => 'created_at'],
            'chosen_inline_result' => ['table' => TB_CHOSEN_INLINE_RESULT, 'field' => 'created_at'],
            'callback_query'       => ['table' => TB_CALLBACK_QUERY, 'field' => 'created_at'],
        ];
        foreach (array_intersect(array_keys($query_tables), $tables_to_clean) as $table_to_clean) {
            $queries[] = sprintf(
                'DELETE FROM `%1$s`
                WHERE `%2$s` < \'%3$s\'
                  AND `id` NOT IN (
                    SELECT `%4$s`
                    FROM `%5$s`
                    WHERE `%4$s` = `%1$s`.`id`
                  )
            ',
                $query_tables[$table_to_clean]['table'],
                $query_tables[$table_to_clean]['field'],
                $clean_older_than[$table_to_clean],
                $table_to_clean . '_id',
                TB_TELEGRAM_UPDATE
            );
        }

        // Messages
        if (in_array('edited_message', $tables_to_clean, true)) {
            $queries[] = sprintf(
                'DELETE FROM `%1$s`
                WHERE `edit_date` < \'%2$s\'
                  AND `id` NOT IN (
                    SELECT `message_id`
                    FROM `%3$s`
                    WHERE `edited_message_id` = `%1$s`.`id`
                  )
            ',
                TB_EDITED_MESSAGE,
                $clean_older_than['edited_message'],
                TB_TELEGRAM_UPDATE
            );
        }

        if (in_array('message', $tables_to_clean, true)) {
            $queries[] = sprintf(
                'DELETE FROM `%1$s`
                WHERE id IN (
                    SELECT id
                    FROM (
                        SELECT id
                        FROM  `%1$s`
                        WHERE `date` < \'%2$s\'
                          AND `id` NOT IN (
                            SELECT `message_id`
                            FROM `%3$s`
                            WHERE `message_id` = `%1$s`.`id`
                          )
                          AND `id` NOT IN (
                            SELECT `message_id`
                            FROM `%4$s`
                            WHERE `message_id` = `%1$s`.`id`
                          )
                          AND `id` NOT IN (
                            SELECT `message_id`
                            FROM `%5$s`
                            WHERE `message_id` = `%1$s`.`id`
                          )
                          AND `id` NOT IN (
                            SELECT a.`reply_to_message` FROM `%1$s` a
                            INNER JOIN `%1$s` b ON b.`id` = a.`reply_to_message` AND b.`chat_id` = a.`reply_to_chat`
                          )
                        ORDER BY `id` DESC
                     ) a
                 )
            ',
                TB_MESSAGE,
                $clean_older_than['message'],
                TB_EDITED_MESSAGE,
                TB_TELEGRAM_UPDATE,
                TB_CALLBACK_QUERY
            );
        }

        return $queries;
    }

    /**
     * Execution if MySQL is required but not available
     *
     * @return ServerResponse
     * @throws TelegramException
     */
    public function executeNoDb(): ServerResponse
    {
        return $this->replyToChat('*No database connection!*', ['parse_mode' => 'Markdown']);
    }

    /**
     * Command execute method
     *
     * @return ServerResponse
     * @throws TelegramException
     */
    public function execute(): ServerResponse
    {
        $message = $this->getMessage() ?: $this->getEditedMessage() ?: $this->getChannelPost() ?: $this->getEditedChannelPost();
        $text    = $message->getText(true);

        // Dry run?
        $dry_run = str_contains($text, 'dry');
        $text    = trim(str_replace('dry', '', $text));

        $settings = $this->getSettings($text);
        $queries  = $this->getQueries($settings);

        if ($dry_run) {
            return $this->replyToUser('Queries:' . PHP_EOL . implode(PHP_EOL, $queries));
        }

        $infos = [];
        foreach ($settings['tables_to_clean'] as $table) {
            $info = "*{$table}*";

            if (isset($settings['clean_older_than'][$table])) {
                $info .= " ({$settings['clean_older_than'][$table]})";
            }

            $infos[] = $info;
        }

        $data = [
            'chat_id'    => $message->getFrom()->getId(),
            'parse_mode' => 'Markdown',
        ];

        $data['text'] = 'Cleaning up tables:' . PHP_EOL . implode(PHP_EOL, $infos);
        Request::sendMessage($data);

        $rows = 0;
        $pdo  = DB::getPdo();
        try {
            $pdo->beginTransaction();

            foreach ($queries as $query) {
                // Delete in chunks to not block / improve speed on big tables.
                $query .= ' LIMIT 10000';
                while ($dbq = $pdo->query($query)) {
                    if ($dbq->rowCount() === 0) {
                        continue 2;
                    }
                    $rows += $dbq->rowCount();
                }

                TelegramLog::error('Error while executing query: ' . $query);
            }

            // commit changes to the database and end transaction
            $pdo->commit();

            $data['text'] = "*Database cleanup done!* _(removed {$rows} rows)_";
        } catch (PDOException $e) {
            $data['text'] = '*Database cleanup failed!* _(check your error logs)_';

            // rollback changes on exception
            // useful if you want to track down error you can't replicate it when some of the data is already deleted
            $pdo->rollBack();

            TelegramLog::error($e->getMessage());
        }

        return Request::sendMessage($data);
    }
}
