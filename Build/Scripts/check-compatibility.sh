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
    8.1:11|8.2:11|8.3:11|8.1:12|8.2:12|8.3:12|8.4:12|8.2:13|8.3:13|8.4:13|8.5:13) ;;
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
    11) constraint='^11.5' ;;
    12) constraint='^12.4' ;;
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

git ls-files -z '*.php' | xargs -0 -n 1 php -l
php vendor/bin/rector process --dry-run --no-progress-bar
configuration_directory="$(mktemp -d)"
configuration="$configuration_directory/phpstan.neon"
trap 'rm -f "$configuration" "$configuration_directory/grumphp.yml"; rmdir "$configuration_directory"' EXIT HUP INT TERM
php -r 'echo "includes:\n  - ", json_encode(getcwd() . "/phpstan.neon"), "\nparameters:\n  phpVersion: ", PHP_VERSION_ID, "\n";' > "$configuration"
php vendor/bin/phpstan analyse Classes --configuration="$configuration" --no-progress --memory-limit=1G
composer audit --no-interaction --abandoned=report
php -r 'require "vendor/autoload.php"; $configuration = ["imports" => [["resource" => getcwd() . "/grumphp.yml"]]]; $defaults = Symfony\Component\Yaml\Yaml::parseFile("vendor/pluswerk/grumphp-config/grumphp.yml"); if (isset($defaults["grumphp"]["tasks"]["securitychecker_enlightn"])) { $configuration["grumphp"]["tasks"]["securitychecker_enlightn"]["metadata"]["enabled"] = false; } file_put_contents($argv[1], Symfony\Component\Yaml\Yaml::dump($configuration, 6, 2));' "$configuration_directory/grumphp.yml"
php vendor/bin/grumphp run --config="$configuration_directory/grumphp.yml" --no-interaction
if php -r '$c=json_decode(file_get_contents("composer.json"),true); exit(isset($c["scripts"]["test"]) ? 0 : 1);'; then
    composer test
else
    printf 'No configured test script; unit, functional and browser coverage remains unverified.\n'
    exit 1
fi
