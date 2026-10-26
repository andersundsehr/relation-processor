#!/bin/sh
set -eu

php_version="${1:?PHP version required}"
typo3_version="${2:?TYPO3 major required}"
dependencies="${3:-latest}"

php -r 'exit(PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION === $argv[1] ? 0 : 1);' "$php_version"
php -v
composer self-update --2 --no-interaction
composer --version
case "$php_version:$typo3_version" in
    8.2:13|8.3:13|8.4:13|8.5:13) ;;
    *) printf 'Unsupported PHP/TYPO3 pair: %s / %s\n' "$php_version" "$typo3_version"; exit 1 ;;
esac
case "$dependencies" in latest|lowest) ;; *) exit 1 ;; esac

export GRUMPHP_GIT_HOOKS_ENABLED=0
export typo3DatabaseDriver=pdo_sqlite
export XDEBUG_MODE=off
export PHP_INI_SCAN_DIR="${PHP_INI_SCAN_DIR:-}:$(pwd)/Build/Configuration/PHP"

composer validate --no-check-publish --no-interaction
composer config lock true
composer require --dev --sort-packages 'squizlabs/php_codesniffer:^3.8.0 || ^4.0' --no-update --no-interaction
case "$typo3_version" in
    13)
        constraint='^13.4'
        composer require --dev --sort-packages 'saschaegerer/phpstan-typo3:^2.2.0' 'ssch/typo3-rector:^3.16.0' 'rector/rector:^2.7.0' 'pluswerk/grumphp-config:^11.2.0' --no-update --no-interaction
        ;;
esac
composer require --dev --sort-packages "typo3/minimal:$constraint" --no-update --no-interaction
if [ "$php_version" = 8.5 ]; then
    composer require --dev --sort-packages 'phpunit/phpunit:^10.5.62 || ^11.5.50 || ^12.5.0 || ^13.0.2' --no-update --no-interaction
fi
if [ "$dependencies" = lowest ]; then
    composer update --with-all-dependencies --prefer-lowest --prefer-stable --no-interaction --no-progress --no-scripts
else
    composer update --with-all-dependencies --prefer-stable --no-interaction --no-progress --no-scripts
fi
composer check-platform-reqs
if [ "$php_version" = 8.5 ]; then
    composer prohibits php 8.5.0 || true
fi

php vendor/bin/grumphp run --no-interaction
if php -r '$c=json_decode(file_get_contents("composer.json"),true); exit(isset($c["scripts"]["test"]) ? 0 : 1);'; then
    composer test
else
    printf 'No configured test script; unit, functional and browser coverage remains unverified.\n'
    exit 1
fi
