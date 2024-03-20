<?php

declare(strict_types=1);

namespace Supergnaw\PlayfabPhp;

use PDO;
use PDOException;
use stdClass;

// API Endpoints
const ENDPOINT_BASE = 'https://titleId.playfabapi.com';
const ENDPOINT_GET_TITLE_NEWS = '/Client/GetTitleNews';
const ENDPOINT_GET_TITLE_DATA = '/Client/GetTitleData';
const ENDPOINT_GET_PLAYER_STATS = '/Client/GetPlayerStatistics';
const ENDPOINT_GET_USER_CHARS = '/Client/GetAllUsersCharacters';
const ENDPOINT_GET_CHAR_STATS = '/Client/GetCharacterStatistics';
const ENDPOINT_GET_CHAR_DATA = '/Client/GetCharacterData';
const ENDPOINT_GET_USER_DATA = '/Client/GetUserData';
const ENDPOINT_GET_LEADERBOARD = '/Client/GetLeaderboard';
const ENDPOINT_GET_CATALOG_ITEMS = '/Client/GetCatalogItems';
const ENDPOINT_GET_INVENTORY_ITEMS = '/Client/GetUserInventory';
const ENDPOINT_LOGIN_EMAIL = '/Client/LoginWithEmailAddress';
const ENDPOINT_LOGIN_GOOGLE = '/Client/LoginWithGoogleAccount';

// Cache Settings
const PLAYFAB_GO_STALE_NEWS_HOURS = 1;
const PLAYFAB_GO_STALE_HOURS_TITLE_DATA = 168;
const PLAYFAB_CATALOG_GO_STALE_HOURS = 168;
const PLAYFAB_LEADERBOARDS_GO_STALE_HOURS = 24;

// API Limits
const PLAYFAB_CLIENT_2_MIN_LIMIT = 1000;
const PLAYFAB_SERVER_2_MIN_LIMIT = 12000;

class Playfab
{
    /*
        1.0 Variables & Magic Methods
    */

    // generic
    private bool $debug = false;
    public array $debug_data = [];
    private string $response;

    // database properties
    protected array $table_schema = [];

    // application identification
    protected string $app_id;

    // authentication
    protected string|null $playFabId = null;

    // endpoints
    protected string $base_endpoint;
    private string $host;
    private string $name;
    private string $user;
    private string $pass;
    private string $auth;
    private ?PDO $pdo;
    private ?string $login_method = null;


    /**
     * Constructor
     *
     * @param string $app_id
     * @param string $username
     * @param string $email
     * @param string $password
     */
    public function __construct(string $app_id = null)
    {
        $this->instantiate($app_id);
    }

    /**
     * Invoker
     *
     * @param string $app_id
     * @param string $username
     * @param string $email
     * @param string $password
     */
    public function __invoke(string $app_id = null)
    {
        $this->instantiate($app_id);
    }

    /**
     * Destructor
     */
    public function __destruct()
    {
        $this->close();
    }

    /**
     * Common instantiation function
     *
     * @param string $app_id
     * @param string $username
     * @param string $email
     * @param string $password
     * @return void
     */
    protected function instantiate(string $app_id = null): void
    {
        if (session_status() === PHP_SESSION_NONE) session_start();
        $this->set_app_id($app_id);

        $missing = [];
        if (!defined(constant_name: "PLAYFAB_DB_HOST")) $missing[] = 'PLAYFAB_DB_HOST';
        if (!defined(constant_name: "PLAYFAB_DB_NAME")) $missing[] = 'PLAYFAB_DB_NAME';
        if (!defined(constant_name: "PLAYFAB_DB_USER")) $missing[] = 'PLAYFAB_DB_USER';
        if (!defined(constant_name: "PLAYFAB_DB_PASS")) $missing[] = 'PLAYFAB_DB_PASS';

        if (empty($missing)) return;

        die("Could not instantiate Playfab class--missing the following constants: " . implode(separator: ", ", array: $missing));
    }

    /*
        2.0 Variable Implementations
    */

    /**
     * Set app ID
     * Set app identification value
     *
     * @param string $app_id
     * @return void
     */
    protected function set_app_id(string $app_id = null): void
    {
        $this->app_id = $app_id ?? PLAYFAB_APP_ID;
        $this->base_endpoint = str_replace(search: "titleId", replace: $this->app_id, subject: ENDPOINT_BASE);
    }

    /*
        3.0 Database Connections
    */

    /**
     * Connect to the database
     * Checks for an existing database connection and creates a new one on fail
     *
     * @return bool
     */
    protected function connect(): bool
    {
        // existing connection
        if ($this->check_connection()) return true;

        // MySQL Database
        try {
            $this->pdo = new PDO(
                dsn: "mysql:host=" . PLAYFAB_DB_HOST . ";dbname=" . PLAYFAB_DB_NAME,
                username: PLAYFAB_DB_USER,
                password: PLAYFAB_DB_PASS,
                options: [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_PERSISTENT => true,
                    PDO::ATTR_EMULATE_PREPARES => true, // off for :named placeholders
                    PDO::ATTR_STRINGIFY_FETCHES => false,
                ]
            );
        } catch (PDOException $e) {
            throw new PDOException($e->getMessage());
        }

        // successful connection
        return true;
    }

