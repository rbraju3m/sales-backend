<?php

namespace Modules\Inventory\App\Repositories;
use Doctrine\ORM\EntityRepository;
use Modules\Domain\App\Entities\GlobalOption;
use Modules\Inventory\App\Entities\Config;
use Modules\Inventory\App\Entities\InvoiceBatchItem;
use Modules\Inventory\App\Entities\InvoiceBatchTransaction;
use Modules\Inventory\App\Entities\Setting;
use Modules\Inventory\App\Entities\StockItem;


/**
 * ItemTypeGroupingRepository
 *
 * This class was generated by the Doctrine ORM. Add your own custom
 * repository methods below.
 */
class SettingRepository extends EntityRepository
{
    public function insertUpdate(GlobalOption $domain,$row)
    {
        $em = $this->_em;
        $config = $em->getRepository(Config::class)->findOneBy(['domain' => $domain]);
        $exist = $this->findOneBy(['config' => $config,'setting' => $row]);

        /* @var $setting \Modules\Utility\App\Entities\Setting */
        $setting = $em->getRepository(\Modules\Utility\App\Entities\Setting::class)->find($row);
        if(empty($exist)){
            $entity = new Setting();
            $entity->setConfig($config);
            $entity->setSetting($setting);
            $entity->setName($setting->getName());
            $entity->setSlug($setting->getSlug());
            $entity->setCreatedAt(new \DateTime());
            $entity->setUpdatedAt(new \DateTime());
            $em->persist($entity);
            $em->flush();
        }else{
            $exist->setName($setting->getName());
            $exist->setSlug($setting->getSlug());
            $em->persist($exist);
            $em->flush();
        }
    }
}