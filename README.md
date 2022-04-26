# README

Please see the instructions for user installation [here](https://elestyle.atlassian.net/wiki/external/494370908/YWE3YzgxYmJjYWNmNDA4N2ExYTY5ZGE0Z[…]iYTdmY2QxMDE4ZWYxNDM5Zjg1NWMwOWU1NWVlNWM3NmIiLCJwIjoiYyJ9).

### Install dependencies

Put `elepay-php-sdk` in the `Resource/vendor/` directory

Use the following command to generate autoload in the `Resource/vendor/` directory

```shell
curl -sS https://getcomposer.org/installer | php && mv composer.phar /usr/bin/composer
COMPOSER_VENDOR_DIR=Resource/vendor composer require elestyle/elepay-php-sdk
COMPOSER_VENDOR_DIR=Resource/vendor composer dumpautoload -o
```

### Directory description

- `Controller\` Route Controller
- `Entity\` Database table definition classes, where files ending in 'traits' are used to extend database tables
- `Form\Type\Admin\ConfigType.php` Plugin setup page for form building
- `Repository\` Operation extension classes for database tables
- `Resource\` Static configuration files, resource files, and template files
- `Service\` Tool class
- `ServiceProvider\` Code that executes globally by default
- `composer.json` Package management
- `Event.php` Use the template render event to do the corresponding processing
- `PluginManager.php` Main
