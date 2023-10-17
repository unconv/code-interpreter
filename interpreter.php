<?php
if (PHP_SAPI !== "cli" || isset($_SERVER['HTTP_USER_AGENT'])) {
    die("This program is intended to be used from the terminal.\n");
}

require(__DIR__ . "/ChatGPT.php");

class PythonResult
{
    public function __construct(
        public string $output,
        public int    $result_code,
    )
    {
    }
}

/**
 * @throws Exception
 */
function run_python_code(string $code): PythonResult
{
    $temp_file = "/tmp/code.py";
    $pythonCommand = getenv("PYTHON_COMMAND") ?? "python3";
    if (!file_put_contents($temp_file, $code)) {
        throw new Exception("Unable to write code file");
    }

    $output = [];
    $result_code = NULL;
    exec($pythonCommand . ' ' . escapeshellarg($temp_file) . ' 2>&1', $output, $result_code);

    if (file_exists($temp_file)) unlink($temp_file);

    return new PythonResult(
        output: implode("\n", $output),
        result_code: (int)$result_code,
    );
}

function input(string $message = ""): string
{
    echo $message;
    $stdin = fopen("php://stdin", "r");
    $line = fgets($stdin);
    fclose($stdin);
    return trim($line);
}

/**
 * Run python code
 *
 * @param string $code The code to run. Code must have a print statement in the end that prints out the relevant return value
 */
function python(string $code): string
{
    $styled_code = "";
    $code_rows = explode("\n", $code);

    foreach ($code_rows as $row) {
        $styled_code .= "# " . str_pad($row, 45) . " #\n";
    }

    echo "\n";
    echo "#################################################\n";
    echo "# I WANT TO RUN THIS PYTHON CODE:               #\n";
    echo $styled_code;
    echo "#################################################\n";

    do {
        $answer = input("\nDo you want to run this code? (yes/no)");
    } while (!in_array($answer, ["yes", "no"]));

    if ($answer != "yes") {
        echo "\nSKIPPED RUNNING CODE\n";
        return "User rejected code. Please ask for changes or what to do next.";
    }

    try {
        $result = run_python_code($code);
    } catch (Exception $e) {
        return json_encode([
            "output" => $e->getMessage(),
            "result_code" => $e->getCode() ?? -1,
        ]);
    }

    return json_encode([
        "output" => $result->output,
        "result_code" => $result->result_code,
    ]);
}

if (!file_exists(__DIR__ . "/data/")) {
    mkdir(__DIR__ . "/data/");
}

$chatGPT = new ChatGPT(getenv("OPENAI_API_KEY"));
if (getenv("MODEL")) {
    $chatGPT->set_model(getenv("MODEL"));
}
$chatGPT->smessage("You are an AI assistant that can run Python code in order to answer the user's question. You can access a folder called 'data/' from the Python code to read or write files. Write visualizations and charts into a file if possible. When creating links to files in the data directory in your response, use the format [link text](data/filename)");
try {
    $chatGPT->add_function("python");
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit;
}

echo "################################################\n";
echo "# PHP CodeInterpreter by Unconventional Coding #\n";
echo "################################################\n\n";

echo "GPT: Hello! I am the PHP CodeInterpreter! What would you like to do?
You: ";

while (true) {
    $message = input();

    if (in_array($message, ["exit", "quit", "stop"])) {
        echo "\nGPT: Thanks!\n";
        exit;
    }

    $chatGPT->umessage($message);

    try {
        echo "\n\nGPT: " . $chatGPT->response()->content . "\nYou: ";
    } catch (Exception $e) {
        echo "\n\nGPT: Something when wrong " . $e->getMessage() . "\n";
        exit;
    }
}
