<?php
if ( PHP_SAPI !== "cli" || isset( $_SERVER['HTTP_USER_AGENT'] ) ) {
    die( "This program is intended to be used from the terminal.\n" );
}

require( __DIR__ . "/ChatGPT.php" );

class PythonResult {
    public function __construct(
        public string $output,
        public int $result_code,
    ) {}
}

/**
 * Determines the Python command to run based on the
 * operating system or the command line argument
 *
 * @return string The python command
 */
function get_python_command(): string {
    if( defined( "PYTHON_COMMAND" ) ) {
        return PYTHON_COMMAND;
    }

    if( stripos( PHP_OS, "win" ) === 0 ) {
        return "python";
    }

    return "python3";
}

function run_python_code( string $code ): PythonResult {
    $temp_file = "/tmp/code.py";

    if( ! file_put_contents( $temp_file, $code ) ) {
        throw new \Exception( "Unable to write code file" );
    }

    $output = [];
    $result_code = NULL;
    exec( get_python_command() . " ".escapeshellarg( $temp_file )." 2>&1", $output, $result_code );

    if( file_exists( $temp_file ) ) unlink( $temp_file );

    return new PythonResult(
        output: implode( "\n", $output ),
        result_code: $result_code,
    );
}

function input( string $message = "" ): string {
    echo $message;
    $stdin = fopen( "php://stdin", "r" );
    $line = fgets( $stdin );
    fclose( $stdin );
    return trim( $line );
}

/**
 * Run python code
 *
 * @param string $code The code to run. Code must have a print statement in the end that prints out the relevant return value
 */
function python( string $code ): string {
    $styled_code = "";
    $code_rows = explode( "\n", $code );

    foreach( $code_rows as $row ) {
        $styled_code .= "# " . str_pad( $row, 45 ) . " #\n";
    }

    echo "\n";
    echo "#################################################\n";
    echo "# I WANT TO RUN THIS PYTHON CODE:               #\n";
    echo $styled_code;
    echo "#################################################\n";

    do {
        $answer = input( "\nDo you want to run this code? (yes/no)" );
    } while ( ! in_array( $answer, ["yes", "no"] ) );

    if( $answer != "yes" ) {
        echo "\nSKIPPED RUNNING CODE\n";
        return "User rejected code. Please ask for changes or what to do next.";
    }

    $result = run_python_code( $code );

    return json_encode( [
        "output" => $result->output,
        "result_code" => $result->result_code,
    ] );
}

$program_name = array_shift( $argv );

$args = [];

while( $flag = array_shift( $argv ) ) {
    if( $flag === "--model" ) {
        $args["model"] = array_shift( $argv );
    } elseif( $flag === "--python-command" ) {
        define( "PYTHON_COMMAND", array_shift( $argv ) );
    }
}

if( ! file_exists( __DIR__ . "/data/" ) ) {
    mkdir( __DIR__ . "/data/" );
}

$chatgpt = new ChatGPT( getenv( "OPENAI_API_KEY" ) );
$chatgpt->set_model( $args["model"] ?? "gpt-3.5-turbo" );
$chatgpt->smessage( "You are an AI assistant that can run Python code in order to answer the user's question. You can access a folder called 'data/' from the Python code to read or write files. Write visualizations and charts into a file if possible. When creating links to files in the data directory in your response, use the format [link text](data/filename)" );
$chatgpt->add_function( "python" );

echo "################################################\n";
echo "# PHP CodeInterpreter by Unconventional Coding #\n";
echo "################################################\n\n";

echo "GPT: Hello! I am the PHP CodeInterpreter! What would you like to do?
You: ";

while( true ) {
    $message = input();

    if( in_array( $message, ["exit", "quit", "stop"] ) ) {
        echo "\nGPT: Thanks!\n";
        exit;
    }

    $chatgpt->umessage( $message );

    echo "\n\nGPT: " . $chatgpt->response()->content . "\nYou: ";
}
