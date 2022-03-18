<?php

namespace Plugin\Elepay\ServiceProvider;

use Plugin\Elepay\Entity\ElepayConfig;
use Plugin\Elepay\Entity\ElepayOrder;
use Silex\Application as BaseApplication;
use Silex\ServiceProviderInterface;
use Monolog\Handler\FingersCrossed\ErrorLevelActivationStrategy;
use Monolog\Handler\FingersCrossedHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Logger;
use Symfony\Component\Translation\Translator;

class ElepayServiceProvider implements ServiceProviderInterface
{
    public function register(BaseApplication $app)
    {
        // Admin page
        $admin_dir = '/' . trim($app['config']['admin_route']);
        $app
            ->match($admin_dir . '/plugin/elepay/config', 'Plugin\Elepay\Controller\Admin\ConfigController::index')
            // The routeName format must be 'plugin_{config.code}_config', otherwise the setting button of the plug-in will not be displayed
            ->bind('plugin_Elepay_config');

        // Checkout page
        $app->match('/elepay_checkout', '\Plugin\Elepay\Controller\ElepayController::checkout')->bind('elepay_checkout');
        $app->match('/elepay_checkout_validate', '\Plugin\Elepay\Controller\ElepayController::checkoutValidate')->bind('elepay_checkout_validate');

        // Webhook
        $app->match('/elepay_paid_webhook', '\Plugin\Elepay\Controller\ElepayController::elepayWebhook')->bind('elepay_paid_webhook');

        // Service
        $app['eccube.elepay.service.helper'] = $app->share(function () use ($app) {
            return new \Plugin\Elepay\Service\ElepayHelper($app);
        });
        $app['eccube.elepay.service.logger'] = $app->share(function () use ($app) {
            return new \Plugin\Elepay\Service\LoggerService($app);
        });

        // Repository
        $app['eccube.elepay.repository.config'] = $app->share(function () use ($app) {
            return $app['orm.em']->getRepository(ElepayConfig::class);
        });
        $app['eccube.elepay.repository.order'] = $app->share(function () use ($app) {
            return $app['orm.em']->getRepository(ElepayOrder::class);
        });

        // Form/Type
        $app['form.types'] = $app->share($app->extend('form.types', function ($types) use ($app) {
            $types[] = new \Plugin\Elepay\Form\Type\Admin\ConfigType($app);
            return $types;
        }));

        // メッセージ登録
        $app['translator'] = $app->share($app->extend('translator', function (Translator $translator, \Silex\Application $app) {
            $translator->addLoader('yaml', new \Symfony\Component\Translation\Loader\YamlFileLoader());
            $file = __DIR__ . '/../Resource/locale/messages.' . $app['locale'] . '.yml';
            if (file_exists($file)) {
                $translator->addResource('yaml', $file, $app['locale']);
            }
            return $translator;
        }));

        $app['monolog.elepay'] = $app->share(function ($app) {
            /** @var Logger $logger */
            $logger = new $app['monolog.logger.class']('Elepay');
            $file = $app['config']['root_dir'] . '/app/log/Elepay.log';
            $RotateHandler = new RotatingFileHandler($file, $app['config']['log']['max_files'], Logger::INFO);
            $RotateHandler->setFilenameFormat('Elepay_{date}', 'Y-m-d');
            $logger->pushHandler(new FingersCrossedHandler($RotateHandler, new ErrorLevelActivationStrategy(Logger::INFO)));
            return $logger;
        });
    }

    public function boot(BaseApplication $app)
    {
    }
}