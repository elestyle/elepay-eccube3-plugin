<?php

namespace Plugin\Elepay;

use Eccube\Application;
use Eccube\Common\Constant;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\ORM\ORMException;
use Eccube\Doctrine\Filter\SoftDeleteFilter;
use Symfony\Component\Filesystem\Filesystem;
use Eccube\Plugin\AbstractPluginManager;
use Eccube\Entity\Payment;
use Eccube\Entity\PaymentOption;
use Eccube\Entity\Delivery;
use Eccube\Repository\PaymentRepository;
use Eccube\Repository\PaymentOptionRepository;
use Eccube\Repository\DeliveryRepository;
use Plugin\Elepay\Entity\ElepayConfig;
use Plugin\Elepay\Repository\ElepayConfigRepository;

class PluginManager extends AbstractPluginManager
{
    /**
     * @var string
     */
    private $origin_dir;

    /**
     * @var string
     */
    private $target_dir;

    /**
     * PluginManager constructor.
     */
    public function __construct()
    {
        // Define a copy source directory and a copy target directory
        $this->origin_dir = __DIR__ . '/Resource/assets/img';
        $this->target_dir = __DIR__ . '/../../../html/template/default/assets/img/elepay';
    }

    /**
     * @param array $config
     * @param Application $app
     */
    public function install(array $config, Application $app)
    {
        // リソースファイルのコピー
//        $this->copyAssets();
    }

    /**
     * @param array $config
     * @param Application $app
     * @throws ORMException
     * @throws OptimisticLockException
     */
    public function uninstall(array $config, Application $app)
    {
        // リソースファイルの削除
//        $this->removeAssets();
        $this->removePaymentMethod($app);
        $this->migrationSchema($app, __DIR__ . '/Resource/doctrine/migration', $config['code'], 0);
    }

    /**
     * @param array $config
     * @param Application $app
     * @throws ORMException
     * @throws OptimisticLockException
     */
    public function enable(array $config, Application $app)
    {
        $this->update($config, $app);
        $this->registerPluginConfig($app);
        $this->registerPaymentMethod($app);
        $this->enablePaymentMethod($app);
    }

    /**
     * @param array $config
     * @param Application $app
     * @throws ORMException
     * @throws OptimisticLockException
     */
    public function disable(array $config, Application $app)
    {
        $this->disablePaymentMethod($app);
    }

    /**
     * @param array $config
     * @param Application $app
     */
    public function update(array $config, Application $app)
    {
        $this->migrationSchema($app, __DIR__ . '/Resource/doctrine/migration', $config['code']);
    }

    /**
     * Register the default plugin configuration
     *
     * @param Application $app
     * @throws ORMException
     * @throws OptimisticLockException
     */
    private function registerPluginConfig(Application $app)
    {
        /** @var EntityManager $entityManager */
        $entityManager = $app['orm.em'];
        /** @var ElepayConfigRepository $configRepository */
        $configRepository = $entityManager->getRepository(ElepayConfig::class);

        /** @var ElepayConfig $config */
        $config = $configRepository->get();

        if (empty($config)) {
            /** @var ElepayConfig $Config */
            $config = ElepayConfig::createInitialConfig();
            $entityManager->persist($config);
            $entityManager->flush($config);
        }
    }

    /**
     * Register payment method
     *
     * @param Application $app
     * @throws ORMException
     * @throws OptimisticLockException
     */
    public function registerPaymentMethod(Application $app)
    {
        $elepayPluginConfig = $app['config']['Elepay']['const'];
        /** @var EntityManager $entityManager */
        $entityManager = $app['orm.em'];

        // Try to get an existing payment method
        /** @var Payment $payment */
        $payment = $this->getPayment($app, ['method' => $elepayPluginConfig['name']]);

        if (empty($payment)) {
            // Payment method does not exist, create again

            // Get the largest payment method of Rank other than Elepay
            // The larger the rank is, the more advanced the page appears
            $topPayment = $this->getPayment($app, [], [ 'rank' => 'DESC' ]);
            // If found, set its sort_no to 1 higher than them
            $sortNo = $topPayment ? $topPayment->getRank() + 1 : 0;

            $payment = new Payment();
            $payment
                ->setRank($sortNo)
                ->setCharge(0)
                ->setChargeFlg(Constant::ENABLED)
                ->setFixFlg(Constant::ENABLED);
        }

        $payment
            ->setMethod($elepayPluginConfig['name'])
            ->setDelFlg(Constant::ENABLED);

        $entityManager->persist($payment);
        $entityManager->flush($payment);

        // Bind existing delivery methods to payment methods
        /** @var DeliveryRepository $deliveryRepository */
        $deliveryRepository = $app['eccube.repository.delivery'];
        /** @var Delivery $delivery */
        foreach ($deliveryRepository->findAll() as $delivery) {
            /** @var PaymentOptionRepository $paymentOptionRepository */
            $paymentOptionRepository = $entityManager->getRepository(PaymentOption::class);
            $paymentOption = $paymentOptionRepository->findOneBy([
                'delivery_id' => $delivery->getId(),
                'payment_id' => $payment->getId(),
            ]);
            if (!is_null($paymentOption)) {
                continue;
            }
            $paymentOption = new PaymentOption();
            $paymentOption
                ->setPayment($payment)
                ->setPaymentId($payment->getId())
                ->setDelivery($delivery)
                ->setDeliveryId($delivery->getId());
            $entityManager->persist($paymentOption);
            $entityManager->flush($paymentOption);
        }
    }

