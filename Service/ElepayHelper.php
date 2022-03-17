<?php

namespace Plugin\Elepay\Service;

require_once(__DIR__ . '/../Resource/vendor/autoload.php');

use Eccube\Application;
use Eccube\Entity\Order;
use Eccube\Entity\Payment;
use Eccube\Repository\Master\OrderStatusRepository;
use Eccube\Repository\OrderRepository;
use Eccube\Service\CartService;
use Eccube\Service\MailService;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Request;
use Elepay\Api\CodeApi;
use Elepay\Api\ChargeApi;
use Elepay\Api\CodeSettingApi;
use Elepay\ApiException;
use Elepay\Configuration;
use Elepay\Model\CodeDto;
use Elepay\Model\CodeReq;
use Elepay\Model\ChargeDto;
use Elepay\Model\CodePaymentMethodResponse;
use InvalidArgumentException;
use Plugin\Elepay\Entity\ElepayConfig;

class ElepayHelper
{
    /**
     * @var Application
     */
    protected $app;

    /**
     * @var OrderRepository
     */
    protected $orderRepository;

    /**
     * @var OrderStatusRepository
     */
    protected $orderStatusRepository;

    /**
     * @var CartService
     */
    protected $cartService;

    /**
     * @var MailService
     */
    protected $mailService;

    /**
     * @var Client
     */
    protected $httpClient;

    protected $elepayPluginConfig;

    public function __construct(Application $app) {
        $this->app = $app;

        $this->orderRepository = $app['eccube.repository.order'];
        $this->orderStatusRepository = $app['eccube.repository.order_status'];
        $this->cartService = $app['eccube.service.cart'];
        $this->mailService = $app['eccube.service.mail'];
        $this->elepayPluginConfig = $app['config']['Elepay']['const'];

        $this->httpClient = new Client();
    }

    private function getElepaySDKConfig()
    {
        /* @var ElepayConfig $config */
        $config = $this->app['eccube.elepay.repository.config']->get();

        $secretKey = $config->getSecretKey();
        $elepayApiHost = $this->elepayPluginConfig['api_host'];

        return Configuration::getDefaultConfiguration()
            ->setUsername($secretKey)
            ->setPassword('')
            ->setHost($elepayApiHost);
    }

    /**
     * 決済処理中の受注を取得する.
     *
     * @return null|object
     */
    public function getCartOrder()
    {
        $preOrderId = $this->cartService->getPreOrderId();
        return $this->orderRepository->findOneBy([
            'pre_order_id' => $preOrderId
        ]);
    }

    /**
     * Cart Clear
     *
     * @return void
     */
    public function cartClear()
    {
        return $this->cartService->clear()->save();
    }

    /**
     * 注文完了メールを送信する.
     *
     * @param Order $order
     * @return string
     */
    public function sendOrderMail($order) {
        return $this->mailService->sendOrderMail($order);
    }

    /**
     * 受注をIDで検索する.
     *
     * @param String $orderNo
     *
     * @return null|object
     */
    public function getOrderByNo($orderNo)
    {
        return $this->orderRepository->findOneBy([
            'id' => $orderNo,
        ]);
    }

    /**
     * Returns PROCESSING order status object
     *
     * @return object|null
     */
    public function getOrderStatusProcessing()
    {
        return $this->orderStatusRepository->find($this->app['config']['order_processing']);
    }

    /**
     * Returns PENDING order status object
     *
     * @return object|null
     */
    public function getOrderStatusPending()
    {
        return $this->orderStatusRepository->find($this->app['config']['order_pending']);
    }

    /**
     * Returns PAID order status object
     *
     * @return object|null
     */
    public function getOrderStatusPaid()
    {
        return $this->orderStatusRepository->find($this->app['config']['order_pre_end']);
    }

    /**
     * Create Code Object
     *
     * @param Order $order
     * @param string $frontUrl
     * @return array
     * @throws ApiException
     * @throws InvalidArgumentException
     */
    public function createCodeObject($order, $frontUrl)
    {
        /** @var CodeReq $codeReq */
        $codeReq = new CodeReq();
        $codeReq->setOrderNo($this->getOrderNo($order));
        $codeReq->setAmount((integer)$order->getPaymentTotal());
        //$codeReq->setCurrency($order->getCurrencyCode());
        $codeReq->setFrontUrl($frontUrl);

        /** @var CodeApi $codeApi */
        $codeApi = new CodeApi(null, $this->getElepaySDKConfig());
        /** @var CodeDto $codeDto */
        $codeDto = $codeApi->createCode($codeReq);
        $json = (string)$codeDto;
        return json_decode($json, true);
    }

    /**
     * Get Code Object
     *
     * @param string $codeId
     * @return array
     * @throws ApiException
     */
    public function getCodeObject($codeId)
    {
        /** @var CodeApi $codeApi */
        $codeApi = new CodeApi(null, $this->getElepaySDKConfig());
        /** @var CodeDto $codeDto */
        $codeDto = $codeApi->retrieveCode($codeId);
        $json = (string)$codeDto;
        return json_decode($json, true);
    }

