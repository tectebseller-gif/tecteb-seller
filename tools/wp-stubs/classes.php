<?php
declare(strict_types=1);

namespace TmcWpStubs {
    final class WpDieException extends \RuntimeException
    {
        public function __construct(string $message, public readonly string $title = '', public readonly array $args = [])
        {
            parent::__construct($message);
        }
    }

    final class Rest
    {
        /** Runs permission_callback then callback exactly as the dispatcher would. */
        public static function call(string $namespace, string $route, ?\WP_REST_Request $request = null): \WP_Error|\WP_REST_Response
        {
            $key = $namespace . $route;
            if (!isset(State::$restRoutes[$key])) {
                throw new \RuntimeException('route not registered: ' . $key);
            }
            $args = State::$restRoutes[$key];
            $request ??= new \WP_REST_Request('GET', '/' . $key);
            $perm = call_user_func($args['permission_callback'], $request);
            if ($perm instanceof \WP_Error) {
                return $perm;
            }
            if ($perm !== true) {
                return new \WP_Error('rest_forbidden', 'forbidden', ['status' => rest_authorization_required_code()]);
            }
            $response = call_user_func($args['callback'], $request);
            return rest_ensure_response($response);
        }
    }
}

namespace {
    if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }
    if (!defined('OBJECT')) { define('OBJECT', 'OBJECT'); }
    if (!defined('MINUTE_IN_SECONDS')) { define('MINUTE_IN_SECONDS', 60); }
    if (!defined('HOUR_IN_SECONDS')) { define('HOUR_IN_SECONDS', 3600); }
    if (!defined('ABSPATH')) { define('ABSPATH', sys_get_temp_dir() . '/tmc-stub-abspath/'); }

    class WP_Error
    {
        /** @var array<string,list<string>> */
        public array $errors = [];
        /** @var array<string,mixed> */
        public array $error_data = [];

        public function __construct(string $code = '', string $message = '', mixed $data = '')
        {
            if ($code !== '') {
                $this->errors[$code][] = $message;
                if ($data !== '') {
                    $this->error_data[$code] = $data;
                }
            }
        }

        public function get_error_code(): string
        {
            return (string) (array_key_first($this->errors) ?? '');
        }

        public function get_error_message(string $code = ''): string
        {
            $code = $code !== '' ? $code : $this->get_error_code();
            return $this->errors[$code][0] ?? '';
        }

        public function get_error_data(string $code = ''): mixed
        {
            $code = $code !== '' ? $code : $this->get_error_code();
            return $this->error_data[$code] ?? null;
        }
    }

    class WP_REST_Server
    {
        public const READABLE = 'GET';
        public const CREATABLE = 'POST';
    }

    class WP_REST_Request
    {
        /** @var array<string,mixed> */
        private array $params = [];
        /** @var array<string,string> */
        private array $headers = [];

        public function __construct(private string $method = 'GET', private string $route = '')
        {
        }

        public function get_method(): string
        {
            return $this->method;
        }

        public function get_route(): string
        {
            return $this->route;
        }

        public function set_param(string $key, mixed $value): void
        {
            $this->params[$key] = $value;
        }

        public function get_param(string $key): mixed
        {
            return $this->params[$key] ?? null;
        }

        public function set_header(string $key, string $value): void
        {
            $this->headers[strtolower($key)] = $value;
        }

        public function get_header(string $key): ?string
        {
            return $this->headers[strtolower($key)] ?? null;
        }
    }

    class WP_REST_Response
    {
        /** @var array<string,string> */
        private array $headers = [];

        public function __construct(private mixed $data = null, private int $status = 200, array $headers = [])
        {
            $this->headers = $headers;
        }

        public function get_data(): mixed
        {
            return $this->data;
        }

        public function set_data(mixed $data): void
        {
            $this->data = $data;
        }

        public function get_status(): int
        {
            return $this->status;
        }

        public function set_status(int $status): void
        {
            $this->status = $status;
        }

        public function header(string $key, string $value, bool $replace = true): void
        {
            $this->headers[$key] = $value;
        }

        public function set_headers(array $headers): void
        {
            $this->headers = array_merge($this->headers, $headers);
        }

        /** @return array<string,string> */
        public function get_headers(): array
        {
            return $this->headers;
        }
    }

    class WP_Role
    {
        /** @param array<string,bool> $capabilities */
        public function __construct(public string $name, public array $capabilities)
        {
        }

        public function has_cap(string $cap): bool
        {
            return !empty($this->capabilities[$cap]);
        }

        public function add_cap(string $cap, bool $grant = true): void
        {
            $this->capabilities[$cap] = $grant;
            \TmcWpStubs\State::$roles[$this->name][$cap] = $grant;
        }

        public function remove_cap(string $cap): void
        {
            unset($this->capabilities[$cap], \TmcWpStubs\State::$roles[$this->name][$cap]);
        }
    }
}