    /**
     * Remove payment method
     * When the payment method overpays, it is bound to the order data and cannot be deleted.
     * So don't do real delete, just do logical disable
     *
     * @param Application $app
     * @throws ORMException
     * @throws OptimisticLockException
     */
    public function removePaymentMethod(Application $app)
    {
        $elepayPluginConfig = $app['config']['Elepay']['const'];
        /** @var EntityManager $entityManager */
        $entityManager = $app['orm.em'];

        /** @var Payment $payment */
        $payment = $this->getPayment($app, ['method' => $elepayPluginConfig['name']]);
        $payment->setDelFlg(Constant::ENABLED);
        $entityManager->persist($payment);
        $entityManager->flush($payment);
    }

    /**
     * Enable payment method
     *
     * @param Application $app
     * @throws ORMException
     * @throws OptimisticLockException
     */
    public function enablePaymentMethod(Application $app)
    {
        $elepayPluginConfig = $app['config']['Elepay']['const'];
        /** @var EntityManager $entityManager */
        $entityManager = $app['orm.em'];

        /** @var Payment $payment */
        $payment = $this->getPayment($app, ['method' => $elepayPluginConfig['name']]);
        $payment->setDelFlg(Constant::DISABLED);
        $entityManager->persist($payment);
        $entityManager->flush($payment);
    }

    /**
     * Disable payment methods
     *
     * @param Application $app
     * @throws ORMException
     * @throws OptimisticLockException
     */
    public function disablePaymentMethod(Application $app)
    {
        $elepayPluginConfig = $app['config']['Elepay']['const'];
        /** @var EntityManager $entityManager */
        $entityManager = $app['orm.em'];

        /** @var Payment $payment */
        $payment = $this->getPayment($app, ['method' => $elepayPluginConfig['name']]);
        $payment->setDelFlg(Constant::ENABLED);
        $entityManager->persist($payment);
        $entityManager->flush($payment);
    }

    /**
     * Get the Payment instance according to the condition, not affected by del_fig
     *
     * @param Application $app
     * @param array $conditions
     * @param array $sort
     * @return Payment|null
     */
    public function getPayment(Application $app, array $conditions = [], array $sort = null)
    {
        /** @var SoftDeleteFilter  $softDeleteFilter */
        $softDeleteFilter = $app['orm.em']->getFilters()->getFilter('soft_delete');
        $originExcludes = $softDeleteFilter->getExcludes();
        $softDeleteFilter->setExcludes([ 'Eccube\Entity\Payment' ]);

        /** @var PaymentRepository $paymentRepository */
        $paymentRepository = $app['eccube.repository.payment'];
        /** @var Payment $payment */
        $payment = $paymentRepository->findOneBy($conditions, $sort);

        $softDeleteFilter->setExcludes($originExcludes);

        return $payment;
    }

    /**
     * Gets the set of all Payment instances, not affected by del_fig
     *
     * @param Application $app
     * @param array $conditions
     * @param array $sort
     * @return array
     */
    public function getPayments($app, array $conditions = [], array $sort = null)
    {
        /** @var SoftDeleteFilter  $softDeleteFilter */
        $softDeleteFilter = $app['orm.em']->getFilters()->getFilter('soft_delete');
        $originExcludes = $softDeleteFilter->getExcludes();
        $softDeleteFilter->setExcludes([ 'Eccube\Entity\Payment' ]);

        /** @var PaymentRepository $paymentRepository */
        $paymentRepository = $app['eccube.repository.payment'];
        $result = $paymentRepository->findBy($conditions, $sort);;

        $softDeleteFilter->setExcludes($originExcludes);

        return $result;
    }

    /**
     * Copy Resource Directories
     */
    private function copyAssets()
    {
        $file = new Filesystem();
        $file->mkdir($this->target_dir);
        $file->mirror($this->origin_dir, $this->target_dir);
    }

    /**
     * Delete Resource Directories
     */
    private function removeAssets()
    {
        $file = new Filesystem();
        $file->remove($this->target_dir);
    }
}
