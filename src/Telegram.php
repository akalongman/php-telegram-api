<?php

/**
 * This file is part of the TelegramBot package.
 *
 * (c) Avtandil Kikabidze aka LONGMAN <akalongman@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Longman\TelegramBot;

defined('TB_BASE_PATH') || define('TB_BASE_PATH', __DIR__);
defined('TB_BASE_COMMANDS_PATH') || define('TB_BASE_COMMANDS_PATH', TB_BASE_PATH . '/Commands');

use Exception;
use InvalidArgumentException;
use Longman\TelegramBot\Commands\AdminCommand;
use Longman\TelegramBot\Commands\Command;
use Longman\TelegramBot\Commands\SystemCommand;
use Longman\TelegramBot\Commands\UserCommand;
use Longman\TelegramBot\Entities\Chat;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Entities\Update;
use Longman\TelegramBot\Entities\User;
use Longman\TelegramBot\Exception\TelegramException;
use PDO;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RegexIterator;

class Telegram
{
    /**
     * Version
     *
     * @var string
     */
    protected $version = '0.83.0';

    /**
     * Telegram API key
     *
     * @var string
     */
    protected $api_key = '';

    /**
     * Telegram Bot username
     *
     * @var string
     */
    protected $bot_username = '';

    /**
     * Telegram Bot id
     *
     * @var int
     */
    protected $bot_id = 0;

    /**
     * Raw request data (json) for webhook methods
     *
     * @var string
     */
    protected $input = '';

    /**
     * Custom commands paths
     *
     * @var array
     */
    protected $commands_paths = [];

    /**
     * Custom command class names
     * ```
     * [
     *     'User' => [
     *         // command_name => command_class
     *         'start' => 'name\space\to\StartCommand',
     *     ],
     *     'Admin' => [], //etc
     * ]
     * ```
     *
     * @var array
     */
    protected $command_classes = [
        Command::AUTH_USER   => [],
        Command::AUTH_ADMIN  => [],
        Command::AUTH_SYSTEM => [],
    ];

    /**
     * Custom commands objects
     *
     * @var array
     */
    protected $commands_objects = [];

    /**
     * Current Update object
     *
     * @var Update
     */
    protected $update;

    /**
     * Upload path
     *
     * @var string
     */
    protected $upload_path = '';

    /**
     * Download path
     *
     * @var string
     */
    protected $download_path = '';

    /**
     * MySQL integration
     *
     * @var bool
     */
    protected $mysql_enabled = false;

    /**
     * PDO object
     *
     * @var PDO
     */
    protected $pdo;

    /**
     * Commands config
     *
     * @var array
     */
    protected $commands_config = [];

    /**
     * Admins list
     *
     * @var array
     */
    protected $admins_list = [];

    /**
     * ServerResponse of the last Command execution
     *
     * @var ServerResponse
     */
    protected $last_command_response;

    /**
     * Check if runCommands() is running in this session
     *
     * @var bool
     */
    protected $run_commands = false;

    /**
     * Is running getUpdates without DB enabled
     *
     * @var bool
     */
    protected $getupdates_without_database = false;

    /**
     * Last update ID
     * Only used when running getUpdates without a database
     *
     * @var int
     */
    protected $last_update_id;

    /**
     * The command to be executed when there's a new message update and nothing more suitable is found
     */
    public const GENERIC_MESSAGE_COMMAND = 'genericmessage';

    /**
     * The command to be executed by default (when no other relevant commands are applicable)
     */
    public const GENERIC_COMMAND = 'generic';

    /**
     * Update filter method
     *
     * @var callable
     */
    protected $update_filter;

    /**
     * Telegram constructor.
     *
     * @param string $api_key
     * @param string $bot_username
     *
     * @throws TelegramException
     */
    public function __construct(string $api_key, string $bot_username = '')
    {
        if (empty($api_key)) {
            throw new TelegramException('API KEY not defined!');
        }
        preg_match('/(\d+):[\w\-]+/', $api_key, $matches);
        if (!isset($matches[1])) {
            throw new TelegramException('Invalid API KEY defined!');
        }
        $this->bot_id  = (int) $matches[1];
        $this->api_key = $api_key;

        $this->bot_username = $bot_username;

        //Add default system commands path
        $this->addCommandsPath(TB_BASE_COMMANDS_PATH . '/SystemCommands');

        Request::initialize($this);
    }

    /**
     * Initialize Database connection
     *
     * @param array  $credentials
     * @param string $table_prefix
     * @param string $encoding
     *
     * @return Telegram
     * @throws TelegramException
     */
    public function enableMySql(array $credentials, string $table_prefix = '', string $encoding = 'utf8mb4'): Telegram
    {
        $this->pdo = DB::initialize($credentials, $this, $table_prefix, $encoding);
        ConversationDB::initializeConversation();
        $this->mysql_enabled = true;

        return $this;
    }

    /**
     * Initialize Database external connection
     *
     * @param PDO    $external_pdo_connection PDO database object
     * @param string $table_prefix
     *
     * @return Telegram
     * @throws TelegramException
     */
    public function enableExternalMySql(PDO $external_pdo_connection, string $table_prefix = ''): Telegram
    {
        $this->pdo = DB::externalInitialize($external_pdo_connection, $this, $table_prefix);
        ConversationDB::initializeConversation();
        $this->mysql_enabled = true;

        return $this;
    }

    /**
     * Get commands list
     *
     * @return array $commands
     * @throws TelegramException
     */
    public function getCommandsList(): array
    {
        $commands = [];

        foreach ($this->commands_paths as $path) {
            try {
                //Get all "*Command.php" files
                $files = new RegexIterator(
                    new RecursiveIteratorIterator(
                        new RecursiveDirectoryIterator($path)
                    ),
                    '/^.+Command.php$/'
                );

                foreach ($files as $file) {
                    // Convert filename to command
                    $command = $this->classNameToCommandName(substr($file->getFilename(), 0, -4));

                    // Invalid Classname
                    if (is_null($command)) {
                        continue;
                    }

                    // Already registered
                    if (array_key_exists($command, $commands)) {
                        continue;
                    }

                    require_once $file->getPathname();

                    $command_obj = $this->getCommandObject($command, $file->getPathname());
                    if ($command_obj instanceof Command) {
                        $commands[$command] = $command_obj;
                    }
                }
            } catch (Exception $e) {
                throw new TelegramException('Error getting commands from path: ' . $path, 0, $e);
            }
        }

        return $commands;
    }

    /**
     * Get classname of predefined commands
     *
     * @see command_classes
     *
     * @param string $auth     Auth of command
     * @param string $command  Command name
     * @param string $filepath Path to the command file
     *
     * @return string|null
     */
    public function getCommandClassName(string $auth, string $command, string $filepath = ''): ?string
    {
        $command = mb_strtolower($command);

        // Invalid command
        if (trim($command) === '') {
            return null;
        }

        $auth    = $this->ucFirstUnicode($auth);

        // First, check for directly assigned command class.
        if ($command_class = $this->command_classes[$auth][$command] ?? null) {
            return $command_class;
        }

        // Start with default namespace.
        $command_namespace = __NAMESPACE__ . '\\Commands\\' . $auth . 'Commands';

        // Check if we can get the namespace from the file (if passed).
        if ($filepath && !($command_namespace = $this->getFileNamespace($filepath))) {
            return null;
        }

        $command_class = $command_namespace . '\\' . $this->commandNameToClassName($command);

        if (class_exists($command_class, false)) {
            return $command_class;
        }

        return null;
    }

    /**
     * Get an object instance of the passed command
     *
     * @param string $command
     * @param string $filepath
     *
     * @return Command|null
     */
    public function getCommandObject(string $command, string $filepath = ''): ?Command
    {
        if (isset($this->commands_objects[$command])) {
            return $this->commands_objects[$command];
        }

        $which = [Command::AUTH_SYSTEM];
        $this->isAdmin() && $which[] = Command::AUTH_ADMIN;
        $which[] = Command::AUTH_USER;

        foreach ($which as $auth) {
            $command_class = $this->getCommandClassName($auth, $command, $filepath);

            if ($command_class) {
                $command_obj = new $command_class($this, $this->update);

                if ($auth === Command::AUTH_SYSTEM && $command_obj instanceof SystemCommand) {
                    return $command_obj;
                }
                if ($auth === Command::AUTH_ADMIN && $command_obj instanceof AdminCommand) {
                    return $command_obj;
                }
                if ($auth === Command::AUTH_USER && $command_obj instanceof UserCommand) {
                    return $command_obj;
                }
            }
        }

        return null;
    }

    /**
     * Get namespace from php file by src path
     *
     * @param string $src (absolute path to file)
     *
     * @return string|null ("Longman\TelegramBot\Commands\SystemCommands" for example)
     */
    protected function getFileNamespace(string $src): ?string
    {
        $content = file_get_contents($src);
        if (preg_match('#^\s*namespace\s+(.+?);#m', $content, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * Set custom input string for debug purposes
     *
     * @param string $input (json format)
     *
     * @return Telegram
     */
    public function setCustomInput(string $input): Telegram
    {
        $this->input = $input;

        return $this;
    }

    /**
     * Get custom input string for debug purposes
     *
     * @return string
     */
    public function getCustomInput(): string
    {
        return $this->input;
    }

    /**
     * Get the ServerResponse of the last Command execution
     *
     * @return ServerResponse
     */
    public function getLastCommandResponse(): ServerResponse
    {
        return $this->last_command_response;
    }

    /**
     * Handle getUpdates method
     *
     * @todo Remove backwards compatibility for old signature and force $data to be an array.
     *
     * @param array|int|null $data
     * @param int|null       $timeout
     *
     * @return ServerResponse
     * @throws TelegramException
     */
    public function handleGetUpdates($data = null, ?int $timeout = null): ServerResponse
    {
        if (empty($this->bot_username)) {
            throw new TelegramException('Bot Username is not defined!');
        }

        if (!DB::isDbConnected() && !$this->getupdates_without_database) {
            return new ServerResponse(
                [
                    'ok'          => false,
                    'description' => 'getUpdates needs MySQL connection! (This can be overridden - see documentation)',
                ],
                $this->bot_username
            );
        }

        $offset = 0;
        $limit  = null;

        // By default, get update types sent by Telegram.
        $allowed_updates = [];

        // @todo Backwards compatibility for old signature, remove in next version.
        if (!is_array($data)) {
            $limit = $data;

            @trigger_error(
                sprintf('Use of $limit and $timeout parameters in %s is deprecated. Use $data array instead.', __METHOD__),
                E_USER_DEPRECATED
            );
        } else {
            $offset          = $data['offset'] ?? $offset;
            $limit           = $data['limit'] ?? $limit;
            $timeout         = $data['timeout'] ?? $timeout;
            $allowed_updates = $data['allowed_updates'] ?? $allowed_updates;
        }

        // Take custom input into account.
        if ($custom_input = $this->getCustomInput()) {
            try {
                $input = json_decode($this->input, true, 512, JSON_THROW_ON_ERROR);
                if (empty($input)) {
                    throw new TelegramException('Custom input is empty');
                }
                $response = new ServerResponse($input, $this->bot_username);
            } catch (\Throwable $e) {
                throw new TelegramException('Invalid custom input JSON: ' . $e->getMessage());
            }
        } else {
            if (DB::isDbConnected() && $last_update = DB::selectTelegramUpdate(1)) {
                // Get last Update id from the database.
                $last_update          = reset($last_update);
                $this->last_update_id = $last_update['id'] ?? null;
            }

            if ($this->last_update_id !== null) {
                $offset = $this->last_update_id + 1; // As explained in the telegram bot API documentation.
            }

            $response = Request::getUpdates(compact('offset', 'limit', 'timeout', 'allowed_updates'));
        }

        if ($response->isOk()) {
            // Log update.
            TelegramLog::update($response->toJson());

            // Process all updates
            /** @var Update $update */
            foreach ($response->getResult() as $update) {
                $this->processUpdate($update);
            }

            if (!DB::isDbConnected() && !$custom_input && $this->last_update_id !== null && $offset === 0) {
                // Mark update(s) as read after handling
                $offset = $this->last_update_id + 1;
                $limit  = 1;

                Request::getUpdates(compact('offset', 'limit', 'timeout', 'allowed_updates'));
            }
        }

        return $response;
    }

    /**
     * Handle bot request from webhook
     *
     * @return bool
     *
     * @throws TelegramException
     */
    public function handle(): bool
    {
        if ($this->bot_username === '') {
            throw new TelegramException('Bot Username is not defined!');
        }

        $input = Request::getInput();
        if (empty($input)) {
            throw new TelegramException('Input is empty! The webhook must not be called manually, only by Telegram.');
        }

        // Log update.
        TelegramLog::update($input);

        $post = json_decode($input, true);
        if (empty($post)) {
            throw new TelegramException('Invalid input JSON! The webhook must not be called manually, only by Telegram.');
        }

        if ($response = $this->processUpdate(new Update($post, $this->bot_username))) {
            return $response->isOk();
        }

        return false;
    }

    /**
     * Get the command name from the command type
     *
     * @param string $type
     *
     * @return string
     */
    protected function getCommandFromType(string $type): string
    {
        return $this->ucFirstUnicode(str_replace('_', '', $type));
    }

    /**
     * Process bot Update request
     *
     * @param Update $update
     *
     * @return ServerResponse
     * @throws TelegramException
     */
    public function processUpdate(Update $update): ServerResponse
    {
        $this->update         = $update;
        $this->last_update_id = $update->getUpdateId();

        if (is_callable($this->update_filter)) {
            $reason = 'Update denied by update_filter';
            try {
                $allowed = (bool) call_user_func_array($this->update_filter, [$update, $this, &$reason]);
            } catch (Exception $e) {
                $allowed = false;
            }

            if (!$allowed) {
                TelegramLog::debug($reason);
                return new ServerResponse(['ok' => false, 'description' => 'denied']);
            }
        }

        //Load admin commands
        if ($this->isAdmin()) {
            $this->addCommandsPath(TB_BASE_COMMANDS_PATH . '/AdminCommands', false);
        }

        //Make sure we have an up-to-date command list
        //This is necessary to "require" all the necessary command files!
        $this->commands_objects = $this->getCommandsList();

        //If all else fails, it's a generic message.
        $command = self::GENERIC_MESSAGE_COMMAND;

        $update_type = $this->update->getUpdateType();
        if ($update_type === 'message') {
            $message = $this->update->getMessage();
            $type    = $message->getType();

            // Let's check if the message object has the type field we're looking for...
            $command_tmp = $type === 'command' ? $message->getCommand() : $this->getCommandFromType($type);
            // ...and if a fitting command class is available.
            $command_obj = $command_tmp ? $this->getCommandObject($command_tmp) : null;

            // Empty usage string denotes a non-executable command.
            // @see https://github.com/php-telegram-bot/core/issues/772#issuecomment-388616072
            if (
                ($command_obj === null && $type === 'command')
                || ($command_obj !== null && $command_obj->getUsage() !== '')
            ) {
                $command = $command_tmp;
            }
        } elseif ($update_type !== null) {
            $command = $this->getCommandFromType($update_type);
        }

        //Make sure we don't try to process update that was already processed
        $last_id = DB::selectTelegramUpdate(1, $this->update->getUpdateId());
        if ($last_id && count($last_id) === 1) {
            TelegramLog::debug('Duplicate update received, processing aborted!');
            return Request::emptyResponse();
        }

        DB::insertRequest($this->update);

        return $this->executeCommand($command);
    }

    /**
     * Execute /command
     *
     * @param string $command
     *
     * @return ServerResponse
     * @throws TelegramException
     */
    public function executeCommand(string $command): ServerResponse
    {
        $command = mb_strtolower($command);

        $command_obj = $this->commands_objects[$command] ?? $this->getCommandObject($command);

        if (!$command_obj || !$command_obj->isEnabled()) {
            //Failsafe in case the Generic command can't be found
            if ($command === self::GENERIC_COMMAND) {
                throw new TelegramException('Generic command missing!');
            }

            //Handle a generic command or non existing one
            $this->last_command_response = $this->executeCommand(self::GENERIC_COMMAND);
        } else {
            //execute() method is executed after preExecute()
            //This is to prevent executing a DB query without a valid connection
            if ($this->update) {
                $this->last_command_response = $command_obj->setUpdate($this->update)->preExecute();
            } else {
                $this->last_command_response = $command_obj->preExecute();
            }
        }

        return $this->last_command_response;
    }

    /**
     * @deprecated
     *
     * @param string $command
     *
     * @return string
     */
    protected function sanitizeCommand(string $command): string
    {
        return str_replace(' ', '', $this->ucWordsUnicode(str_replace('_', ' ', $command)));
    }

    /**
     * Enable a single Admin account
     *
     * @param int $admin_id Single admin id
     *
     * @return Telegram
     */
    public function enableAdmin(int $admin_id): Telegram
    {
        if ($admin_id <= 0) {
            TelegramLog::error('Invalid value "' . $admin_id . '" for admin.');
        } elseif (!in_array($admin_id, $this->admins_list, true)) {
            $this->admins_list[] = $admin_id;
        }

        return $this;
    }

    /**
     * Enable a list of Admin Accounts
     *
     * @param array $admin_ids List of admin ids
     *
     * @return Telegram
     */
    public function enableAdmins(array $admin_ids): Telegram
    {
        foreach ($admin_ids as $admin_id) {
            $this->enableAdmin($admin_id);
        }

        return $this;
    }

    /**
     * Get list of admins
     *
     * @return array
     */
    public function getAdminList(): array
    {
        return $this->admins_list;
    }

    /**
     * Check if the passed user is an admin
     *
     * If no user id is passed, the current update is checked for a valid message sender.
     *
     * @param int|null $user_id
     *
     * @return bool
     */
    public function isAdmin($user_id = null): bool
    {
        if ($user_id === null && $this->update !== null) {
            //Try to figure out if the user is an admin
            $update_methods = [
                'getMessage',
                'getEditedMessage',
                'getChannelPost',
                'getEditedChannelPost',
                'getInlineQuery',
                'getChosenInlineResult',
                'getCallbackQuery',
            ];
            foreach ($update_methods as $update_method) {
                $object = call_user_func([$this->update, $update_method]);
                if ($object !== null && $from = $object->getFrom()) {
                    $user_id = $from->getId();
                    break;
                }
            }
        }

        return ($user_id === null) ? false : in_array($user_id, $this->admins_list, true);
    }

    /**
     * Check if user required the db connection
     *
     * @return bool
     */
    public function isDbEnabled(): bool
    {
        return $this->mysql_enabled;
    }

    /**
     * Add a single custom command class
     *
     * @param string $command_class Full command class name
     *
     * @return Telegram
     */
    public function addCommandClass(string $command_class): Telegram
    {
        if (!$command_class || !class_exists($command_class)) {
            $error = sprintf('Command class "%s" does not exist.', $command_class);
            TelegramLog::error($error);
            throw new InvalidArgumentException($error);
        }

        if (!is_a($command_class, Command::class, true)) {
            $error = sprintf('Command class "%s" does not extend "%s".', $command_class, Command::class);
            TelegramLog::error($error);
            throw new InvalidArgumentException($error);
        }

        // Dummy object to get data from.
        $command_object = new $command_class($this);

        $auth = null;
        $command_object->isSystemCommand() && $auth = Command::AUTH_SYSTEM;
        $command_object->isAdminCommand() && $auth = Command::AUTH_ADMIN;
        $command_object->isUserCommand() && $auth = Command::AUTH_USER;

        if ($auth) {
            $command = mb_strtolower($command_object->getName());

            $this->command_classes[$auth][$command] = $command_class;
        }

        return $this;
    }

    /**
     * Add multiple custom command classes
     *
     * @param array $command_classes List of full command class names
     *
     * @return Telegram
     */
    public function addCommandClasses(array $command_classes): Telegram
    {
        foreach ($command_classes as $command_class) {
            $this->addCommandClass($command_class);
        }

        return $this;
    }

    /**
     * Set a single custom commands path
     *
     * @param string $path Custom commands path to set
     *
     * @return Telegram
     */
    public function setCommandsPath(string $path): Telegram
    {
        $this->commands_paths = [];

        $this->addCommandsPath($path);

        return $this;
    }

    /**
     * Add a single custom commands path
     *
     * @param string $path   Custom commands path to add
     * @param bool   $before If the path should be prepended or appended to the list
     *
     * @return Telegram
     */
    public function addCommandsPath(string $path, bool $before = true): Telegram
    {
        if (!is_dir($path)) {
            TelegramLog::error('Commands path "' . $path . '" does not exist.');
        } elseif (!in_array($path, $this->commands_paths, true)) {
            if ($before) {
                array_unshift($this->commands_paths, $path);
            } else {
                $this->commands_paths[] = $path;
            }
        }

        return $this;
    }

    /**
     * Set multiple custom commands paths
     *
     * @param array $paths Custom commands paths to add
     *
     * @return Telegram
     */
    public function setCommandsPaths(array $paths): Telegram
    {
        $this->commands_paths = [];

        $this->addCommandsPaths($paths);

        return $this;
    }

    /**
     * Add multiple custom commands paths
     *
     * @param array $paths  Custom commands paths to add
     * @param bool  $before If the paths should be prepended or appended to the list
     *
     * @return Telegram
     */
    public function addCommandsPaths(array $paths, bool $before = true): Telegram
    {
        foreach ($paths as $path) {
            $this->addCommandsPath($path, $before);
        }

        return $this;
    }

    /**
     * Return the list of commands paths
     *
     * @return array
     */
    public function getCommandsPaths(): array
    {
        return $this->commands_paths;
    }

    /**
     * Return the list of command classes
     *
     * @return array
     */
    public function getCommandClasses(): array
    {
        return $this->command_classes;
    }

    /**
     * Set custom upload path
     *
     * @param string $path Custom upload path
     *
     * @return Telegram
     */
    public function setUploadPath(string $path): Telegram
    {
        $this->upload_path = $path;

        return $this;
    }

    /**
     * Get custom upload path
     *
     * @return string
     */
    public function getUploadPath(): string
    {
        return $this->upload_path;
    }

    /**
     * Set custom download path
     *
     * @param string $path Custom download path
     *
     * @return Telegram
     */
    public function setDownloadPath(string $path): Telegram
    {
        $this->download_path = $path;

        return $this;
    }

    /**
     * Get custom download path
     *
     * @return string
     */
    public function getDownloadPath(): string
    {
        return $this->download_path;
    }

    /**
     * Set command config
     *
     * Provide further variables to a particular commands.
     * For example you can add the channel name at the command /sendtochannel
     * Or you can add the api key for external service.
     *
     * @param string $command
     * @param array  $config
     *
     * @return Telegram
     */
    public function setCommandConfig(string $command, array $config): Telegram
    {
        $this->commands_config[$command] = $config;

        return $this;
    }

    /**
     * Get command config
     *
     * @param string $command
     *
     * @return array
     */
    public function getCommandConfig(string $command): array
    {
        return $this->commands_config[$command] ?? [];
    }

    /**
     * Get API key
     *
     * @return string
     */
    public function getApiKey(): string
    {
        return $this->api_key;
    }

    /**
     * Get Bot name
     *
     * @return string
     */
    public function getBotUsername(): string
    {
        return $this->bot_username;
    }

    /**
     * Get Bot Id
     *
     * @return int
     */
    public function getBotId(): int
    {
        return $this->bot_id;
    }

    /**
     * Get Version
     *
     * @return string
     */
    public function getVersion(): string
    {
        return $this->version;
    }

    /**
     * Set Webhook for bot
     *
     * @param string $url
     * @param array  $data Optional parameters.
     *
     * @return ServerResponse
     * @throws TelegramException
     */
    public function setWebhook(string $url, array $data = []): ServerResponse
    {
        if ($url === '') {
            throw new TelegramException('Hook url is empty!');
        }

        $data        = array_intersect_key($data, array_flip([
            'certificate',
            'ip_address',
            'max_connections',
            'allowed_updates',
            'drop_pending_updates',
            'secret_token',
        ]));
        $data['url'] = $url;

        // If the certificate is passed as a path, encode and add the file to the data array.
        if (!empty($data['certificate']) && is_string($data['certificate'])) {
            $data['certificate'] = Request::encodeFile($data['certificate']);
        }

        $result = Request::setWebhook($data);

        if (!$result->isOk()) {
            throw new TelegramException(
                'Webhook was not set! Error: ' . $result->getErrorCode() . ' ' . $result->getDescription()
            );
        }

        return $result;
    }

    /**
     * Delete any assigned webhook
     *
     * @param array $data
     *
     * @return ServerResponse
     * @throws TelegramException
     */
    public function deleteWebhook(array $data = []): ServerResponse
    {
        $result = Request::deleteWebhook($data);

        if (!$result->isOk()) {
            throw new TelegramException(
                'Webhook was not deleted! Error: ' . $result->getErrorCode() . ' ' . $result->getDescription()
            );
        }

        return $result;
    }

    /**
     * Replace function `ucwords` for UTF-8 characters in the class definition and commands
     *
     * @param string $str
     * @param string $encoding (default = 'UTF-8')
     *
     * @return string
     */
    protected function ucWordsUnicode(string $str, string $encoding = 'UTF-8'): string
    {
        return mb_convert_case($str, MB_CASE_TITLE, $encoding);
    }

    /**
     * Replace function `ucfirst` for UTF-8 characters in the class definition and commands
     *
     * @param string $str
     * @param string $encoding (default = 'UTF-8')
     *
     * @return string
     */
    protected function ucFirstUnicode(string $str, string $encoding = 'UTF-8'): string
    {
        return mb_strtoupper(mb_substr($str, 0, 1, $encoding), $encoding)
            . mb_strtolower(mb_substr($str, 1, mb_strlen($str), $encoding), $encoding);
    }

    /**
     * Enable requests limiter
     *
     * @param array $options
     *
     * @return Telegram
     * @throws TelegramException
     */
    public function enableLimiter(array $options = []): Telegram
    {
        Request::setLimiter(true, $options);

        return $this;
    }

    /**
     * Run provided commands
     *
     * @param array $commands
     *
     * @return ServerResponse[]
     *
     * @throws TelegramException
     */
    public function runCommands(array $commands): array
    {
        if (empty($commands)) {
            throw new TelegramException('No command(s) provided!');
        }

        $this->run_commands = true;

        // Check if this request has a user Update / comes from Telegram.
        if ($userUpdate = $this->update) {
            $from = $this->update->getMessage()->getFrom();
            $chat = $this->update->getMessage()->getChat();
        } else {
            // Fall back to the Bot user.
            $from = new User([
                'id'         => $this->getBotId(),
                'first_name' => $this->getBotUsername(),
                'username'   => $this->getBotUsername(),
            ]);

            // Try to get "live" Bot info.
            $response = Request::getMe();
            if ($response->isOk()) {
                /** @var User $result */
                $result = $response->getResult();

                $from = new User([
                    'id'         => $result->getId(),
                    'first_name' => $result->getFirstName(),
                    'username'   => $result->getUsername(),
                ]);
            }

            // Give Bot access to admin commands.
            $this->enableAdmin($from->getId());

            // Lock the bot to a private chat context.
            $chat = new Chat([
                'id'   => $from->getId(),
                'type' => 'private',
            ]);
        }

        $newUpdate = static function ($text = '') use ($from, $chat) {
            return new Update([
                'update_id' => -1,
                'message'   => [
                    'message_id' => -1,
                    'date'       => time(),
                    'from'       => json_decode($from->toJson(), true),
                    'chat'       => json_decode($chat->toJson(), true),
                    'text'       => $text,
                ],
            ]);
        };

        $responses = [];

        foreach ($commands as $command) {
            $this->update = $newUpdate($command);

            // Refresh commands list for new Update object.
            $this->commands_objects = $this->getCommandsList();

            $responses[] = $this->executeCommand($this->update->getMessage()->getCommand());
        }

        // Reset Update to initial context.
        $this->update = $userUpdate;

        return $responses;
    }

    /**
     * Is this session initiated by runCommands()
     *
     * @return bool
     */
    public function isRunCommands(): bool
    {
        return $this->run_commands;
    }

    /**
     * Switch to enable running getUpdates without a database
     *
     * @param bool $enable
     *
     * @return Telegram
     */
    public function useGetUpdatesWithoutDatabase(bool $enable = true): Telegram
    {
        $this->getupdates_without_database = $enable;

        return $this;
    }

    /**
     * Return last update id
     *
     * @return int|null
     */
    public function getLastUpdateId(): ?int
    {
        return $this->last_update_id;
    }

    /**
     * Set an update filter callback
     *
     * @param callable $callback
     *
     * @return Telegram
     */
    public function setUpdateFilter(callable $callback): Telegram
    {
        $this->update_filter = $callback;

        return $this;
    }

    /**
     * Return update filter callback
     *
     * @return callable|null
     */
    public function getUpdateFilter(): ?callable
    {
        return $this->update_filter;
    }

    /**
     * Converts the name of a class into the name of a command.
     *
     * @param string $class For example FooBarCommand
     *
     * @return string|null for example foo_bar. In case of errors, returns null.
     */
    protected function classNameToCommandName(string $class): ?string
    {
        // If $class doesn't end with 'Command'
        if (substr($class, -7) !== 'Command') {
            return null;
        }

        return mb_strtolower(preg_replace('/(.)(?=[\p{Lu}])/u', '$1_', substr($class, 0, -7)));
    }

    /**
     * Converts a command name into the name of a class.
     *
     * @param string $command For example foo_bar
     *
     * @return string|null for example FooBarCommand. In case of errors, returns null.
     */
    protected function commandNameToClassName(string $command): ?string
    {
        if (trim($command) === '') {
            return null;
        }

        return str_replace(' ', '', $this->ucWordsUnicode(str_replace('_', ' ', $command))) . 'Command';
    }
}
