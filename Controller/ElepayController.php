<?php

namespace Plugin\Elepay\Controller;

require_once(__DIR__ . '/../Resource/vendor/autoload.php');

use DateTime;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\OptimisticLockException;
use Eccube\Application;
use Eccube\Event\EccubeEvents;
use Eccube\Event\EventArgs;
use Elepay\ApiException;
use Exception;
use GuzzleHttp\Exception\GuzzleException;
use Plugin\Elepay\Entity\ElepayOrder;
use Plugin\Elepay\Repository\ElepayOrderRepository;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Eccube\Entity\Order;
use Eccube\Entity\Master\OrderStatus;
use InvalidArgumentException;
use Plugin\Elepay\Service\ElepayHelper;
use Plugin\Elepay\Service\LoggerService;
use Symfony\Component\HttpFoundation\Response;

class ElepayController
{
    /**
     * elepay 決済画面を表示する.
     *
     * @Route("/elepay_checkout", name="elepay_checkout")
     *
     * @param Application $app
     * @param Request $request
     * @return RedirectResponse|Response
     * @throws OptimisticLockException
     */
    public function checkout(Application $app, Request $request)
    {
        /** @var EntityManager $entityManager */
        $entityManager = $app['orm.em'];
        /** @var ElepayHelper $elepayHelper */
        $elepayHelper = $app['eccube.elepay.service.helper'];
        /** @var LoggerService $logger */
        $logger = $app['eccube.elepay.service.logger'];

        /** @var Order $order */
        $order = $elepayHelper->getCartOrder();
        if (!$order) {
            $logger->error('[注文確認] 購入処理中の受注が存在しません.', [$order->getId()]);
            return $app->redirect($app->url('shopping_error'));
        }

        if ((integer)$order->getPaymentTotal() === 0) {
            // When the payment amount is 0, directly modify the order status to paid
            /** @var OrderStatus $orderStatus */
            $orderStatus = $elepayHelper->getOrderStatusPaid();
            $order->setOrderStatus($orderStatus);
            $order->setPaymentDate(new DateTime());
            $entityManager->persist($order);
            $entityManager->flush($order);

            // Clear shopping cart
            $logger->info('[注文処理] カートをクリアします.', [$order->getId()]);
            $elepayHelper->cartClear();

            // Save the order ID into Session. The shopping_complete page needs Session to get the order
            $session = $request->getSession();
            $session->set('eccube.front.shopping.order.id', $order->getId());

            $logger->info('[注文処理] 購入完了画面へ遷移します.', [$order->getId()]);
            return $app->redirect($app->url('shopping_complete'));
        }

        /** @var ElepayOrder $elepayOrder */
        // TODO Reusing charge Object is currently not supported
        $elepayOrder = null;
//        $elepayOrder = $elepayOrderRepository->findOneBy([
//            'order_id' => $order->getId()
//        ]);

        if (empty($elepayOrder)) {
            $elepayOrder = new ElepayOrder();
            $elepayOrder->setOrderId($order->getId());
        }

        $httpOrigin = (empty($_SERVER["HTTPS"]) ? "http://" : "https://") . $request->server->get('HTTP_HOST');
        $checkoutValidateUrl = $elepayHelper->addQuery(
            $httpOrigin . $app['config']['root'] . 'elepay_checkout_validate',
            [
                'orderNo' => $elepayHelper->getOrderNo($order)
            ]
        );

        try {
            $paymentObject = $elepayHelper->createCodeObject($order, $checkoutValidateUrl);
            $redirectUrl = $elepayHelper->addQuery(
                $paymentObject['codeUrl'],
                [
                    'mode' => 'auto',
                    'locale' => $request->getLocale()
                ]
            );
            return $app->redirect($redirectUrl);
        } catch (ApiException $e) {
            $logger->error('[注文処理] Exception when calling CodeApi->createCode::' . $e->getMessage(), [$order->getId()]);
            return $app->redirect($app->url('shopping_error'));
        } catch (InvalidArgumentException $e) {
            $logger->error('[注文処理] Exception when calling CodeApi->createCode::' . $e->getMessage(), [$order->getId()]);
            return $app->redirect($app->url('shopping_error'));
        }
    }

