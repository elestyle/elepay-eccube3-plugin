<?php

namespace Plugin\Elepay\Service;

use Eccube\Application;
use Monolog\Logger;

/**
 * Class LoggerService
 */
class LoggerService
{
    /**
     * ログヘッダー
     */
    const LOG_HEADER = '[elepay] ';

    /**
     * @var Logger $logger
     */
    protected $logger;

    /**
     * LoggerService constructor.
     * @param Application $app
     */
    public function __construct(Application $app)
    {
        $this->logger = $app['monolog.elepay'];
    }

    public function emergency($message, array $context = [])
    {
        $this->logger->emergency(self::LOG_HEADER . $message, $context);
    }

    public function alert($message, array $context = [])
    {
        $this->logger->alert(self::LOG_HEADER . $message, $context);
    }

    public function critical($message, array $context = [])
    {
        $this->logger->critical(self::LOG_HEADER . $message, $context);
    }

    public function error($message, array $context = [])
    {
        $this->logger->error(self::LOG_HEADER . $message, $context);
    }

    public function warning($message, array $context = [])
    {
        $this->logger->warning(self::LOG_HEADER . $message, $context);
    }

    public function notice($message, array $context = [])
    {
        $this->logger->notice(self::LOG_HEADER . $message, $context);
    }

    public function info($message, array $context = [])
    {
        $this->logger->info(self::LOG_HEADER . $message, $context);
    }

    public function debug($message, array $context = [])
    {
        $this->logger->debug(self::LOG_HEADER . $message, $context);
    }
}
