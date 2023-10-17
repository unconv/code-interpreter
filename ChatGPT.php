<?php
/**
 * From: https://github.com/unconv/php-gpt-funcs/blob/master/library/ChatGPT.php
 */

class ChatGPT
{
    protected array $messages = [];
    protected array $functions = [];
    protected $savefunction = null;
    protected $loadfunction = null;
    protected bool $loaded = false;
    protected string|array $function_call = "auto";
    protected string $model = "gpt-3.5-turbo";

    public function __construct(
        protected string  $api_key,
        protected ?string $chat_id = null
    )
    {
        if ($this->chat_id === null) {
            $this->chat_id = uniqid(more_entropy: true);
        }
    }

    public function load(): void
    {
        if (is_callable($this->loadfunction)) {
            $this->messages = ($this->loadfunction)($this->chat_id);
            $this->loaded = true;
        }
    }

    public function set_model(string $model): void
    {
        $this->model = $model;
    }

    public function get_model(): string
    {
        return $this->model;
    }

    public function version(): float
    {
        preg_match("/gpt-(([0-9]+)\.?([0-9]+)?)/", $this->model, $matches);
        return floatval($matches[1]);
    }

    public function force_function_call(string $function_name, ?array $arguments = null): void
    {
        if ($function_name === "auto") {
            if (!is_null($arguments)) {
                throw new Exception("Arguments must not be set when function_call is 'auto'");
            }
            $this->function_call = "auto";
        } else {
            $this->function_call = [
                "name" => $function_name,
                "arguments" => $arguments,
            ];
        }
    }

    public function smessage(string $system_message): void
    {
        $message = [
            "role" => "system",
            "content" => $system_message,
        ];

        $this->messages[] = $message;

        if (is_callable($this->savefunction)) {
            ($this->savefunction)((object)$message, $this->chat_id);
        }
    }

    public function umessage(string $user_message): void
    {
        $message = [
            "role" => "user",
            "content" => $user_message,
        ];

        $this->messages[] = $message;

        if (is_callable($this->savefunction)) {
            ($this->savefunction)((object)$message, $this->chat_id);
        }
    }

    public function amessage(string $assistant_message): void
    {
        $message = [
            "role" => "assistant",
            "content" => $assistant_message,
        ];

        $this->messages[] = $message;

        if (is_callable($this->savefunction)) {
            ($this->savefunction)((object)$message, $this->chat_id);
        }
    }

    public function fcall(
        string $function_name,
        string $function_arguments
    ): void
    {
        $message = [
            "role" => "assistant",
            "content" => null,
            "function_call" => [
                "name" => $function_name,
                "arguments" => $function_arguments,
            ]
        ];

        $this->messages[] = $message;

        if (is_callable($this->savefunction)) {
            ($this->savefunction)((object)$message, $this->chat_id);
        }
    }

    public function fresult(
        string $function_name,
        string $function_return_value
    ): void
    {
        $message = [
            "role" => "function",
            "content" => $function_return_value,
            "name" => $function_name,
        ];

        $this->messages[] = $message;

        if (is_callable($this->savefunction)) {
            ($this->savefunction)((object)$message, $this->chat_id);
        }
    }

    public function response(bool $raw_function_response = false)
    {
        $fields = [
            "model" => $this->model,
            "messages" => $this->messages,
        ];

        $functions = $this->get_functions();

        if (!empty($functions)) {
            $fields["functions"] = $functions;
            $fields["function_call"] = $this->function_call;
        }

        // make ChatGPT API request
        $ch = curl_init("https://api.openai.com/v1/chat/completions");
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Content-Type: application/json",
            "Authorization: Bearer " . $this->api_key
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(
            $fields
        ));
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        // get ChatGPT reponse
        $curl_exec = curl_exec($ch);
        $response = json_decode($curl_exec);

        // somewhat handle errors
        if (!isset($response->choices[0]->message)) {
            if (isset($response->error)) {
                $error = trim($response->error->message . " (" . $response->error->type . ")");
            } else {
                $error = $curl_exec;
            }
            throw new Exception("Error in OpenAI request: " . $error);
        }

        // add response to messages
        $message = $response->choices[0]->message;
        $this->messages[] = $message;

        if (is_callable($this->savefunction)) {
            ($this->savefunction)((object)$message, $this->chat_id);
        }

        $message = end($this->messages);

