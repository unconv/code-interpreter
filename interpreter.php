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

function get_filename( string $filename ): string {
    if( strpos( $filename, "data/" ) !== 0 ) {
        return "data/" . $filename;
    }

    return $filename;
}

/**
 * Read the contents of a file
 *
 * @param string $filename The name of the file to read
 * @param int $line_count How many lines to read (-1 = all lines)
 */
function read_file_contents( string $filename, ?int $line_count = null ) {
    $filename = get_filename( $filename );

    if( $line_count === -1 ) {
        $line_count = null;
    }

    $how_many = $line_count === null ? "ALL": $line_count;

    print( "\nREADING " . $how_many . " LINES FROM FILE: " . $filename . "\n" );

    if( ! file_exists( $filename ) ) {
        return "<file not found>";
    }

    if( ! is_readable( $filename ) ) {
        return "<file is not readable>";
    }

    // TODO: read lines more efficiently
    $lines = file( $filename );

    if( $lines === false ) {
        return "<unable to read file>";
    }

    $lines = array_slice( $lines, 0, $line_count );

    $contents = implode( "\n", $lines );

    if( trim( $contents ) == "" ) {
        return "<file is empty>";
    }

    return $contents;
}

/**
 * Run python code
 *
 * @param string $code The code to run. Code must have a print statement in the end that prints out the relevant return value
 */
function python( string $code ): string {
    $code = trim( $code );

    // fix ChatGPT hallucinations
    if( str_contains( $code, '"code": "' ) ) {
        echo "\nNOTICE: Fixing ChatGPT hallucinated arguments\n";

        $code = explode( '"code": "', $code, 2 );
        $code = trim( $code[1] );
        $code = trim( rtrim( $code, '}' ) );
        $code = trim( rtrim( $code, '"' ) );

        // convert "\n" to newline
        $code = str_replace( '\n', "\n", $code );
    }

    $styled_code = "";
    $code_rows = explode( "\n", $code );
    $row_count = count( $code_rows );

    foreach( $code_rows as $i => $row ) {
        // TODO: run python code in such a way that this is
        //       not necessary
        if( $i === $row_count-1 ) {
            if( ! str_contains( $row, "print(" ) ) {
                $row = "print(" . $row . ")";
            }
        }

        $styled_code .= "# " . str_pad( $row, CODE_BOX_LEN - 4 ) . " #\n";
    }

    echo "\n";
    echo "#" . str_repeat( "#", CODE_BOX_LEN - 2 ) . "#\n";
    echo "# ". str_pad( "I WANT TO RUN THIS PYTHON CODE:", CODE_BOX_LEN - 3 ) . "#\n";
    echo $styled_code;
    echo "#" . str_repeat( "#", CODE_BOX_LEN - 2 ) . "#\n";

    do {
        $answer = input( "\nGPT: Do you want to run this code? (yes/no)\nYou: " );
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

// alias for ChatGPT hallucinations
function pythoncode( string $code ): string {
    echo "\nNOTICE: ChatGPT ran hallucinated 'pythoncode' function\n";
    return python( $code );
}

define( "CODE_BOX_LEN", 65 );

$program_name = array_shift( $argv );

$args = [];

while( $flag = array_shift( $argv ) ) {
    if( $flag === "--model" ) {
        $args["model"] = array_shift( $argv );
        echo "INFO: Using model '" . $args["model"] . "'\n";
    } elseif( $flag === "--python-command" ) {
        $args["python-command"] = array_shift( $argv );
        define( "PYTHON_COMMAND", array_shift( $argv ) );
        echo "INFO: Using python command '" . PYTHON_COMMAND . "'\n";
    }
}

if( count( $args ) ) {
    echo "\n";
}

if( ! file_exists( __DIR__ . "/data/" ) ) {
    mkdir( __DIR__ . "/data/" );
}

$chatgpt = new ChatGPT( getenv( "OPENAI_API_KEY" ) );
$chatgpt->set_model( $args["model"] ?? "gpt-3.5-turbo" );
$chatgpt->smessage( "You are an AI assistant that can read files and run Python code in order to answer the user's question. You can access a folder called 'data/' from the Python code to read or write files. Always save visualizations and charts into a file. When creating links to files in the data directory in your response, use the format [link text](data/filename). When the task requires to process or read user provided data from files, always read the file content first, before running Python code. Don't assume the contents of files. When processing CSV files, read the file first before writing any Python code. Note that Python code will always be run in an isolated environment, without access to variables from previous code." );
$chatgpt->add_function( "read_file_contents" );
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
