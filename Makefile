.DEFAULT_GOAL := help

help:
	@echo "Available targets:"
	@echo "  install            Build PHP container + install Composer deps"
	@echo "  install-wp-tests   Install the WordPress test framework (requires Docker)"
	@echo "  test               Run the PHPUnit test suite"
	@echo "  test-filter        Run a single test: make test-filter FILTER=Test_Foo::test_bar"
	@echo "  shell              Open a bash shell inside the PHP container"
	@echo "  down               Stop containers"
	@echo "  clean              Nuke everything (containers, volumes, vendor/)"

.PHONY: help build install install-wp-tests test test-filter shell mysql-wait down clean

DC  := docker compose
RUN := $(DC) run --rm php

build:
	$(DC) build

install: build
	$(RUN) composer install

mysql-wait:
	$(DC) up -d mysql
	@echo "Waiting for MySQL to be ready..."
	@until $(DC) exec -T mysql healthcheck.sh --connect --innodb_initialized > /dev/null 2>&1; do sleep 1; done
	@echo "MySQL ready."

install-wp-tests: mysql-wait
	$(RUN) bash bin/install-wp-tests.sh wordpress_test root '' mysql latest

test:
	$(RUN) vendor/bin/phpunit

# Run a single test or filter: make test-filter FILTER=Test_Settings::test_defaults
test-filter:
	$(RUN) vendor/bin/phpunit --filter "$(FILTER)"

shell:
	$(RUN) bash

down:
	$(DC) down

# Nukes everything: containers, volumes, vendor/. Removes all dev state.
# vendor/ and other artifacts are root-owned (Docker runs as root inside the container),
# so we delete them from inside the container before tearing it down.
clean:
	-$(RUN) rm -rf vendor composer.lock .phpunit.result.cache
	$(DC) down -v
