<?php

namespace Plugin\Elepay\Entity;

use Doctrine\ORM\Mapping as ORM;
use Eccube\Entity\AbstractEntity;
use stdClass;

class ElepayConfig extends AbstractEntity
{
    private $id;

    private $public_key;

    private $secret_key;

    /**
     * Constructor
     * @param stdClass $params
     */
    public function __construct(stdClass $params)
    {
        $this->public_key = $params->public_key;
        $this->secret_key = $params->secret_key;
    }

    /**
     * {@inheritdoc}
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * {@inheritdoc}
     */
    public function getPublicKey()
    {
        return $this->public_key;
    }

    /**
     * {@inheritdoc}
     */
    public function setPublicKey($public_key)
    {
        $this->public_key = $public_key;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getSecretKey()
    {
        return $this->secret_key;
    }

    /**
     * {@inheritdoc}
     */
    public function setSecretKey($secret_key)
    {
        $this->secret_key = $secret_key;

        return $this;
    }

    /**
     * @return ElepayConfig
     */
    public static function createInitialConfig(): ElepayConfig
    {
        /** @var stdClass $params */
        $params = new stdClass();
        $params->public_key = '';
        $params->secret_key = '';
        return new static($params);
    }
}
