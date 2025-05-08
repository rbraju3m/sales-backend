<?php
/**
 * Created by PhpStorm.
 * User: shafiq
 * Date: 10/9/15
 * Time: 8:05 AM
 */

namespace Modules\Core\App\Repositories;

use Doctrine\ORM\EntityRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\Core\App\Filters\CustomerFilter;
use Modules\Core\App\Models\CustomerModel;
use Modules\Domain\App\Entities\GlobalOption;

class CustomerRepository extends EntityRepository {

    public function listWithSearch(array $queryParams = [])
    {

        $page = (isset($queryParams['page']) and $queryParams['page'] ) ? $queryParams['page']:1;
        $limit = (isset($queryParams['limit']) and $queryParams['limit'] ) ? $queryParams['limit']:200;
        $queryBuilder = CustomerModel::with(['location'])->select('id','name','mobile','created_at')->orderBy('created_at','DESC')->limit(200);
        if ($queryParams){
            $queryBuilder->where(function ($query) use ($queryParams) {
                $query->orWhere('name','LIKE','%'.$queryParams['term'].'%')
                    ->orWhere('mobile','LIKE','%'.$queryParams['term'].'%');
            });
        }
        $results =$queryBuilder->get()->toArray();
        /*$data = Cache::remember('Customer'.$page, 200, function() use ($queryParams,$limit){
            $queryBuilder = CustomerModel::with(['location'])->select('id','name','mobile','created_at')->orderBy('created_at','DESC')->limit(2000);
            $query = resolve(CustomerFilter::class)->getResults([
                'builder' => $queryBuilder,
                'params' => $queryParams,
                'limit' => $limit
            ]);
            return $queryBuilder;
        });*/
        return $results;

    }




    public function searchPatientAutoComplete(GlobalOption $globalOption, $q, $type = 'NULL')
    {
        $query = $this->createQueryBuilder('e');
        $query->select('e.id as id');
        $query->addSelect('e.id as customer');
        $query->addSelect('CONCAT(e.customer_id, \' - \',e.mobile, \' - \', e.name) AS text');
        $query->where($query->expr()->like("e.mobile", "'$q%'"  ));
        $query->orWhere($query->expr()->like("e.name", "'%$q%'"  ));
        $query->orWhere($query->expr()->like("e.customer_id", "'%$q%'"  ));
        $query->andWhere("e.globalOption = :globalOption");
        $query->setParameter('globalOption', $globalOption->getId());
        $query->andWhere('e.status=1');
        $query->orderBy('e.name', 'ASC');
        $query->groupBy('e.mobile');
        $query->setMaxResults( '20' );
        return $query->getQuery()->getResult();

    }

    public function searchMobileAutoComplete(GlobalOption $globalOption, $q, $type = 'NULL')
    {
        $query = $this->createQueryBuilder('e');

        $query->select('e.mobile as id');
        $query->addSelect('e.id as customer');
        $query->addSelect('CONCAT(e.mobile, \'-\', e.name) AS text');
        $query->where($query->expr()->like("e.mobile", "'$q%'"  ));
        $query->andWhere("e.globalOption = :globalOption");
        $query->setParameter('globalOption', $globalOption->getId());
        $query->orderBy('e.mobile', 'ASC');
        $query->groupBy('e.mobile,e.name');
        $query->setMaxResults( '10' );
        return $query->getQuery()->getResult();

    }

    public function searchCustomerAutoComplete(GlobalOption $globalOption, $q, $type = 'NULL')
    {
        $query = $this->createQueryBuilder('e');
        $query->select('e.name as id');
        $query->addSelect('e.id as name');
        $query->addSelect('e.name as text');
        $query->where($query->expr()->like("e.mobile", "'$q%'"  ));
        $query->andWhere("e.globalOption = :globalOption");
        $query->setParameter('globalOption', $globalOption->getId());
        $query->orderBy('e.name', 'ASC');
        $query->groupBy('e.mobile,e.name');
        $query->setMaxResults( '10' );
        return $query->getQuery()->getResult();

    }

    public function searchAutoCompleteName(GlobalOption $globalOption, $q)
    {
        $query = $this->createQueryBuilder('e');
        $query->select('e.name as id');
        $query->addSelect('e.id as customer');
        $query->addSelect('e.name as text');
        $query->where($query->expr()->like("e.name", "'$q%'"  ));
        $query->andWhere("e.globalOption = :globalOption");
        $query->setParameter('globalOption', $globalOption->getId());
        $query->groupBy('e.name');
        $query->orderBy('e.name', 'ASC');
        $query->setMaxResults( '10' );
        return $query->getQuery()->getResult();

    }

    public function searchAutoCompleteCode(GlobalOption $globalOption, $q)
    {
        $query = $this->createQueryBuilder('e');

        $query->select('e.mobile as id');
        $query->addSelect('e.id as customer');
        $query->addSelect('e.customer_id as text');
        //$query->addSelect('CONCAT(e.customerId, " - ", e.name) AS text');
        $query->where($query->expr()->like("e.customer_id", "'$q%'"  ));
        $query->andWhere("e.globalOption = :globalOption");
        $query->setParameter('globalOption', $globalOption->getId());
        $query->orderBy('e.customer_id', 'ASC');
        $query->setMaxResults( '10' );
        return $query->getQuery()->getResult();

    }



}
