<?php

namespace Plugin\Elepay;

use Doctrine\ORM\EntityManager;
use Eccube\Application;
use Eccube\Entity\Order;
use Eccube\Entity\Payment;
use Eccube\Event\EventArgs;
use Eccube\Event\TemplateEvent;
use Eccube\Repository\OrderRepository;
use Eccube\Service\CartService;
use Eccube\Service\ShoppingService;
use Elepay\ApiException;
use Knp\Component\Pager\Pagination\SlidingPagination;
use Plugin\Elepay\Entity\ElepayConfig;
use Plugin\Elepay\Entity\ElepayOrder;
use Plugin\Elepay\Repository\ElepayOrderRepository;
use Plugin\Elepay\Service\ElepayHelper;
use Symfony\Component\Form\Form;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\HttpFoundation\Request;

class Event
{
    /**
     * @var Application
     */
    private $app;

    /**
     * @var object|null
     */
    private $elepayIdMap;

    public function __construct(Application $app) {
        $this->app = $app;
    }

    /**
     * Admin Order list page
     *
     * @param EventArgs $event
     */
    public function onOrderSearch(EventArgs $event)
    {
        $session = $event->getRequest()->getSession();
        $page_no = $session->get('eccube.admin.order.search.page_no', 1);
        $page_count = $session->get('eccube.admin.order.search.page_count', $this->app['config']['default_page_count']);
        $qb = $event['qb'];
        /** @var SlidingPagination $pagination */
        $pagination = $this->app['paginator']()->paginate(
            $qb,
            $page_no,
            $page_count
        );
        $items = $pagination->getItems();

        /** @var ElepayHelper $elepayHelper */
        $elepayHelper = $this->app['eccube.elepay.service.helper'];
        /** @var ElepayOrderRepository $elepayOrderRepository */
        $elepayOrderRepository = $this->app['eccube.elepay.repository.order'];

        $elepayIdMap = [];
        /** @var Order $order */
        foreach ($items as $order) {
            /** @var ElepayOrder $elepayOrder */
            $elepayOrder = $elepayOrderRepository->findOneBy([
                'order_id' => $order->getId()
            ]);
            if (empty($elepayOrder)) continue;
            $elepay_charge_id = $elepayOrder->getElepayChargeId();
            if (empty($elepay_charge_id)) continue;
            try {
                $chargeObject = $elepayHelper->getChargeObject($elepay_charge_id);
                switch ($chargeObject['status']) {
                    case 'captured':
                    case 'refunded':
                    case 'partially_refunded':
                        $elepayIdMap[$order->getId()] = $elepay_charge_id;
                        break;
                }
            } catch (ApiException $err) {}
        }
        $this->elepayIdMap = $elepayIdMap;
    }

    /**
     * Admin Order list page
     * Mount the Twig template
     *
     * @param TemplateEvent $event
     */
    public function onOrderIndex(TemplateEvent $event)
    {
        if ($this->elepayIdMap === null) return;

        $parameters = [
            'elepay_id_map' => $this->elepayIdMap
        ];

        $event->setParameters(array_merge($event->getParameters(), $parameters));
        $source = $event->getSource();

        if (preg_match('/\{%\s*block\s*javascript\s*%\}/', $source, $result)) {
            $search = $result[0];
            $snippet = file_get_contents($this->app['config']['plugin_realdir']. '/Elepay/Resource/template/admin/order.twig');
            $replace = $search.$snippet;
            $source = str_replace($search, $replace, $source);
        }

        $event->setSource($source);
        $this->elepayIdMap = null;
    }

    /**
     * 支付方法选择画面
     *
     * @param TemplateEvent $event
     */
    public function onShoppingIndex(TemplateEvent $event)
    {
        /** @var ElepayHelper $elepayHelper */
        $elepayHelper = $this->app['eccube.elepay.service.helper'];

        $parameters = $event->getParameters();
        $order = $parameters['Order'];
        /** @var Payment $payment */
        $payment = $order->getPayment();

        $event->setParameters(array_merge($parameters, [
            'payment_id' => $payment->getId(), // Shopping/info.twig
            'payment_method' => $payment->getMethod(), // Shopping/confirm_button.twig
            'payment_methods' => $elepayHelper->getPaymentMethods() // Shopping/info.twig
        ]));

        $source = $event->getSource();

        // Payment method list template rendering
        if (preg_match('/\{%\s*endblock\s*%\}/', $source, $result)) {
            $search = $result[0];
            $snippet = file_get_contents($this->app['config']['plugin_realdir']. '/Elepay/Resource/template/default/Shopping/info.twig');
            $replace = $snippet.$search;
            $source = str_replace($search, $replace, $source);
        }

        // EC-CUBE supports the ability to change the name of the payment method button
        // Modify the payment button script template rendering
//        if ($elepayHelper->isElepay($payment) && preg_match('/\{%\s*block\s*javascript\s*%\}/', $source, $result)) {
//            $search = $result[0];
//            $snippet = file_get_contents($this->app['config']['plugin_realdir']. '/Elepay/Resource/template/default/Shopping/confirm_button.twig');
//            $replace = $search.$snippet;
//            $source = str_replace($search, $replace, $source);
//        }

        $event->setSource($source);
    }

    public function onControllerShoppingConfirmBefore($event = null) {
        /** @var EntityManager $entityManager */
        $entityManager = $this->app['orm.em'];
        /** @var CartService $cartService */
        $cartService = $this->app['eccube.service.cart'];
        /** @var ShoppingService $shoppingService */
        $shoppingService = $this->app['eccube.service.shopping'];
        /** @var ElepayHelper $elepayHelper */
        $elepayHelper = $this->app['eccube.elepay.service.helper'];
        /** @var OrderRepository $orderRepository */
        $orderRepository = $this->app['eccube.repository.order'];

        /** @var Request $request */
        $request = $this->app['request'];
        $session = $request->getSession();
        $nonMember = $session->get('eccube.front.shopping.nonmember');

        if ($this->app->isGranted('ROLE_USER') || !is_null($nonMember)) {
            $pre_order_id = $cartService->getPreOrderId();

            if (empty($pre_order_id)) return;

            /** @var Order $order */
            $order = $orderRepository->findOneBy([ 'pre_order_id' => $pre_order_id ]);
            /** @var FormBuilderInterface $builder */
            $builder = $shoppingService->getShippingFormBuilder($order);
            /** @var Form $form */
            $form = $builder->getForm();

            if ('POST' === $this->app['request']->getMethod()) {
                $form->handleRequest($this->app['request']);

                if ($form->isValid()) {
                    $formData = $form->getData();
                    /** @var Payment $payment */
                    $payment = $formData['payment'];

                    if ($elepayHelper->isElepay($payment)) {
                        // 受注情報、配送情報を更新（決済処理中として更新する）
                        $shoppingService->setOrderUpdateData($order);
                        $order->setOrderDate(null);
                        $order->setOrderStatus($elepayHelper->getOrderStatusPending());
                        $entityManager->persist($order);
                        $entityManager->flush();

                        $url = $this->app->url('elepay_checkout');

                        if ($event instanceof \Symfony\Component\HttpKernel\Event\KernelEvent) {
                            $response = $this->app->redirect($url);
                            $event->setResponse($response);
                            return;
                        } else {
                            header('Location: ' . $url);
                            exit;
                        }
                    }
                }
            }
        }
    }
}