    /**
     * Check database connection
     * Verifies if the database connection exists and is still alive
     *
     * @return bool
     */
    protected function check_connection(): bool
    {
        // no connection
        if (empty($this->pdo)) return false;

        // test existing connection for timeout
        $this->prep("SELECT 1");
        $this->execute();
        $rows = $this->results();

        // check test results
        if (1 === $rows[0]['1'] ?? false) return true;

        // kill dead connection
        $this->close();
        return false;
    }

    /**
     * Close the database connection
     * Yes
     *
     * @return void
     */
    protected function close(): void
    {
        $this->pdo = null;
    }

    /*
        4.0 Query Execution
    */

    /**
     * Execute a query
     * Performs a query against the database
     *
     * @param string $query
     * @param array $params
     * @param bool $close
     * @return bool
     */
    public function sql_exec(string $query, array $params = [], bool $close = false): bool
    {
        // verify query isn't empty
        if (empty(trim($query))) return false;

        // verify parameters
        $params = $this->verify_parameters($query, $params);

        // connect to database
        $this->connect();

        // prepare statement
        $this->prep($query, $params);

        // bind parameters
        foreach ($params as $var => $val) $this->bind(variable: $var, value: $val);

        // execute
        $result = $this->execute();

        // close the connection
        if ($close) $this->close();

        // return result
        return $result;
    }

    /**
     * Verify query parameters
     * Checks the passed parameters against the query to ensure everything is good to go
     *
     * @param string $query
     * @param array $params
     * @return array
     */
    protected function verify_parameters(string $query, array $params = []): array
    {
        foreach ($params as $var => $val) {
            // remove unwanted parameters
            if (!strpos($query, ":{$var}")) unset($params[$var]);
        }
        return $params;
    }

    /**
     * Create prepared statement
     * Converts a string query into a prepared statement
     *
     * @param string $query
     * @param array $params
     * @return bool
     */
    protected function prep(string $query, array $params = []): bool
    {
        $this->stmt = (empty($params))
            ? $this->pdo->prepare($query)
            : $this->pdo->prepare($query, $params);
        return true;
    }

    /**
     * Bind variable
     * Binds a variable to its query parameter
     *
     * @param string $variable
     * @param $value
     * @return bool
     */
    protected function bind(string $variable, $value): bool
    {
        // set bind type
        $type = Playfab::check_variable_type($value);
        if ("array" == $type) return false;

        // backwards compatibility or whatever
        $variable = (!str_starts_with($variable, ':')) ? ":{$variable}" : $variable;

        // bind value to parameter
        return $this->stmt->bindValue($variable, $value, $type);
    }

    /**
     * Check variable type
     * Detects the input variable type for matters regarding binding
     *
     * @param $input
     * @return int|string
     */
    protected static function check_variable_type($input): int|string
    {
        if (is_int($input)) return PDO::PARAM_INT;
        if (is_bool($input)) return PDO::PARAM_BOOL;
        if (is_null($input)) return PDO::PARAM_NULL;
        if (is_array($input)) return "array";
        return PDO::PARAM_STR;
    }

    /**
     * Execute a prepared statement
     * Attempts to execute the prepared statement
     *
     * @return bool
     */
    protected function execute(): bool
    {
        // execute query
        try {
            if (!$this->stmt->execute()) {
                $error = $this->stmt->errorInfo();
                if ($this->debug) {
                    echo "<pre>Statement Info:\n" . $this->stmt->queryString . "</pre>";
                    throw new PDOException("MySQL error {$error[1]}: {$error[2]} ({$error[0]})");
                } else {
                    die("SQL Error: {$error[1]}");
                }
            }
        } catch (PDOException $e) {
            $msg = $e->getMessage();
            if ($this->debug) {
                echo "<pre>Statement Info:\n" . $this->stmt->queryString;
                print_r($this->stmt->debugDumpParams());
                echo "</pre>";
                throw new PDOException("PDO Exception: {$msg}");
            } else {
                die("PDO Exception: {$msg}");
            }
        }
        return true;
    }

    /*
        5.0 Records and Results
    */

    /**
     * Get results
     * Returns the results of an executed query
     *
     * @param bool $first_result_only
     * @return array
     */
    public function results(bool $first_result_only = false): array
    {
        // get result set
        $rows = $this->stmt->fetchAll();
        return true === $first_result_only && !empty($rows) ? $rows[0] : $rows;
    }

    /**
     * Row count
     * Get the affected row count of an executed query
     *
     * @return int
     */
    public function row_count(): int
    {
        // get row count
        return $this->stmt->rowCount();
    }

