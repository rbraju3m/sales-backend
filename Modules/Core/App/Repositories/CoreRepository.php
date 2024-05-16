<?php

namespace Modules\Core\App\Repositories;
use Doctrine\ORM\EntityRepository;
use Illuminate\Support\Facades\DB;


/**
 *
 * This class was generated by the Doctrine ORM. Add your own custom
 * repository methods below.
 */
class CoreRepository extends EntityRepository{


    public function userAutoComplete($domain, $term, $type = 'NULL')
    {
        $entity = DB::table("users as e")
            ->select(DB::raw("CONCAT(e.username, ' - ', e.email) AS name"),'e.id as id')
          //  ->where('e.is_delete', 0)
           // ->where('e.domain_id', $domain)
            ->where(function ($query) use ($term) {
                $query->orWhere('e.username','LIKE','%'.$term.'%')
                    ->orWhere('e.name','LIKE','%'.$term.'%');
            })
            ->limit(5000)->get();
        return $entity;
    }

    public function customerAutoComplete($domain, $term, $type = 'NULL')
    {
        $entity = DB::table("cor_customers as e")
            ->select(DB::raw("CONCAT(e.mobile, ' - ', e.name) AS name"),'e.id as id')
            ->where('e.domain_id', $domain)
            ->where(function ($query) use ($term) {
                $query->orWhere('e.name','LIKE','%'.$term.'%')
                    ->orWhere('e.mobile','LIKE','%'.$term.'%');
            })
            ->limit(5000)->get();
        return $entity;
    }

    public function vendorAutoComplete($domain, $term, $type = 'NULL')
    {
        $entity = DB::table("cor_vendors as e")
            ->select(DB::raw("CONCAT(e.mobile, ' - ', e.name) AS name"),'e.id as id')
        //    ->where('e.status', 1)
            ->where('e.domain_id', $domain)
            ->where(function ($query) use ($term) {
                $query->orWhere('e.name','LIKE','%'.$term.'%')
                    ->orWhere('e.mobile','LIKE','%'.$term.'%');
            })
            ->limit(5000)->get();
        return $entity;
    }

    public function locationAutoComplete($term)
    {

        $entity = DB::table("cor_locations as e")
            ->select(DB::raw("e.name AS name"),'e.id as id')
            ->where('e.level', 2)
            ->where(function ($query) use ($term) {
                $query->orWhere('e.name','LIKE','%'.$term.'%');
            })
            ->orderBy('name','ASC')
            ->limit(5000)->get();
        return $entity;

    }

}