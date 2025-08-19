<?php

namespace Modules\Core\App\Http\Controllers;

use App\Http\Controllers\Controller;
use Doctrine\ORM\EntityManagerInterface;
use Illuminate\Http\Request;
use Modules\AppsApi\App\Services\JsonRequestResponse;
use Modules\Core\App\Entities\Core;
use Modules\Core\App\Models\CountryModel;
use Modules\Core\App\Models\SettingModel;
use Modules\Core\App\Models\SettingTypeModel;
use Modules\Core\App\Models\UserModel;

class CoreController extends Controller
{

    protected $domain;

    public function __construct(Request $request)
    {
        $userId = $request->header('X-Api-User');
        if ($userId && !empty($userId)){
            $userData = UserModel::getUserData($userId);
            $this->domain = $userData;
        }
    }

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return view('core::index');
    }

    /**
     * Show the form for creating a new resource.
     */
    public function user(Request $request,EntityManagerInterface $em)
    {
        $term = $request['term'];
        $service = new JsonRequestResponse();
        $go = $this->domain['global_id'];
        $entities = $em->getRepository(Core::class)->userAutoComplete($go,$term);
        $data = $service->returnJosnResponse($entities);
        return $data;
    }

    /**
     * Show the form for creating a new resource.
     */
    public function executive(Request $request,EntityManagerInterface $em)
    {

        $term = $request['term'];
        $service = new JsonRequestResponse();
        $go = $this->domain['global_id'];
        $entities = $em->getRepository(Core::class)->userAutoComplete( $go,$term);
        $data = $service->returnJosnResponse($entities);
        return $data;
    }

    /**
     * Show the form for creating a new resource.
     */
    public function customer(Request $request,EntityManagerInterface $em)
    {

        $term = $request['term'];
        $service = new JsonRequestResponse();
        $go = $this->domain['global_id'];
        $entities = $em->getRepository(Core::class)->customerAutoComplete($go,$term);
        $data = $service->returnJosnResponse($entities);
        return $data;
    }

    /**
     * Show the form for creating a new resource.
     */
    public function vendor(Request $request,EntityManagerInterface $em)
    {

        $term = $request['term'];
        $service = new JsonRequestResponse();
        $go = $this->domain['global_id'];
        $entities = $em->getRepository(Core::class)->vendorAutoComplete($go,$term);
        $data = $service->returnJosnResponse($entities);
        return $data;
    }

    /**
     * Show the form for creating a new resource.
     */
    public function location(Request $request,EntityManagerInterface $em)
    {

        $service = new JsonRequestResponse();
        $entities = SettingModel::getSettingDropdown(
            'location',
            $this->domain->global_id
        );
        //$entities = $em->getRepository(Core::class)->locationAutoComplete($go,$term);
        $data = $service->returnJosnResponse($entities);
        return $data;
    }

    /**
     * Show the form for creating a new resource.
     */
    public function settingDropdown(Request $request)
    {
        $entities = SettingModel::getSettingDropdown(
            $request['dropdown-type'],
            $this->domain->global_id
        );
        return (new JsonRequestResponse())->returnJosnResponse($entities);
    }

     /**
     * Show the form for creating a new resource.
     */
    public function settingTypeDropdown(Request $request)
    {
        $entities = SettingTypeModel::getSettingTypeDropdown();
        return (new JsonRequestResponse())->returnJosnResponse($entities);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function countriesDropdown(Request $request)
    {
        $entities = CountryModel::getCountryDropdown();
        return (new JsonRequestResponse())->returnJosnResponse($entities);
    }

}