    /**
     * Last insert ID
     * Get the row id of the last insert from an executed query
     *
     * @return string
     */
    public function last_insert_id(): string
    {
        return $this->pdo->lastInsertId();
    }

    /*
        6.0 Database Schema
    */

    /**
     * Build PlayFab database
     * Creates the core database tables of the class
     *
     * @return void
     */
    public function build_playfab_db(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS `playfab_api_calls` (
                        `call_id` INT PRIMARY KEY auto_increment ,
                        `call_endpoint` VARCHAR(64) ,
                        `call_client` VARCHAR(64) , 
                        `call_time` DATETIME DEFAULT NOW() ,
                        `status_code` VARCHAR(3)
                    ) ENGINE = InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
                    CREATE TABLE IF NOT EXISTS `playfab_news_entries` (
                        `news_id` VARCHAR(36) PRIMARY KEY ,
                        `news_title` VARCHAR(128) ,
                        `news_body` VARCHAR(2048) ,
                        `news_timestamp` TIMESTAMP
                    ) ENGINE = InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
                    CREATE TABLE IF NOT EXISTS `playfab_title_data` (
                        `data_id` VARCHAR(64) PRIMARY KEY ,
                        `data_content` TEXT
                    ) ENGINE = InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;";
        $this->sql_exec($sql);
    }

    /**
     * Check schema
     * Verify the `table`[.`column`] schema in the database
     *
     * @param string $table
     * @param string $column
     * @return bool
     */
    public function valid_schema(string $table, string $column = ''): bool
    {
        if (empty($this->table_schema)) $this->load_table_schema();

        $table = trim(string: $table ?? "");
        $column = trim(string: $column ?? "");

        if (!array_key_exists(key: $table, array: $this->table_schema)) {
            $this->load_table_schema();
            if (!array_key_exists(key: $table, array: $this->table_schema)) return false;
        }

        if (empty($column)) return true;

        return array_key_exists(key: $column, array: $this->table_schema[$table] ?? []);
    }

    /**
     * Load schema
     * Saves the table schema into local variable for fewer database requests
     *
     * @return void
     */
    protected function load_table_schema(): void
    {
        $sql = "SELECT `TABLE_NAME`,`COLUMN_NAME`,`CHARACTER_MAXIMUM_LENGTH`
                    FROM `INFORMATION_SCHEMA`.`COLUMNS`
                    WHERE `TABLE_SCHEMA` = :database_name;";

        if (!$this->sql_exec(query: $sql, params: ['database_name' => PLAYFAB_DB_NAME])) return;

        $this->table_schema = [];
        foreach ($this->results() as $row) {
            $this->table_schema[$row['TABLE_NAME']][$row['COLUMN_NAME']] = intval($row['CHARACTER_MAXIMUM_LENGTH']);
        };
    }

    /**
     * Modify table
     * Update a table schema, specifically for PlayFab content ingestion
     *
     * @param string $table
     * @param string $column
     * @param int $length
     * @return void
     */
    protected function modify_table_schema(string $table, string $column, int $length): void
    {
        if (!$this->valid_schema(table: $table)) {
            $sql = "CREATE TABLE IF NOT EXISTS `{$table}` (
                            `data_id` VARCHAR(64) NOT NULL PRIMARY KEY
                        ) ENGINE = InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;";
            $this->sql_exec($sql);
            $this->load_table_schema();
        }

        if (!$this->valid_schema(table: $table, column: $column)) {
            $sql = "ALTER TABLE `{$table}` ADD COLUMN `{$column}` VARCHAR( {$length} ) NULL;";
            $this->sql_exec($sql);
            $this->load_table_schema();
        }

