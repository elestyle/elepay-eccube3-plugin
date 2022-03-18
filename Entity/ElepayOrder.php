<?php

namespace Plugin\Elepay\Entity;

use Doctrine\ORM\Mapping as ORM;
use Eccube\Entity\AbstractEntity;

class ElepayOrder extends AbstractEntity
{
    private $id;

    private $order_id;

    private $elepay_charge_id;

    /**
     * {@inheritdoc}
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Set orderId.
     *
     * @param string|null $orderId
     *
     * @return $this
     */
    public function setOrderId($orderId = null)
    {
        $this->order_id = $orderId;

        return $this;
    }

    /**
     * Get orderId.
     *
     * @return string|null
     */
    public function getOrderId()
    {
        return $this->order_id;
    }

    /**
     * Set elepayChargeId.
     *
     * @param string|null $elepayChargeId
     *
     * @return $this
     */
    public function setElepayChargeId($elepayChargeId = null)
    {
        $this->elepay_charge_id = $elepayChargeId;

        return $this;
    }

    /**
     * Get elepayChargeId.
     *
     * @return string|null
     */
    public function getElepayChargeId()
    {
        return $this->elepay_charge_id;
    }
}
