<?php

namespace Plugin\Elepay\Repository;

use Doctrine\ORM\EntityRepository;

class ElepayConfigRepository extends EntityRepository
{
    public function get($id = 1)
    {
        return $this->find($id);
    }
}