        if ($length > $this->table_schema[$table][$column]) {
            $sql = "ALTER TABLE `{$table}` MODIFY `{$column}` VARCHAR( {$length} );";
            $this->sql_exec($sql);
            $this->load_table_schema();
        }
    }

    /*
        7.0 API Usage Logging
    */

    /**
     * Log API call
     * Records an API call for historical tracking and limiting purposes
     *
     * @param string $endpoint
     * @return void
     */
    protected function log_api_call(string $endpoint, int $status_code): void
    {
        $sql = "INSERT INTO `playfab_api_calls` (`call_endpoint`, `call_client`, `status_code`) VALUES (:endpoint, :client, :status_code);";
        $params = [
            "endpoint" => preg_replace(pattern: '/^(.*?(?=com))com/', replacement: '', subject: $endpoint),
            "client" => $_SERVER['REMOTE_ADDR'],
            "status_code" => $status_code
        ];
        $this->sql_exec($sql, $params);
    }

    /**
     * API calls per second
     * Query all logs that happened within the last 'x' minutes
     *
     * @param int $minutes
     * @return array
     */
    public function last_x_minute_calls_per_second(int $minutes = 2): int
    {
        $minutes = (is_int($minutes)) ? min($minutes, 120) : 2;
        $sql = "SELECT
                    IFNULL(
                        COUNT(*)
                        DIV TIMESTAMPDIFF(
                            SECOND
                            , CURRENT_TIMESTAMP - INTERVAL {$minutes} MINUTE
                            , CURRENT_TIMESTAMP
                        )
                        , 0
                    ) AS `calls_per_second`
                FROM `playfab_api_calls`
                WHERE `call_time` > (CURRENT_TIMESTAMP - INTERVAL {$minutes} MINUTE);";
        $this->sql_exec($sql);
        return intval($this->results(first_result_only: true)['calls_per_second']);
    }

    /**
     * Limit API Calls
     * Pause the script to hang for a period of time based on API call rates
     *
     * @return void
     */
    protected function call_limiter(): void
    {
        // calculate api calls per second
        $max_calls_per_second = PLAYFAB_CLIENT_2_MIN_LIMIT / 120;
        $current_calls_per_second = $this->last_x_minute_calls_per_second();

        // get number of microseconds of current and call limits
        $current_microseconds = 1 / max($current_calls_per_second, $max_calls_per_second) * 1000000;
        $limit_microseconds = 1 / $max_calls_per_second * 1000000;

        // get the overall call ratio based on the target limit
        $call_ratio = 1 / ($current_microseconds / $limit_microseconds);

        // scale the sleep time up or down based on the current call ratio
        $sleep_time = intval($limit_microseconds * $call_ratio);

        // this is just a weird function: https://www.php.net/manual/en/function.usleep.php
        usleep(microseconds: $sleep_time);
    }

    /**
     * Last endpoint call
     * Get the timestamp for the last call to a given endpoint
     *
     * @param string $endpoint
     * @return string
     */
    public function last_endpoint_call(string $endpoint): string
    {
        $sql = "SELECT * FROM `playfab_api_calls` WHERE `call_endpoint` = :endpoint ORDER BY `call_time` DESC LIMIT 1;";
        $params = ["endpoint" => preg_replace(pattern: '/^(.*?(?=com))com/', replacement: '', subject: $endpoint)];
        $this->sql_exec(query: $sql, params: $params);
        return $this->results(first_result_only: true)['call_time'] ?? gmdate(format: "Y-m-d H:i:s.u", timestamp: 0);
    }

    /**
     * Endpoint stale
     * Check if the last access time to a given endpoint is older than a given number of hours
     *
     * @param string $endpoint
     * @param int $hours
     * @return bool
     */
    protected function endpoint_stale(string $endpoint, int $hours): bool
    {
        $last_call = strtotime(datetime: $this->last_endpoint_call($endpoint));
        $go_stale = strtotime(datetime: "+{$hours} hour", baseTimestamp: $last_call);
        return $go_stale <= strtotime(datetime: "now");
    }

    /**
     * Trim API call logs
     * Delete old logs from `playfab_api_calls` table
     *
     * @param int $day_limit
     * @return void
     */
    public function trim_excess_logs(int $day_limit = 366): void
    {
        $this->sql_exec("DELETE FROM `playfab_api_calls` WHERE `call_time` < (NOW() - INTERVAL {$day_limit} DAY);");
    }

    /*
        8.0 Generic API Wrapper Functions
    */

    /**
     * Make REST Call
     * A consolidated REST caller for each endpoint function to call
     *
     * @param string $endpoint
     * @param array $headers
     * @param array $params
     * @param string $method
     * @return string
     */
    protected function make_rest_call(string $endpoint, array $headers = [], array $params = [], string $method = "POST", bool $kill = true): stdClass|string
    {
        // API call auditing
        $this->call_limiter();

        // input validation
//            $endpoint = $this->base_endpoint . $endpoint;
        if (!in_array(needle: "Content-Type: application/json", haystack: $headers)) $headers[] = "Content-Type: application/json";
        $method = (in_array(strtoupper($method), ['POST', 'GET', 'PUT', 'PATCH', 'DELETE'])) ? strtoupper($method) : "GET";
        #$params = $this->compile_params($params); // was used for GET but everything is POST

        // curl options
        # all curl options list: https://www.php.net/manual/en/function.curl-setopt.php
        $options = [
            #CURLOPT_URL => $endpoint . $params, // was used for GET but everything is POST
            CURLOPT_URL => $this->base_endpoint . $endpoint,
            CURLOPT_POSTFIELDS => json_encode($params),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_VERBOSE => true,
            CURLOPT_USERAGENT => 'Mozilla/4.0 (compatible; MSIE 6.0; Windows NT 5.1; SV1; .NET CLR 1.0.3705; .NET CLR 1.1.4322)',
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
        ];

        // echo "curl options: ". json_encode($options, JSON_PRETTY_PRINT) . "\n\n";

        // do all the things
        $curl = curl_init();
        curl_setopt_array($curl, $options);
        $this->response = curl_exec($curl);
        $this->log_api_call(endpoint: $endpoint, status_code: json_decode(json: $this->response ?: [])->code ?? 0);

        // debug printing
        if ($this->debug) {
            var_dump("endpoint: {$this->base_endpoint}{$endpoint}");
            var_dump("params: {$params}");
            var_dump("method: {$method}");
            var_dump("headers:", $headers);
            var_dump("response", $this->response);
            var_dump("curl", $curl);
        }

        // close connection and handle resposne
        curl_close($curl);
        $response = json_decode(json: ($this->response) ?: "[]");
        if (200 != $response->code) {
            if (401 == $response->code) unset($_SESSION['PlayFab']);
            if (!$kill) {
                return $this->response;
            }
            die("{$response->status} ({$response->code}): {$response->error}, {$response->errorMessage} [{$response->errorCode}]");
        }
        return $response;
    }

    /**
     * Compile URL Parameters
     * converts [key1=>val1,key2=>val2] list to URL-friendly ?key1=val1&key2=val2
     *
     * @param array $params
     * @return string
     */
    protected function compile_url_params(array $params): string
    {
        if (empty($params)) {
            return "";
        }
        $p = [];
        foreach ($params as $key => $val) {
            $p[] = (is_string($val)) ? "{$key}={$val}" : "{$key}=" . json_encode($val);
        }
        return "?" . implode("&", $p);
    }

    /*
        5.0 Playfab Authentication
    */

    /**
     * Register PlayFab User
     * https://learn.microsoft.com/en-us/rest/api/playfab/client/authentication/register-playfab-user?view=playfab-rest
     *
     * @return string
     */
    public function register(string $username, string $email, string $password): string
    {
        $params = [
            "TitleId" => $this->app_id,
            "Username" => $username,
            "Email" => $email,
            "Password" => $password
        ];
        $this->response = $this->make_rest_call(endpoint: '/Client/RegisterPlayFabUser', params: $params);
        return $this->response;
    }

    /**
     * Fetch 'SessionTicket'
     * Will get the current SessionTicket or reauthenticate to create a new one
     *
     * @return string
     */
    protected function session_ticket(): string
    {
        if ($this->session_ticket_expired()) {
            if (!$this->login_method) die("Missing login_method");
            $this->{$this->login_method}();
        }
        return $_SESSION['PlayFab']->SessionTicket;
    }

    /**
     * Session ticket expired?
     * Returns true or false based on whether the ticket has expired, or always false if it doesn't exist
     *
     * @return bool
     */
    public function session_ticket_expired(): bool
    {
        $expired = strtotime(datetime: $_SESSION['PlayFab']->EntityToken->TokenExpiration ?? "now");
        $right_now = strtotime(gmdate(format: "Y-m-d\TH:i:s\Z"));
        return $right_now >= $expired;
    }

    /**
     * Login With Email Address
     * https://learn.microsoft.com/en-us/rest/api/playfab/client/authentication/login-with-email-address?view=playfab-rest
     *
     * @return string
     */
    public function login_with_email(string $email = null, string $password = null, bool $force = false): void
    {
        $this->login_method = "login_with_email";

        if (!empty($email)) $this->email = $email;
        if (!empty($password)) $this->password = $password;
        if (empty($this->email) || empty($this->password)) {
            die("Could not authenticate with Playfab: missing email or password.");
        }

        if (!$this->session_ticket_expired() and !$force) return;

        $params = [
            "Email" => $this->email,
            "Password" => $this->password,
            "TitleId" => $this->app_id
        ];

        $r = $this->make_rest_call(endpoint: ENDPOINT_LOGIN_EMAIL, params: $params);
        $this->playFabId = $r->data->PlayFabId;
        $_SESSION['PlayFab'] = $r->data;
    }

    /**
     * Login With Google Account
     * https://learn.microsoft.com/en-us/rest/api/playfab/client/authentication/login-with-google-account?view=playfab-rest
     *
     * @return void
     */
    public function login_with_google(): void
    {
        $params = [
            "TitleId" => $this->app_id,
            "CreateAccount" => false
        ];
        $this->response = $this->make_rest_call(endpoint: ENDPOINT_LOGIN_GOOGLE, params: $params);
        $r = json_decode($this->response);
        $this->playFabId = $r->data->PlayFabId;
        $_SESSION['PlayFab'] = $r->data;
        $this->login_method = "login_with_google";
    }

    /*
        6.0 Player Context API Calls
    */

    /**
     * Get Player Statistics
     * https://learn.microsoft.com/en-us/rest/api/playfab/client/player-data-management/get-player-statistics?view=playfab-rest
     *
     * @param string|null $playFabId
     * @return string
     */
    public function get_player_stats(string $playFabId = null): stdClass
    {
        $headers = [
            'Content-Type: application/json',
            "X-Authorization: {$this->session_ticket()}"
        ];
        $params = [
            "PlayFabId" => ($playFabId) ?: $this->playFabId
        ];
        return $this->make_rest_call(endpoint: ENDPOINT_GET_PLAYER_STATS, headers: $headers, params: $params);
    }

    /**
     * Get All Users Characters
     * https://learn.microsoft.com/en-us/rest/api/playfab/client/characters/get-all-users-characters?view=playfab-rest
     *
     * @param string|null $playFabId
     * @return string
     */
    public function get_characters(string $playFabId = null): array
    {
        $headers = [
            'Content-Type: application/json',
            "X-Authorization: {$this->session_ticket()}"
        ];
        $params = [
            "PlayFabId" => ($playFabId) ?: $this->playFabId
        ];
        return $this->make_rest_call(endpoint: ENDPOINT_GET_USER_CHARS, headers: $headers, params: $params)->data->Characters;
    }

    /**
     * Get Character Statistics
     * https://learn.microsoft.com/en-us/rest/api/playfab/client/characters/get-character-statistics?view=playfab-rest
     *
     * @param string|null $characterId
     * @return string
     */
    public function get_character_stats(string $characterId = null): stdClass|string|bool
    {
        $headers = [
            'Content-Type: application/json',
            "X-Authorization: {$this->session_ticket()}"
        ];
        $params = [
            "CharacterId" => ($characterId) ?: "3850D4D160360B56"
        ];

        echo "request params: " . json_encode($params, JSON_PRETTY_PRINT) . "\n\n";

        try {
            return $this->make_rest_call(endpoint: ENDPOINT_GET_CHAR_STATS, headers: $headers, params: $params, kill: false);
        } catch (\Exception $e) {
            return False;
        }

    }

    /**
     * Get Character Statistics
     * https://learn.microsoft.com/en-us/rest/api/playfab/client/characters/get-character-statistics?view=playfab-rest
     *
     * @param string|null $characterId
     * @return string
     */
    public function get_character_data(string $characterId = null): stdClass|string|bool
    {
        $headers = [
            'Content-Type: application/json',
            "X-Authorization: {$this->session_ticket()}"
        ];
        $params = [
            "CharacterId" => ($characterId) ?: "3850D4D160360B56"
        ];

        echo "request params: " . json_encode($params, JSON_PRETTY_PRINT) . "\n\n";

        try {
            return $this->make_rest_call(endpoint: ENDPOINT_GET_CHAR_DATA, headers: $headers, params: $params, kill: false);
        } catch (\Exception $e) {
            return False;
        }

    }

    /**
     * Get User Data
     * https://learn.microsoft.com/en-us/rest/api/playfab/client/player-data-management/get-user-data?view=playfab-rest
     *
     * @return string
     */
    public function get_user_data(string $playerId = null): stdClass
    {
        $headers = [
            'Content-Type: application/json',
            "X-Authorization: {$this->session_ticket()}"
        ];
        $params = [];
        if ($playerId) $params["PlayFabId"] = $playerId;
        return $this->make_rest_call(endpoint: ENDPOINT_GET_USER_DATA, headers: $headers, params: $params);
    }


    /*
        7.0 Game Content API Calls
    */

    /**
     * Get Title News
     * https://learn.microsoft.com/en-us/rest/api/playfab/client/title-wide-data-management/get-title-news?view=playfab-rest
     *
     * @return string
     */
    public function get_news(int $count = 10): array
    {
        $rows = [];

        // update database
        if ($this->endpoint_stale(endpoint: ENDPOINT_GET_TITLE_NEWS, hours: PLAYFAB_GO_STALE_NEWS_HOURS)) {
            // make a new call to update the database
            $headers = [
                'Content-Type: application/json',
                "X-Authorization: {$this->session_ticket()}"
            ];
            $params = [
                "count" => $count
            ];
            $r = $this->make_rest_call(endpoint: ENDPOINT_GET_TITLE_NEWS, headers: $headers, params: $params);

            $sql = "INSERT INTO `playfab_news_entries` ( `news_timestamp`, `news_id`, `news_title`, `news_body` )
                        VALUES ( :news_timestamp, :news_id, :news_title, :news_body )
                        ON DUPLICATE KEY UPDATE `news_id` = :news_id;";

            foreach ($r->data->News as $post) {
                $params = [
                    "news_timestamp" => trim(preg_replace('/[TZ]/', ' ', $post->Timestamp)),
                    "news_id" => $post->NewsId,
                    "news_title" => $post->Title,
                    "news_body" => $post->Body
                ];
                $this->sql_exec($sql, $params);
            }
        }

        // fetch cached news posts from local database
        if (!is_int($count)) $count = 10;
        $this->sql_exec(query: "SELECT * FROM `playfab_news_entries` ORDER BY `news_timestamp` DESC LIMIT {$count};");
        return $this->results();
    }

    /**
     * Get Title Data
     * https://learn.microsoft.com/en-us/rest/api/playfab/client/title-wide-data-management/get-title-data?view=playfab-rest
     *
     * @return string
     */
    public function get_title_data(string|array $keys = [], string $search = null, bool $force_update = false, string|array $order_by = [], bool $sort_asc = true, string $ignore_regex = ''): stdClass
    {
        // do some limited formatting and input validation
        if (!is_array($keys)) $keys = ["Keys" => [$keys]];
        $keys = (array_key_exists(key: "Keys", array: $keys)) ? $keys : ["Keys" => $keys];

        // validate tables
        foreach (($keys["Keys"] ?? []) as $key) {
            if (!empty($ignore_regex) and 0 < preg_match($ignore_regex, $key)) continue;
            $table = "playfab_data_" . $this::pascal_to_snake($key);
            if (!$this->valid_schema($table)) {
                $sql = "CREATE TABLE IF NOT EXISTS `{$table}` (
                            `data_id` VARCHAR(64) PRIMARY KEY
                        ) ENGINE = InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;";
                $this->sql_exec($sql);
                $force_update = true;
                var_dump("force update");
            }

        }

        // refresh data
        if ($this->endpoint_stale(endpoint: ENDPOINT_GET_TITLE_DATA, hours: PLAYFAB_GO_STALE_HOURS_TITLE_DATA) or $force_update) {
            // make a new call to update the database
            $headers = [
                'Content-Type: application/json',
                "X-Authorization: {$this->session_ticket()}"
            ];

            $r = $this->make_rest_call(endpoint: ENDPOINT_GET_TITLE_DATA, headers: $headers, params: $keys);

            foreach ($r->data->Data as $type => $content) {
                if (!empty($ignore_regex) and 0 < preg_match($ignore_regex, $type)) {
                    continue;
                }

                $table = "playfab_data_" . $this::pascal_to_snake($type);
//                $sql = "CREATE TABLE IF NOT EXISTS `{$table}` (
//                            `data_id` VARCHAR(64) PRIMARY KEY
//                        ) ENGINE = InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;";
//                $this->sql_exec($sql);

                foreach (json_decode($content) as $id => $data) {
                    $cols = ['data_id'];
                    $update = [];
                    $params = ['data_id' => $id];
                    foreach ($data as $col => $val) {
                        $col = $this::pascal_to_snake($col);
                        $this->modify_table_schema(table: $table, column: $col, length: strlen($val));
                        $cols[] = $col;
                        $update[] = "`{$col}` = :{$col}";
                        $params[$col] = $val;
                    }
                    $update = implode(separator: ",\n", array: $update);

                    $sql = "INSERT INTO `{$table}` ( `" . implode(separator: "`,\n`", array: $cols) . "` )
                                VALUES ( :" . implode(separator: ",\n:", array: $cols) . " )
                                ON DUPLICATE KEY UPDATE {$update};";

                    $this->sql_exec($sql, $params);
                }
            }
        }

        $where = [];
        if ($keys) $where[] = "`data_id` = :key";
        if ($search) $where[] = "`data_content` LIKE :search";
        $where = (0 < count(value: $where)) ? "WHERE " . implode(separator: " AND ", array: $where) : '';
        $where = '';
        $params = [];
        $objs = new stdClass();

        foreach (($keys["Keys"] ?? []) as $key) {
            if (!empty($ignore_regex) and 0 < preg_match($ignore_regex, $key)) continue;
            $table = "playfab_data_" . $this::pascal_to_snake($key);
            $this->sql_exec(query: "SELECT * FROM `{$table}` {$where};", params: $params);
            $results = $this->results();
            foreach ($results as $result) $objs->{$result['data_id']} = (object)$result;
        }

        return $objs;
    }

    /**
     * Get Title Data Keys
     * I honestly don't know what I'm doing with this function anymore...
     *
     * @return array
     */
    public function get_title_data_keys(): array
    {
        $output = [];
        $headers = [
            'Content-Type: application/json',
            "X-Authorization: {$this->session_ticket()}"
        ];

        echo "<pre>";

        $r = $this->make_rest_call(endpoint: ENDPOINT_GET_TITLE_DATA, headers: $headers);

        $params = [
            "keys" => [
                "ConstellationData",
                "StarSystemData",
                "StarSystemConfigData",
//                    "ConstellationData",
//                    "ShopData",
                "GlobalVariables",
            ]
        ];

        $r = $this->make_rest_call(endpoint: ENDPOINT_GET_TITLE_DATA, params: $params, headers: $headers);

        $data = $r->data->Data;

        foreach ($data as $key => $datum) {
            echo "\n";
            print_r($datum);
        }
        echo "</pre>";

        return $output;
    }

    /**
     * Get Leaderboard
     * https://learn.microsoft.com/en-us/rest/api/playfab/client/player-data-management/get-leaderboard?view=playfab-rest
     *
     * @param string $statName
     * @param int $maxResults
     * @param int $startPosition
     * @return string
     */
    public function get_leaderboard(string $statName, int $maxResults = 10, int $startPosition = 0): string
    {
        $maxResults = min(100, max(10, $maxResults));
        $headers = [
            'Content-Type: application/json',
            "X-Authorization: {$this->session_ticket()}"
        ];
        $params = [
            'StatisticName' => $statName,
            'MaxResultsCount' => $maxResults,
            'StartPosition' => $startPosition
        ];
        $this->response = $this->make_rest_call(endpoint: ENDPOINT_GET_LEADERBOARD, headers: $headers, params: $params);
        return $this->response;
    }

    /**
     * Get Full Leaderboard
     * Recursively calls get_leaderboard() for a given 'StatisticName' until all results have been acquired
     *
     * @param string $statName
     * @return string
     */
    public function get_full_leaderboard(string $statName): string
    {
        $startPosition = 0;
        $leaderboard = [];
        while (true) {
            // make a request for the leaderboard
            $response = json_decode($this->get_leaderboard(statName: $statName, maxResults: 100, startPosition: $startPosition));
            // no results
            if (0 == count($response->data->Leaderboard)) break;
            // some results
            $leaderboard = array_merge($response->data->Leaderboard, $leaderboard);
            // max results
            if (100 > count($response->data->Leaderboard)) break;
            // keep getting more leaderboards
            $startPosition += 100;
        }
        return json_encode($leaderboard);
    }

    /**
     * Get Catalog Items
     * https://learn.microsoft.com/en-us/rest/api/playfab/client/title-wide-data-management/get-catalog-items?view=playfab-rest
     *
     * @return string
     */
    public function get_catalog(): string
    {
        $headers = [
            'Content-Type: application/json',
            "X-Authorization: {$this->session_ticket()}"
        ];
        return $this->make_rest_call(endpoint: ENDPOINT_GET_CATALOG_ITEMS, headers: $headers);
    }

    /**
     * Get Inventory
     * https://learn.microsoft.com/en-us/rest/api/playfab/client/player-item-management/get-user-inventory?view=playfab-rest
     *
     * @return string
     */
    public function get_inventory(): stdClass|string
    {
        $headers = [
            'Content-Type: application/json',
            "X-Authorization: {$this->session_ticket()}"
        ];
        return $this->make_rest_call(endpoint: ENDPOINT_GET_INVENTORY_ITEMS, headers: $headers);
    }

    /**
     * Dump Response
     * Formats and prints out the raw JSON for a request response
     *
     * @param string|null $response
     * @param bool $exit
     * @return void
     */
    public static function dump_response(string|stdClass|array $response = null, bool $exit = true): void
    {
        header('Content-Type: application/json; charset=utf-8');
        echo (is_string($response)) ?: json_encode(value: $response, flags: JSON_PRETTY_PRINT);
        if ($exit) exit();
    }

    /*
        X.0 Extras
    */

    /**
     * Pascal to Snake
     * It's in the name
     *
     * @param string $input
     * @return string
     */
    public static function pascal_to_snake(string $input): string
    {
        $input = preg_replace(pattern: '/([A-Z])/', replacement: '_$1', subject: $input);
        $input = preg_replace(pattern: '/\s+/', replacement: '_', subject: $input);
        $input = preg_replace(pattern: '/(_+)/', replacement: '_', subject: $input);
        return strtolower(trim(string: $input, characters: "_"));
    }

    /**
     * Snake to Pascal
     * It's in the name
     *
     * @param string $input
     * @return string
     */
    public static function snake_to_pascal(string $input): string
    {
        $input = ucwords($input, separators: "_");
        return preg_replace(pattern: '/(_+)/', replacement: '_', subject: $input);
    }

    /**
     * Generate a UUID
     * Creates an RFC 4122 compliant UUID from a text input
     *
     * @param string $input
     * @return string
     */
    public static function uuid_from_string(string $input): string
    {
        $uuid = bin2hex(hash(algo: 'md5', data: $input, binary: true));
        foreach ([20, 16, 12, 8] as $offset) $uuid = substr_replace(string: $uuid, replace: "-", offset: $offset, length: 0);
        return strtoupper($uuid);
    }

    /**
     * Generate HTML Table
     * Creates and formats the HTML code for a two-dimensional array
     *
     * @param string $table
     * @param string $tblClass
     * @param array $colClass
     * @return string
     */
    public static function html_table(array $table, string $tblClass = "", array $colClass = [], array $row_class = []): string
    {
        // table start
        $code = "";
        $code .= "<table class='{$tblClass}'>";

        if (!$table) {
            return "";
        }

        // add headers
        $hdrs = "";
        foreach ($table[0] as $col => $data) {
            $class = (array_key_exists($col, $colClass)) ? "class='{$colClass[$col]}'" : "";
            $hdrs .= "<th {$class}>{$col}</th>";
        }
        $code .= "<tr>{$hdrs}</tr>";

        // add _depricated_data
        foreach ($table as $row_num => $tblRow) {
            $row = "";
            foreach ($tblRow as $col => $val) {
                $class = (array_key_exists($col, $colClass)) ? "class='{$colClass[$col]}'" : "";
                $row .= "<td {$class}>{$val}</td>";
            }
            $class = ($row_class[$row_num] ?? "");
            $code .= "<tr class='{$class}'>{$row}</tr>";
        }

        // table end
        $code .= "</table>";
        return $code;
    }
}

