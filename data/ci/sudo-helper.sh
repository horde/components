#!/bin/bash
# Horde CI - Privileged Operations Script
#
# This script performs all operations that require root privileges:
# - Adding ondrej PPA
# - Running apt-get update
# - Installing PHP versions (whichever versions ondrej/php currently ships)
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
        #
        # The regex only enforces the shape MAJOR.MINOR so command
        # injection via shell metacharacters is impossible. Deciding
        # which versions are actually installable is not this script's
        # job: PhpInstaller picks the matrix, ondrej/php decides what
        # apt can resolve, and apt's own "Unable to locate package ..."
        # is the authoritative signal. Hard-coding a version range here
        # (previously ^8\.[2-5]$) silently breaks the day PHP 8.6 or 9.0
        # ships.
        PHP_VERSION="$1"
        if [[ ! "$PHP_VERSION" =~ ^[0-9]+\.[0-9]+$ ]]; then
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
        if [[ ! "$PHP_VERSION" =~ ^[0-9]+\.[0-9]+$ ]]; then
            echo "ERROR: Invalid PHP version: $PHP_VERSION" >&2
            exit 1
        fi
        if [[ ! "$EXTENSION" =~ ^[a-z0-9_-]+$ ]]; then
            echo "ERROR: Invalid extension name: $EXTENSION" >&2
            exit 1
        fi
        # The helper's exit code is the apt-get exit code.
        # Previously this line had `2>&1 | grep -v "Unable to locate
        # package" || true` which swallowed both the error AND the exit
        # code: when apt couldn't find php${PHP_VERSION}-${EXTENSION},
        # the helper still exited 0 and ExtensionInstaller thought the
        # install had succeeded. The downstream composer install then
        # failed cryptically. Pipe apt's output straight through; set -e
        # (top of script) plus apt's non-zero exit takes us out of the
        # case branch with the right status.
        DEBIAN_FRONTEND=noninteractive apt-get install -y "php${PHP_VERSION}-${EXTENSION}"
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
