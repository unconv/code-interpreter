FROM python:3.9-slim-bookworm as python-env
RUN apt-get update && apt-get install -y \
    libjpeg62-turbo-dev \
    zlib1g-dev \
    libfreetype6-dev \
    liblcms2-dev \
    libopenjp2-7-dev \
    libtiff5-dev \
    libblas-dev \
    liblapack-dev \
    gcc \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /app
COPY requirements.txt .
RUN pip install --upgrade pip
RUN pip install --no-cache-dir -r requirements.txt

FROM php:8.2-cli-bookworm

RUN apt-get update && apt-get install -y \
    libffi-dev \
    libjpeg62-turbo-dev \
    zlib1g-dev \
    libfreetype6-dev \
    liblcms2-dev \
    libopenjp2-7-dev \
    libtiff5-dev \
    libblas-dev \
    liblapack-dev \
    gcc \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*
# Copy Python binary and libraries
COPY --from=python-env /usr/local/bin/python /usr/local/bin/
COPY --from=python-env /usr/local/lib/python3.9/ /usr/local/lib/python3.9/
COPY --from=python-env /usr/local/lib/libpython3.9.so.1.0 /usr/local/lib/
COPY --from=python-env /usr/local/bin/pip /usr/local/bin/
ENV PYTHONPATH /usr/local/lib/python3.9/site-packages/
COPY . /app/
WORKDIR /app
ENV PYTHON_COMMAND=python
ENV MODEL=gpt-3.5-turbo
VOLUME /app/data
ENTRYPOINT [ "/bin/sh", "-c", "php /app/interpreter.php \
                               --model $MODEL \
                               --python-command $PYTHON_COMMAND"]

