# Test environment for tests/launch.sh.
#
# launch.sh runs PHP with -n and loads bcmath, curl, gd and xml as shared
# modules from the extension directory, so this image uses the Ubuntu PHP
# packages (built that way, like the CI runners) instead of the official
# php image, where those extensions are compiled in.
#
# Also provides pcov for coverage, poppler-utils for pdfinfo/pdftotext,
# and the Docker CLI plus jq for the veraPDF validation step.
FROM ubuntu:24.04

ARG PHP_VERSION=8.1

ENV DEBIAN_FRONTEND=noninteractive

RUN apt-get update \
	&& apt-get install -y --no-install-recommends ca-certificates gnupg software-properties-common \
	&& add-apt-repository -y ppa:ondrej/php \
	&& apt-get update \
	&& apt-get install -y --no-install-recommends \
		docker.io \
		git \
		jq \
		make \
		php${PHP_VERSION}-bcmath \
		php${PHP_VERSION}-cli \
		php${PHP_VERSION}-curl \
		php${PHP_VERSION}-gd \
		php${PHP_VERSION}-mbstring \
		php${PHP_VERSION}-pcov \
		php${PHP_VERSION}-xml \
		php${PHP_VERSION}-zip \
		poppler-utils \
		unzip \
	&& rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