    /**
     * Verify Charge Object
     *
     * @param string $chargeId
     * @return array
     * @throws ApiException
     */
    public function getChargeObject($chargeId)
    {
        /** @var ChargeApi $chargeApi */
        $chargeApi = new ChargeApi(null, $this->getElepaySDKConfig());
        /** @var ChargeDto $chargeDto */
        $chargeDto = $chargeApi->retrieveCharge($chargeId);
        $json = (string)$chargeDto;
        return json_decode($json, true);
    }

    /**
     * @param Order $order
     * @return string
     */
    public function getOrderNo($order)
    {
        // Since the ECCUBE orderNo is an increment number, Create Charge will fail if a database reset occurs
        // Add preOrderId here to prevent duplicate order numbers
        return $order->getId() . '-' . date('His');
    }

    /**
     * @param string $orderNo
     * @return string
     */
    public function parseOrderNo($orderNo)
    {
        return explode('-', $orderNo)[0] ?? $orderNo;
    }

    /**
     * @return array
     * @throws GuzzleException
     */
    public function getPaymentMethods()
    {
        try {
            $url = $this->elepayPluginConfig['payment_methods_info_url'];
            $headers = ['Content-Type' => 'application/json'];
            $request = new Request(
                'GET',
                $url,
                $headers
            );

            $response = $this->httpClient->send($request);
            $content = $response->getBody()->getContents();
            /**
             * $paymentMethodMap data structure
             * {
             *   "alipay": {
             *     "name": {
             *       "ja": "アリペイ",
             *       "en": "Alipay",
             *       "zh-CN": "支付宝",
             *       "zh-TW": "支付寶"
             *     },
             *     "image": {
             *       "short": "https://resource.elecdn.com/payment-methods/img/alipay.svg",
             *       "long": "https://resource.elecdn.com/payment-methods/img/alipay_long.svg"
             *     }
             *   },
             *   ...
             * }
             */
            $paymentMethodMap = json_decode($content, true);

            /** @var CodeSettingApi $codeSettingApi */
            $codeSettingApi = new CodeSettingApi(null, $this->getElepaySDKConfig());
            /** @var CodePaymentMethodResponse $codePaymentMethodResponse */
            $codePaymentMethodResponse = $codeSettingApi->listCodePaymentMethods();
            $json = (string)$codePaymentMethodResponse;
            /**
             * $availablePaymentMethods data structure
             * [
             *   {
             *     "paymentMethod": "alipay",
             *     "resources": [ "ios", "android", "web" ],
             *     "brand": [],
             *     "ua": "",
             *     "channelProperties": {}
             *   },
             *   ...
             * ]
             */
            $availablePaymentMethods = json_decode($json, true)['paymentMethods'];

            $paymentMethods = [];
            foreach ($availablePaymentMethods as $item) {
                $key = $item['paymentMethod'];
                $paymentMethodInfo = $paymentMethodMap[$key];

                if (
                    empty($key) ||
                    empty($paymentMethodInfo) ||
                    empty($item['resources']) ||
                    !in_array('web', $item['resources'])
                ) continue;

                if ($key === 'creditcard') {
                    foreach ($item['brand'] as $brand) {
                        $key = 'creditcard_' . $brand;
                        $paymentMethodInfo = $paymentMethodMap[$key];
                        $paymentMethods []= $this->getPaymentMethodInfo($key, $paymentMethodInfo, $item);
                    }
                } else {
                    $paymentMethods []= $this->getPaymentMethodInfo($key, $paymentMethodInfo, $item);
                }
            }
        } catch (Exception $e) {
            $paymentMethods = [];
        }

        return $paymentMethods;
    }

    private function getPaymentMethodInfo ($key, $paymentMethodInfo, $metaData)
    {
        return [
            'key' => $key,
            'name' => $paymentMethodInfo['name']['ja'],
            'image' => $paymentMethodInfo['image']['short'],
            'min' => null,
            'max' => null,
            'ua' => empty($metaData['ua']) ? '' : $metaData['ua']
        ];
    }

    public function addQuery($url, $params)
    {
        foreach ($params as $key => $value) {
            $url = $this->addQueryArg($url, $key, $value);
        }

        return $url;
    }

    public function addQueryArg($url, $key, $value)
    {
        $url = preg_replace('/(&)(#038;)?/', '$1', $url);
        preg_match('/(.*)([?&])' . $key . '=[^&]+?(&)(.*)/i', $url . '&', $match);
        if (!empty($match)) {
            $url = $match[1] . $match[2] . $key . '=' . $value . '&' . $match[4];
            $url = substr($url, 0, -1);
        } elseif (strstr($url, '?')) {
            if (preg_match('/(\?|&)$/', $url)) {
                $url .= $key . '=' . $value;
            } else {
                $url .= '&' . $key . '=' . $value;
            }
        } else {
            $url .= '?' . $key . '=' . $value;
        }
        return $url;
    }

    /**
     * Determine whether the payment method is Elepay
     * @param Payment $payment
     * @return bool
     */
    public function isElepay($payment)
    {
        return $payment->getMethod() === $this->elepayPluginConfig['name'];;
    }
}
