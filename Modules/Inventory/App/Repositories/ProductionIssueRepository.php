<?php

namespace Modules\Inventory\App\Repositories;
use Doctrine\ORM\EntityRepository;
use Modules\Inventory\App\Entities\Item;

/**
 * DamageRepository
 *
 * This class was generated by the Doctrine ORM. Add your own custom
 * repository methods below.
 */
class ProductionIssueRepository extends EntityRepository
{

    public function findWithSearch($config,$parameter,$query)
    {
        if (!empty($parameter['orderBy'])) {
            $sortBy = $parameter['orderBy'];
            $order = $parameter['order'];
        }

        $name             = isset($data['name'])? $data['name'] :'';
        $process           = isset($data['process'])? $data['process'] :'';
        $startDate           = isset($data['startDate'])? $data['startDate'] :'';
        $endDate           = isset($data['endDate'])? $data['endDate'] :'';

        $qb = $this->createQueryBuilder('item');
        $qb->select('item.id as id','item.name as name','item.uom as uom','item.subTotal as subTotal','item.purchasePrice as purchasePrice',"item.totalQuantity as quantity","item.process as process","item.created as created");
        $qb->where("item.config = :config")->setParameter('config', $config);
        if (!empty($item)) {
            $qb->andWhere("item.name LIKE :name")->setParameter('name', "%{$invoice}%");
        }
        if (!empty($name)) {
            $qb->andWhere("name.id = :nameId")->setParameter('nameId', $name);
        }
        if (!empty($process)) {
            $qb->andWhere("item.process = :process")->setParameter('process', $process);
        }

        if (!empty($startDate)) {
            $datetime = new \DateTime($startDate);
            $start = $datetime->format('Y-m-d 00:00:00');
            $qb->andWhere("item.created >= :startDate")->setParameter('startDate',$start);
        }
        if (!empty($endDate)) {
            $datetime = new \DateTime($endDate);
            $end = $datetime->format('Y-m-d 23:59:59');
            $qb->andWhere("item.created <= :endDate")->setParameter('endDate',$end);
        }

        $qb->setFirstResult($parameter['offset']);
        $qb->setMaxResults($parameter['limit']);
        if ($parameter['orderBy']){
            $qb->orderBy($sortBy, $order);
        }else{
            $qb->orderBy('item.created', 'DESC');
        }
        $result = $qb->getQuery()->getArrayResult();
        return  $result;

    }

    public function getIssueItem(Item $item)
    {
        $qb = $this->createQueryBuilder('e');
        $qb->select('SUM(e.totalQuantity) AS quantity');
        $qb->where('e.item = :particular')->setParameter('particular', $item->getId());
        $qnt = $qb->getQuery()->getOneOrNullResult();
        $productionQnt = ($qnt['quantity'] == 'NULL') ? 0 : $qnt['quantity'];
        return $productionQnt;

    }


}