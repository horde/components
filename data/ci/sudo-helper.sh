#!/bin/bash
# Horde CI - Privileged Operations Script
#
# This script performs all operations that require root privileges:
# - Adding ondrej PPA
# - Running apt-get update
# - Installing PHP versions (8.2, 8.3, 8.4, 8.5)
# - Installing PHP extensions
#
# This script is designed to be called by horde-components CI setup
# and should be allowed via sudoers for passwordless execution.
#
# Location: /usr/local/bin/horde-ci-sudo-helper
# Sudoers: <user> ALL=(ALL) NOPASSWD: /usr/local/bin/horde-ci-sudo-helper

set -e

# Only accept specific operations
OPERATION="$1"
shift

case "$OPERATION" in
    add-ppa)
        # Add ondrej PHP PPA
        apt-get install -y software-properties-common >/dev/null 2>&1
        add-apt-repository -y ppa:ondrej/php
        apt-get update -qq
        ;;

    install-php)
        # Install PHP version
        # Usage: install-php 8.4
        PHP_VERSION="$1"
        if [[ ! "$PHP_VERSION" =~ ^8\.[2-5]$ ]]; then
            echo "ERROR: Invalid PHP version: $PHP_VERSION" >&2
            exit 1
        fi
        DEBIAN_FRONTEND=noninteractive apt-get install -y "php${PHP_VERSION}-cli"
        ;;

    install-extension)
        # Install PHP extension
        # Usage: install-extension 8.4 curl
        PHP_VERSION="$1"
        EXTENSION="$2"
        if [[ ! "$PHP_VERSION" =~ ^8\.[2-5]$ ]]; then
            echo "ERROR: Invalid PHP version: $PHP_VERSION" >&2
            exit 1
        fi
        if [[ ! "$EXTENSION" =~ ^[a-z0-9_-]+$ ]]; then
            echo "ERROR: Invalid extension name: $EXTENSION" >&2
            exit 1
        fi
        DEBIAN_FRONTEND=noninteractive apt-get install -y "php${PHP_VERSION}-${EXTENSION}" 2>&1 | grep -v "Unable to locate package" || true
        ;;

    check-php)
        # Check if PHP version is installed
        # Usage: check-php 8.4
        PHP_VERSION="$1"
        if [ -x "/usr/bin/php${PHP_VERSION}" ]; then
            echo "installed"
        else
            echo "not-installed"
        fi
        ;;

    *)
        echo "ERROR: Unknown operation: $OPERATION" >&2
        echo "Valid operations: add-ppa, install-php, install-extension, check-php" >&2
        exit 1
        ;;
esac