    /**
     * Checkout Success
     *
     * @Route("/elepay_checkout_validate", name="elepay_checkout_validate")
     *
     * @param Application $app
     * @param Request $request
     *
     * @return RedirectResponse
     * @throws Exception
     * @throws GuzzleException
     */
    public function checkoutValidate(Application $app, Request $request)
    {
        /** @var EntityManager $entityManager */
        $entityManager = $app['orm.em'];
        /** @var ElepayHelper $elepayHelper */
        $elepayHelper = $app['eccube.elepay.service.helper'];
        /** @var LoggerService $logger */
        $logger = $app['eccube.elepay.service.logger'];

        $status = $request->query->get('status');
        $codeId = $request->query->get('codeId');
        $chargeId = $request->query->get('chargeId');
        $orderNo = $elepayHelper->parseOrderNo($request->query->get('orderNo'));

        /** @var Order $order */
        $order = $elepayHelper->getOrderByNo($orderNo);

        if (empty($order)) {
            $logger->error('[注文確認] 購入処理中の受注が存在しません.', [$order->getId()]);
            return $app->redirect($app->url('shopping_error'));
        }

        /** @var OrderStatus $orderStatusPaid */
        $orderStatusPaid = $elepayHelper->getOrderStatusPaid();
        $orderStatus = $order->getOrderStatus();

        if ($orderStatus === $orderStatusPaid) {
            $this->orderComplete($app, $request, $order);
            return $app->redirect($app->url('shopping_complete'));
        }

        if ($status === 'captured') {
            try {
                if (!empty($chargeId)) {
                    $chargeObject = $elepayHelper->getChargeObject($chargeId);
                } else if (!empty($codeId)) {
                    $codeObject = $elepayHelper->getCodeObject($codeId);
                    $chargeObject = $codeObject['charge'];
                }
            } catch (ApiException $e) {
                $logger->error('[注文確認] Exception when calling ChargeApi->retrieveCharge::' . $e->getMessage(), [$order->getId()]);
                return $app->redirect($app->url('shopping_error'));
            } catch (InvalidArgumentException $e) {
                $logger->error('[注文処理] Exception when calling ChargeApi->retrieveCharge::' . $e->getMessage(), [$order->getId()]);
                return $app->redirect($app->url('shopping_error'));
            }

            if (empty($chargeObject)) {
                $logger->error('[注文処理] ChangeObject is empty', [$order->getId()]);
                return $app->redirect($app->url('shopping_error'));
            }

            $result = $this->orderValidate($app, $order, $chargeObject);

            if ($result === 'success') {
                $this->orderComplete($app, $request, $order);
                $logger->info('[注文処理] 購入完了画面へ遷移します.', [$order->getId()]);
                return $app->redirect($app->url('shopping_complete'));
            } else {
                return $app->redirect($app->url('shopping_error'));
            }
        } else {
            // Roll back the order status
            /** @var OrderStatus $orderStatusProcessing */
            $orderStatusProcessing = $elepayHelper->getOrderStatusProcessing();
            $order->setOrderStatus($orderStatusProcessing);
            $entityManager->flush();

            if ($status === 'cancelled') {
                $logger->error('[注文確認] Order cancelled.', [$order->getId()]);
                return $app->redirect($app->url('shopping'));
            } else {
                $logger->error('[注文確認] Unknown error.', [$order->getId()]);
                return $app->redirect($app->url('shopping_error'));
            }
        }
    }

    /**
     * Webhook 処理を行う
     *
     * @Route("/elepay_paid_webhook", name="elepay_paid_webhook", methods={"POST"})
     *
     * @param Application $app
     * @param Request $request
     * @return Response
     * @throws ApiException
     */
    public function elepayWebhook(Application $app, Request $request)
    {
        /** @var ElepayHelper $elepayHelper */
        $elepayHelper = $app['eccube.elepay.service.helper'];
        /** @var LoggerService $logger */
        $logger = $app['eccube.elepay.service.logger'];
        /** @var EventDispatcher $eventDispatcher */
        $eventDispatcher = $app['eccube.event.dispatcher'];

        $logger->info('*****  Elepay Webhook start.  ***** ');

        $json = $request->getContent();
        $data = json_decode($json, true);
        $chargeId = $data['data']['object']['id'];
        $orderNo = $elepayHelper->parseOrderNo($data['data']['object']['orderNo']);
        $chargeObject = $elepayHelper->getChargeObject($chargeId);

        $logger->info('[注文確認] Order No :', [$orderNo]);

        /** @var Order $order */
        $order = $elepayHelper->getOrderByNo($orderNo);

        if (!empty($order)) {
            $result = $this->orderValidate($app, $order, $chargeObject);
        } else {
            $logger->error('[注文確認] 購入処理中の受注が存在しません.');
            $result = 'error';
        }

        if ($result === 'success') {
            $event = new EventArgs(
                array(
                    'orderId' => $order->getId(),
                ),
                $request
            );
            $eventDispatcher->dispatch(EccubeEvents::FRONT_SHOPPING_COMPLETE_INITIALIZE, $event);
            $logger->error('[注文完了] 注文完了.');
        }

        $logger->info('*****  Elepay Webhook end.  *****');
        return new Response($result, $result === 'success' ? 200 : 400);
    }