        return $this->handle_functions($message, $raw_function_response);
    }

    protected function handle_functions(stdClass $message, bool $raw_function_response = false)
    {
        if (isset($message->function_call)) {
            if ($raw_function_response) {
                return $message;
            }

            // get function name and arguments
            $function_call = $message->function_call;
            $function_name = $function_call->name;
            $arguments = json_decode($function_call->arguments, true);

            // sometimes ChatGPT responds with only a string of the
            // first argument instead of a JSON object
            if ($arguments === null) {
                $arguments = [$function_call->arguments];
            }

            $callable = $this->get_function($function_name);

            if (is_callable($callable)) {
                $result = $callable(...array_values($arguments));
            } else {
                $result = "Function '$function_name' unavailable.";
            }

            $this->fresult($function_name, $result);

            return $this->response();
        }

        return $message;
    }

    protected function get_function(string $function_name)
    {
        foreach ($this->functions as $function) {
            if ($function["name"] === $function_name) {
                return $function["function"];
            }
        }

        return false;
    }

    protected function get_functions()
    {
        $functions = [];

        foreach ($this->functions as $function) {
            $properties = [];
            $required = [];

            foreach ($function["parameters"] as $parameter) {
                $properties[$parameter['name']] = [
                    "type" => $parameter['type'],
                    "description" => $parameter['description'],
                ];

                if (isset($parameter["items"])) {
                    $properties[$parameter['name']]["items"] = $parameter["items"];
                }

                if (array_key_exists("required", $parameter) && $parameter["required"] !== false) {
                    $required[] = $parameter["name"];
                }
            }

            $functions[] = [
                "name" => $function["name"],
                "description" => $function["description"],
                "parameters" => [
                    "type" => "object",
                    "properties" => $properties,
                    "required" => $required,
                ],
            ];
        }

        return $functions;
    }

    public function add_function(array|callable $function): void
    {
        if (is_callable($function, true)) {
            $function = $this->parse_function($function);

            if (!is_callable($function['function'])) {
                throw new Exception("Function must be callable (public)");
            }
        }
        $this->functions[] = $function;
    }

    protected function parse_function(array|callable $function)
    {
        if (is_array($function)) {
            if (!is_callable($function, true)) {
                throw new Exception("Invalid class method provided");
            }

            $reflection = new ReflectionMethod(...$function);
        } else {
            $reflection = new ReflectionFunction($function);
        }

        $doc_comment = $reflection->getDocComment() ?: "";
        $description = $this->parse_description($doc_comment);

        $function_data = [
            "function" => $function,
            "name" => $reflection->getName(),
            "description" => $description,
            "parameters" => [],
        ];

        $matches = [];
        preg_match_all('/@param\s+(\S+)\s+\$(\S+)[^\S\r\n]?([^\r\n]+)?/', $doc_comment, $matches);

        $types = $matches[1];
        $names = $matches[2];
        $descriptions = $matches[3];

        $params = $reflection->getParameters();
        foreach ($params as $param) {
            $name = $param->getName();
            $index = array_search($name, $names);
            $description = $descriptions[$index] ?? "";
            $type = $param->getType()?->getName() ?? $types[$index] ?? "string";

            try {
                $param->getDefaultValue();
                $required = false;
            } catch (ReflectionException $e) {
                $required = true;
            }

            $data = [
                "name" => $name,
                "type" => $this->parse_type($type),
                "description" => $description,
                "required" => $required,
            ];

            if (strpos($type, "array<") === 0) {
                $array_type = trim(substr($type, 5), "<>");
                $data["type"] = "array";
                $data["items"] = [
                    "type" => $this->parse_type($array_type),
                ];
            }

            if (strpos($type, "[]") !== false) {
                $array_type = substr($type, 0, -2);
                $data["type"] = "array";
                $data["items"] = [
                    "type" => $this->parse_type($array_type),
                ];
            }

            $function_data["parameters"][] = $data;
        }

        return $function_data;
    }

    protected function parse_type(string $type)
    {
        return match ($type) {
            "int" => "number",
            "integer" => "number",
            "string" => "string",
            "float" => "number",
            default => "string",
        };
    }

    protected function parse_description(string $doc_comment)
    {
        $lines = explode("\n", $doc_comment);
        $description = "";

        $started = false;
        foreach ($lines as $line) {
            $matches = [];
            if (preg_match('/\s+?\*\s+?([^@](.*?))?$/', $line, $matches) === 1) {
                $description .= " " . $matches[1];
                $started = true;
            } elseif ($started) {
                break;
            }
        }

        return trim($description);
    }

    public function messages()
    {
        return $this->messages;
    }

    public function loadfunction(callable $loadfunction, bool $autoload = true)
    {
        $this->loadfunction = $loadfunction;
        if ($autoload && !$this->loaded) {
            $this->load();
        }
    }

    public function savefunction(callable $savefunction)
    {
        $this->savefunction = $savefunction;
    }
}
