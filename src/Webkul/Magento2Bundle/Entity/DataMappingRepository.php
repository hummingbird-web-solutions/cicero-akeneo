<?php

namespace Webkul\Magento2Bundle\Entity;

use Doctrine\Common\Collections\Criteria;

/**
 * Magento2MappingRepository
 *
 * This class was generated by the Doctrine ORM. Add your own custom
 * repository methods below.
 */
class DataMappingRepository extends \Doctrine\ORM\EntityRepository
{
    public function getOptionsByAttributeCode($code)
    {
        $qb = $this->getEntityManager()->createQueryBuilder();
        $qb->select('m')
            ->from($this->getEntityName(), 'm')
            ->andWhere('m.entityType = :option')
            ->setParameter('option', 'option')
            ->andWhere('m.code LIKE :code')
            ->setParameter('code', '%'. $code .'%')
            ->orderby('m.externalId', Criteria::ASC);

        return $qb->getQuery()->getResult();
    }

    public function getOptionsByAttributeCodeAndApiUrl($code, $apiUrl)
    {
        $qb = $this->getEntityManager()->createQueryBuilder();
        $qb->select('m')
            ->from($this->getEntityName(), 'm')
            ->andWhere('m.entityType = :option')
            ->setParameter('option', 'option')
            ->andWhere('m.code LIKE :code')
            ->andWhere('m.apiUrl = :url')
            ->setParameter('code', '%'. $code .'%')
            ->setParameter('url', $apiUrl)
            ->orderby('m.externalId', Criteria::ASC);

        return $qb->getQuery()->getResult();
    }
}
