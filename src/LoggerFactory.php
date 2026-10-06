<?php

declare(strict_types=1);

namespace DbBackup;

use Monolog\Formatter\LineFormatter;
use Monolog\Handler\SlackWebhookHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\WhatFailureGroupHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Log\LoggerInterface;

class LoggerFactory
{
    public static function create(Config $config): LoggerInterface
    {
        $formatter = new LineFormatter("[%datetime%] %level_name% %message%\n", 'Y-m-d H:i:s');
        $logger = new Logger('db-backup');

        $logger->pushHandler((new StreamHandler($config->logFile))->setFormatter($formatter));
        if (stream_isatty(STDOUT)) {
            $logger->pushHandler((new StreamHandler('php://stdout'))->setFormatter($formatter));
        }

        if ($config->slackWebhook !== '') {
            $slack = new SlackWebhookHandler($config->slackWebhook, username: 'db-backup@' . gethostname(), level: Level::Notice);
            $logger->pushHandler(new WhatFailureGroupHandler([$slack]));
        }

        return $logger;
    }
}