    /**
     * Refund Validate
     *
     * @Route("/elepay_admin_redirect", name="elepay_refund_validate")
     *
     * @param Application $app
     * @param Request $request
     *
     * @return RedirectResponse
     * @throws ApiException
     */
    public function adminRedirect(Application $app, Request $request)
    {
        /** @var ElepayHelper $elepayHelper */
        $elepayHelper = $app['eccube.elepay.service.helper'];

        $chargeId = $request->query->get('chargeId');
        $chargeObject = $elepayHelper->getChargeObject($chargeId);
        $redirectUrl = $app['config']['Elepay']['const']['admin_host'] .
            '/apps/' . $chargeObject['appId'] . '/gw/payment/charges/' . $chargeId;
        return $app->redirect($redirectUrl);
    }

    /**
     * test
     *
     * @Route("/elepay_test", name="elepay_test")
     * @Template("@Elepay/default/test.twig")
     *
     * @param Application $app
     * @param Request $request
     *
     * @return array
     */
    public function test(Application $app, Request $request)
    {
        return [
            'message' => 'elepay test',
            'request' => $request
        ];
    }

    private function orderComplete(Application $app, Request $request, Order $order)
    {
        /** @var ElepayHelper $elepayHelper */
        $elepayHelper = $app['eccube.elepay.service.helper'];
        /** @var LoggerService $logger */
        $logger = $app['eccube.elepay.service.logger'];

        // Clear shopping cart
        $logger->info('[注文処理] カートをクリアします.', [$order->getId()]);
        $elepayHelper->cartClear();

        // Save the order ID into Session. The shopping_complete page needs Session to get the order
        $session = $request->getSession();
        $session->set('eccube.front.shopping.order.id', $order->getId());
    }

    private function orderValidate(Application $app, Order $order, array $chargeObject)
    {
        /** @var EntityManager $entityManager */
        $entityManager = $app['orm.em'];
        /** @var ElepayHelper $elepayHelper */
        $elepayHelper = $app['eccube.elepay.service.helper'];
        /** @var LoggerService $logger */
        $logger = $app['eccube.elepay.service.logger'];
        /** @var ElepayOrderRepository $elepayOrderRepository */
        $elepayOrderRepository = $app['eccube.elepay.repository.order'];

        /** @var OrderStatus $orderStatusPaid */
        $orderStatusPaid = $elepayHelper->getOrderStatusPaid();
        $orderStatus = $order->getOrderStatus();

        if ($orderStatus === $orderStatusPaid) {
            return 'success';
        }

        $orderNo = $order->getId();
        $chargeOrderNo = $elepayHelper->parseOrderNo($chargeObject['orderNo']);
        if ($orderNo != $chargeOrderNo) {
            $logger->error('[注文確認] ERROR::Verify payment order error.' . PHP_EOL . '  ec[order_no] : ' . $orderNo . ' / elepay[order_no] : ' . $chargeOrderNo);
            return 'error';
        }

        $orderAmount = $order->getPaymentTotal();
        $chargeAmount = $chargeObject['amount'];

        if ($orderAmount != $chargeAmount) {
            $logger->error('[注文確認] Verify payment amount error.' . PHP_EOL . '  ec[payment_total] : ' . $orderAmount . ' / elepay[amount] : ' . $chargeAmount, [$order->getId()]);
            return 'error';
        }

        $chargeStatus = $chargeObject['status'];

        if ($chargeStatus !== 'captured') {
            $logger->error('[注文確認] Verify payment status error : status is ' . $chargeStatus, [$order->getId()]);
            return 'error';
        }

        $paymentMethodName = $chargeObject['paymentMethod'];
        if ($paymentMethodName === 'creditcard') {
            $paymentMethodName = 'creditcard_' . $chargeObject['cardInfo']['brand'];
        }

        $paymentMethods = $elepayHelper->getPaymentMethods();
        foreach ($paymentMethods as $paymentMethod) {
            if ($paymentMethod['key'] === $paymentMethodName) {
                $paymentMethodName = $paymentMethod['name'];
                break;
            }
        }

        // Change the order status to paid
        /** @var OrderStatus $orderStatus */
        $orderStatus = $elepayHelper->getOrderStatusPaid();
        $order->setOrderStatus($orderStatus);
        $order->setPaymentDate(new DateTime());
        $order->setPaymentMethod($paymentMethodName);
        $entityManager->persist($order);
        $entityManager->flush($order);

        /** @var ElepayOrder $elepayOrder */
        $elepayOrder = $elepayOrderRepository->findOneBy([
            'order_id' => $order->getId()
        ]);
        if (empty($elepayOrder)) {
            $elepayOrder = new ElepayOrder();
            $elepayOrder->setOrderId($order->getId());
        }
        $elepayOrder->setElepayChargeId($chargeObject['id']); // If paying using a widget, need to save the chargeId here
        $entityManager->persist($elepayOrder);
        $entityManager->flush($elepayOrder);

        // メール送信
        $logger->info('[注文処理] 注文メールの送信を行います.', [$order->getId()]);
        $elepayHelper->sendOrderMail($order);

        return 'success';
    }
}
