<?php

namespace Webkul\Magento2GroupProductBundle\Repository;

/**
 * JobDataMappingRepository
 *
 * This class was generated by the Doctrine ORM. Add your own custom
 * repository methods below.
 */
class JobDataMappingRepository extends \Doctrine\ORM\EntityRepository
{
    public function getAllMappingIdentifiersByType(string $mappingType, string $jobInstanceId)
    {
        $results = $this->createQueryBuilder('jdm')
                ->select('jdm.productIdentifier as sku')
                ->where('jdm.mappingType = :mappingType')
                ->andWhere('jdm.jobInstanceId = :jobInstanceId')
                ->setParameters(['mappingType' => $mappingType, 'jobInstanceId' => $jobInstanceId])
                ->getQuery()->getResult();
            
        return $results;
    }
}
