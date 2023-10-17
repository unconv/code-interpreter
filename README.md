# ChatGPT CodeInterpreter in PHP

This is ChatGPT CodeInterpreter for the terminal made in PHP. You can ask it to create charts, analyze CSV files or
anything, really.

## Quick Start

```shell
$ php interpreter.php
```

Requires PHP and Python

## Running in Docker (Optional)

Requires Docker installed and configured locally.

To build the image, run the following command in the root directory of the project:

```shell
docker build -t chatgpt-codeinterpreter .
```

To run the image, run the following command in the root directory of the project:

```shell
docker run -it --rm -e OPENAI_API_KEY=sk-{REPLACE_ME} -e MODEL=gpt-3.5-turbo -v ./data:/app/data chatgpt-codeinterpreter
```

You can also use MODEL=gpt-4
