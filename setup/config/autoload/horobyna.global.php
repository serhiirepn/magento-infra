<?php
/**
 * PostgreSQL database adapter for Magento
 *
 * The setup application (setup:install, setup:upgrade...) is built by its own service manager, which module
 * di.xml files do not configure. This replaces its MySQL-only connection factory and database validator.
 */
return [
    'service_manager' => [
        'aliases' => [
            \Magento\Setup\Module\ConnectionFactory::class => \Horobyna\Db\Setup\Module\ConnectionFactory::class,
            \Magento\Setup\Validator\DbValidator::class => \Horobyna\Db\Setup\Validator\DbValidator::class,
        ],
    ],
];
