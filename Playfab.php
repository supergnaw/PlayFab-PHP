<?php
    declare( strict_types = 1 );

    namespace app\PlayFab;

    class Playfab {
        /*
            1.0 Variables & Magic Methods
        */

        // generic
        private bool $debug = false;
        private string $response;


        // application identification
        protected string $app_id;
        protected string $username;
        protected string $email;
        protected string $password;

        // authentication
        private string | null $sessionTicket = null;
        private string | null $playFabId = null;

        // endpoints
        private string $BASE_ENDPOINT = "https://titleId.playfabapi.com";


        /**
         * Constructor
         *
         * @param string $app_id
         * @param string $username
         * @param string $email
         * @param string $password
         */
        public function __construct(string $app_id, string $username, string $email, string $password)
        {
            $this->set_app_id( $app_id );
            $this->set_username( $username );
            $this->set_email( $email );
            $this->set_password( $password );
        }

        /**
         * Invoker
         *
         * @param string $app_id
         * @param string $username
         * @param string $email
         * @param string $password
         */
        public function __invoke(string $app_id, string $username, string $email, string $password): void
        {
            $this->set_app_id( $app_id );
            $this->set_email( $email );
            $this->set_password( $password );
        }

        /*
            2.0 Variable Implementations
        */

        /**
         * Set app identification value
         *
         * @param string $app_id
         * @return void
         */
        public function set_app_id(string $app_id): void
        {
            $this->app_id = $app_id;
            $this->BASE_ENDPOINT = str_replace(search: "titleId", replace: $this->app_id, subject: $this->BASE_ENDPOINT);
        }

        /**
         * Set app login username
         *
         * @param string $username
         * @return void
         */
        public function set_username(string $username): void
        {
            $this->username = $username;
        }

        /**
         * Set app login email
         *
         * @param string $email
         * @return void
         */
        public function set_email(string $email): void
        {
            $this->email = $email;
        }

        /**
         * Set app login password
         *
         * @param string $password
         * @return void
         */
        public function set_password(string $password): void
        {
            $this->password = $password;
        }

        /*
            3.0 API Calls
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
        private function _make_rest_call(string $endpoint, array $headers = ["Content-Type: application/json"], array $params = [], string $method = "POST"): string
        {
            $endpoint = $this->BASE_ENDPOINT . $endpoint;
            $params = $this->_compile_params($params);
            $method = (in_array(strtoupper($method), ['POST', 'GET', 'PUT', 'PATCH', 'DELETE'])) ? strtoupper($method) : "GET";
            $options = [
                CURLOPT_URL => $endpoint . $params,
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

            $curl = curl_init();
            curl_setopt_array($curl, $options);
            $this->response = curl_exec($curl);

            if ($this->debug) {
                var_dump("endpoint: {$endpoint}{$params}");
                var_dump("method: {$method}");
                var_dump("headers:", $headers);
                var_dump("response", $this->response);
                var_dump("curl", $curl);
            }

            curl_close($curl);
            return $this->response;
        }

        /**
         * Compile URL Parameters
         * converts [key1=>val1,key2=>val2] list to URL-friendly ?key1=val1&key2=val2
         *
         * @param array $params
         * @return string
         */
        private function _compile_params(array $params): string
        {
            if (empty($params)) {
                return "";
            }
            $p = [];
            foreach ($params as $key => $val) $p[] = "{$key}={$val}";
            return "?" . implode("&", $p);
        }

        /**
         * Fetch 'SessionTicket' or generate a new one
         *
         * @return string
         */
        private function session_ticket(): string
        {
            if (null == $this->sessionTicket) {
                $this->login();
            }
            return $this->sessionTicket;
        }

        /*
            4.0 Endpoint Functions
        */

        /**
         * Register PlayFab User
         * https://learn.microsoft.com/en-us/rest/api/playfab/client/authentication/register-playfab-user?view=playfab-rest
         *
         * @return string
         */
        public function register(): string
        {
            $params = [
                "TitleId" => $this->app_id,
                "Username" => $this->username,
                "Email" => $this->email,
                "Password" => $this->password
            ];
            $this->response = $this->_make_rest_call(endpoint: '/Client/RegisterPlayFabUser', params: $params);
            return $this->response;
        }

        /**
         * Login With Email Address
         * https://learn.microsoft.com/en-us/rest/api/playfab/client/authentication/login-with-email-address?view=playfab-rest
         *
         * @return string
         */
        public function login(): string
        {
            $params = [
                "Email" => $this->email,
                "Password" => $this->password,
                "TitleId" => $this->app_id
            ];
            $this->response = $this->_make_rest_call(endpoint: '/Client/LoginWithEmailAddress', params: $params);
            $r = json_decode($this->response);
            $this->playFabId = $r->data->PlayFabId;
            $this->sessionTicket = $r->data->SessionTicket;
            return $this->response;
        }

        /**
         * Get Title News
         * https://learn.microsoft.com/en-us/rest/api/playfab/client/title-wide-data-management/get-title-news?view=playfab-rest
         *
         * @return string
         */
        public function get_news(): string
        {
            $headers = [
                'Content-Type: application/json',
                "X-Authorization: {$this->session_ticket()}"
            ];
            $this->response = $this->_make_rest_call(endpoint: '/Client/GetTitleNews', headers: $headers);
            return $this->response;
        }

        /**
         * Get Player Statistics
         * https://learn.microsoft.com/en-us/rest/api/playfab/client/player-data-management/get-player-statistics?view=playfab-rest
         *
         * @param string|null $playFabId
         * @return string
         */
        public function get_player_stats(string $playFabId = null): string
        {
            $headers = [
                'Content-Type: application/json',
                "X-Authorization: {$this->session_ticket()}"
            ];
            $params = [
                "PlayFabId" => (null == $playFabId) ? $this->playFabId : $playFabId
            ];
            return $this->_make_rest_call(endpoint: "/Client/GetPlayerStatistics", headers: $headers, params: $params);
        }

        /**
         * Get All Users Characters
         * https://learn.microsoft.com/en-us/rest/api/playfab/client/characters/get-all-users-characters?view=playfab-rest
         *
         * @param string|null $playFabId
         * @return string
         */
        public function get_characters(string $playFabId = null): string
        {
            $headers = [
                'Content-Type: application/json',
                "X-Authorization: {$this->session_ticket()}"
            ];
            $params = [
                "PlayFabId" => (null == $playFabId) ? $this->playFabId : $playFabId
            ];
            return $this->_make_rest_call(endpoint: "/Client/GetAllUsersCharacters", headers: $headers, params: $params);
        }

        /**
         * Get Character Statistics
         * https://learn.microsoft.com/en-us/rest/api/playfab/client/characters/get-character-statistics?view=playfab-rest
         *
         * @param string|null $characterId
         * @return string
         */
        public function get_character_stats(string $characterId = null): string
        {
            $headers = [
                'Content-Type: application/json',
                "X-Authorization: {$this->session_ticket()}"
            ];
            $params = [
                "CharacterId" => (null == $characterId) ? "3850D4D160360B56" : $characterId
            ];
            return $this->_make_rest_call(endpoint: "/Client/GetCharacterStatistics", headers: $headers, params: $params);
        }

        /**
         * Get User Data
         * https://learn.microsoft.com/en-us/rest/api/playfab/client/player-data-management/get-user-data?view=playfab-rest
         *
         * @return string
         */
        public function get_user_data(): string
        {
            $headers = [
                'Content-Type: application/json',
                "X-Authorization: {$this->session_ticket()}"
            ];
            return $this->_make_rest_call(endpoint: "/Client/GetUserData", headers: $headers);
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
            $maxResults = min(100, max( 10, $maxResults));
            $headers = [
                'Content-Type: application/json',
                "X-Authorization: {$this->session_ticket()}"
            ];
            $params = [
                'StatisticName' => $statName,
                'MaxResultsCount' => $maxResults,
                'StartPosition' => $startPosition
            ];
            $this->response = $this->_make_rest_call(endpoint: '/Client/GetLeaderboard', headers: $headers, params: $params);
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
            $results = json_decode('{"code":200,"status":"OK","data":{"Leaderboard":[]}}');
            $startPosition = 0;
            while (true) {
                $response = json_decode($this->get_leaderboard(statName: $statName, maxResults: 100, startPosition: $startPosition));
                if (0 == count($response->data->Leaderboard)) break;
                $results->data->Leaderboard = array_merge($response->data->Leaderboard, $results->data->Leaderboard);
                $startPosition += 100;
            }
            $this->response = json_encode($results);
            return $this->response;
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
            return $this->_make_rest_call(endpoint: '/Client/GetCatalogItems', headers: $headers);
        }

        /**
         * Get Title Data
         * https://learn.microsoft.com/en-us/rest/api/playfab/client/title-wide-data-management/get-title-data?view=playfab-rest
         *
         * @return string
         */
        public function get_title_data(): string
        {
            $headers = [
                'Content-Type: application/json',
                "X-Authorization: {$this->session_ticket()}"
            ];
            $this->_make_rest_call(endpoint: '/Client/GetTitleData', headers: $headers);
            $r = json_decode($this->response);
            foreach ($r->data->Data as $k => $data) {
                if (is_string($data)) $r->data->Data->$k = json_decode($data);
            }
            $this->response = json_encode($r);
            return $this->response;
        }


        /**
         * Print JSON Response
         *
         * @param string|null $response
         * @param bool $exit
         * @return void
         */
        public function print_response(string $response = null, bool $exit = true): void
        {
            header('Content-Type: application/json; charset=utf-8');
            echo (null == $response) ? $this->response : $response;
            if ($exit) exit();
        }

        /*
            X.0 Other
        */

        /**
         * Generate HTML code for a two-dimensional array
         *
         * @param string $table
         * @param string $tblClass
         * @param array $colClass
         * @return string
         */
        public static function html_table(array $table, string $tblClass = "", array $colClass = [] ): string
        {
            // table start
            $code = "";
            $code .= "<table class='{$tblClass}'>";

            // add headers
            $hdrs = "";
            foreach( $table[0] as $col => $data ) {
                $class = ( array_key_exists( $col, $colClass )) ? "class='{$colClass[$col]}'" : "";
                $hdrs .= "<th {$class}>{$col}</th>";
            }
            $code .= "<tr>{$hdrs}</tr>";

            // add _depricated_data
            foreach( $table as $tblRow ) {
                $row = "";
                foreach( $tblRow as $col => $val ) {
                    $class = ( array_key_exists( $col, $colClass )) ? "class='{$colClass[$col]}'" : "";
                    $row .= "<td {$class}>{$val}</td>";
                }
                $code .= "<tr>{$row}</tr>";
            }

            // table end
            $code .= "</table>";
            return $code;
        }
    }

