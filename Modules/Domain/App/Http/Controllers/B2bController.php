<?php

namespace Modules\Domain\App\Http\Controllers;

use App\Http\Controllers\Controller;
use Doctrine\ORM\EntityManager;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Accounting\App\Entities\AccountHead;
use Modules\Accounting\App\Models\AccountingModel;
use Modules\Accounting\App\Models\TransactionModeModel;
use Modules\AppsApi\App\Services\GeneratePatternCodeService;
use Modules\AppsApi\App\Services\JsonRequestResponse;
use Modules\Core\App\Models\CustomerModel;
use Modules\Core\App\Models\SettingModel;
use Modules\Core\App\Models\SettingTypeModel;
use Modules\Core\App\Models\UserModel;
use Modules\Core\App\Models\VendorModel;
use Modules\Domain\App\Entities\DomainChild;
use Modules\Domain\App\Entities\GlobalOption;
use Modules\Domain\App\Entities\SubDomain;
use Modules\Domain\App\Http\Requests\DomainRequest;
use Modules\Domain\App\Models\CurrencyModel;
use Modules\Domain\App\Models\DomainModel;
use Modules\Domain\App\Models\SubDomainModel;
use Modules\Inventory\App\Entities\Setting;
use Modules\Inventory\App\Models\ConfigModel;
use Modules\Inventory\App\Models\PurchaseModel;
use Modules\Inventory\App\Models\SalesModel;
use Modules\Inventory\App\Models\SettingModel as InventorySettingModel;
use Modules\Inventory\App\Models\StockItemModel;
use Modules\NbrVatTax\App\Models\NbrVatModel;
use Modules\Production\App\Models\ProductionConfig;
use Modules\Utility\App\Models\SettingModel as UtilitySettingModel;
use Symfony\Component\HttpFoundation\Response as ResponseAlias;

class B2bController extends Controller
{
    protected $domain;

    public function __construct(Request $request)
    {
        $entityId = $request->header('X-Api-User');
        if ($entityId && !empty($entityId)){
            $entityData = UserModel::getUserData($entityId);
            $this->domain = $entityData;
        }
    }

    public function domainInlineUpdate(Request $request)
    {
        $validated = $request->validate([
            'domain_id'  => 'required|integer',
            'field_name' => 'required|string',
            'value'      => 'required',
        ]);

        $domainId   = $validated['domain_id'];
        $fieldName  = $validated['field_name'];
        $value      = $validated['value'];
        $globalDomainId = $this->domain['global_id'];

        $findDomain = DomainModel::find($domainId);
        if (!$findDomain) {
            return response()->json(['message' => 'Domain not found', 'status' => ResponseAlias::HTTP_NOT_FOUND], ResponseAlias::HTTP_NOT_FOUND);
        }

        // Assuming $this->domain is defined globally
        if (!isset($globalDomainId)) {
            return response()->json(['message' => 'Global domain context missing', 'status' => ResponseAlias::HTTP_INTERNAL_SERVER_ERROR], ResponseAlias::HTTP_INTERNAL_SERVER_ERROR);
        }

        try {
            switch ($fieldName) {
                case 'domain_type':
                    $subDomain = SubDomainModel::firstOrCreate(
                        [
                            'domain_id'     => $globalDomainId,
                            'sub_domain_id' => $domainId,
                        ],
                        ['status' => 0]
                    );
                    $subDomain->update(['domain_type' => $value]);
                    break;

                case 'status':
                    $subDomain = SubDomainModel::where('domain_id', $globalDomainId)
                        ->where('sub_domain_id', $domainId)
                        ->first();

                    if (!$subDomain) {
                        return response()->json([
                            'message' => 'Assign domain type before updating status',
                            'status'  => ResponseAlias::HTTP_NOT_FOUND
                        ], ResponseAlias::HTTP_NOT_FOUND);
                    }

                    $subDomain->update(['status' => $value]);
                    break;

                default:
                    return response()->json([
                        'message' => 'Field not supported',
                        'status'  => ResponseAlias::HTTP_BAD_REQUEST
                    ], ResponseAlias::HTTP_BAD_REQUEST);
            }

            return response()->json([
                'message' => 'Success',
                'status'  => ResponseAlias::HTTP_OK
            ], ResponseAlias::HTTP_OK);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Something went wrong',
                'error'   => $e->getMessage(),
                'status'  => ResponseAlias::HTTP_INTERNAL_SERVER_ERROR
            ], ResponseAlias::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Display a listing of the resource.
     */

    public function index(Request $request){

        $data = DomainModel::getRecords($request);
        $response = new Response();
        $response->headers->set('Content-Type','application/json');
        $response->setContent(json_encode([
            'message' => 'success',
            'status' => Response::HTTP_OK,
            'total' => $data['count'],
            'data' => $data['entities']
        ]));
        $response->setStatusCode(Response::HTTP_OK);
        return $response;
    }

    /**
     * Store a newly created resource in storage.
     */

    public function store(DomainRequest $request , EntityManager $em,GeneratePatternCodeService $patternCodeService)
    {
        $data = $request->validated();

        // Start the transaction
        DB::beginTransaction();

        try {
            // Step 1: Create the domain the entity
            $data['modules'] = json_encode($data['modules'], JSON_PRETTY_PRINT);
            $entity = DomainModel::create($data);

            // Step 2: Prepare email and password, then create user
            $password = "@123456";
            $email = $data['email'] ?? "{$data['username']}@gmail.com"; // If email is not present, default to username@gmail.com

            UserModel::create([
                'username' => $data['username'],
                'email' => $email,
                'password' => Hash::make($password),
                'domain_id' => $entity->id,
                'user_group' => 'domain',
            ]);

            // create domain customer

            // Fetch the customer
            $customer = CustomerModel::where('domain_id',$entity->id)->first();

            if (!$customer) {
                $getCoreSettingTypeId = SettingTypeModel::where('slug', 'customer-group')->first();
                $getCustomerGroupId = SettingModel::where('setting_type_id', $getCoreSettingTypeId->id)
                    ->where('name', 'Domain')->where('domain_id', $this->domain['global_id'])->first();

                if (empty($getCustomerGroupId)) {
                    $getCustomerGroupId = SettingModel::create([
                        'domain_id' => $this->domain['global_id'],
                        'name' => 'Default',
                        'setting_type_id' => $getCoreSettingTypeId->id, // Ensure this variable has a value
                        'slug' => 'default',
                        'status' => 1,
                        'created_at' => now(),  // Add the current timestamp
                        'updated_at' => now()   // If you also have `updated_at`
                    ]);
                }

                // Handle Customer
                $code = $this->generateCustomerCode($patternCodeService);

                CustomerModel::create([
                    'domain_id' => $entity->id,
                    'code' => $code['code'],
                    'name' => $data['username'],
                    'mobile' => $data['mobile'],
                    'email' => $entity->email,
                    'status' => true,
                    'address' => $entity->address,
                    'customer_group_id' => $getCustomerGroupId->id ?? null, // Default group
                    'slug' => Str::slug($entity->name),
                    'customerId' => $code['generateId'], // Generated ID from the pattern code
                ]);
            }

            // Step 3: Create the inventory configuration (config)
            $currency = CurrencyModel::find(1);

            $config =  ConfigModel::create([
                'domain_id' => $entity->id,
                'currency_id' => $currency->id,
                'zero_stock' => true,
                'is_sku' => true,
                'is_measurement' => true,
                'is_product_gallery' => true,
                'is_multi_price' => true,
                'business_model_id' => $entity->business_model_id,
            ]);

            // Step 4: Create the accounting data
            $accountingConfig = AccountingModel::create([
                'domain_id' => $entity->id,
                'financial_start_date' => date('Y-m-d'),
                'financial_end_date' => date('Y-m-d'),
            ]);

            // Step 4: Create the accounting data
            NbrVatModel::create([
                'domain_id' => $entity->id,
            ]);

             // Step 5: Create the Production data
            ProductionConfig::create([
                'domain_id' => $entity->id,
            ]);

            $getProductType = UtilitySettingModel::getEntityDropdown('product-type');
            if (count($getProductType) > 0) {
                // If no inventory config found, return JSON response.
                if (!$config) {
                    DB::rollBack();
                    $response = new Response();
                    $response->headers->set('Content-Type', 'application/json');
                    $response->setContent(json_encode([
                        'message' => 'Inventory config not found',
                        'status' => Response::HTTP_NOT_FOUND,
                    ]));
                    $response->setStatusCode(Response::HTTP_OK);
                    return $response;
                }

                // Loop through each product type and either find or create inventory setting.
                foreach ($getProductType as $type) {
                    // If the inventory setting is not found, create a new one.
                    InventorySettingModel::create([
                        'config_id' => $config->id,
                        'setting_id' => $type->id,
                        'name' => $type->name,
                        'slug' => $type->slug,
                        'parent_slug' => 'product-type',
                        'is_production' => in_array($type->slug,
                            ['post-production', 'mid-production', 'pre-production']) ? 1 : 0,
                    ]);
                }

                TransactionModeModel::create([
                    'config_id' => $accountingConfig->id,
                    'account_owner' => 'Cash',
                    'authorised' => 'Cash',
                    'name' => 'Cash',
                    'short_name' => 'Cash',
                    'slug' => 'cash',
                    'is_selected' => true,
                    'path' => null,
                    'account_type' => 'Current',
                    'method_id' => 20,
                    'status' => true
                ]);
            }


            // Commit all database operations
            DB::commit();
            $em->getRepository(AccountHead::class)->generateAccountHead($accountingConfig->id);
            // Return the response
            $service = new JsonRequestResponse();
            return $service->returnJosnResponse($entity);

        } catch (Exception $e) {
            // Something went wrong, rollback the transaction
            DB::rollBack();

            // Optionally log the exception for debugging purposes
            \Log::error('Error storing domain and related data: ' . $e->getMessage());

            // Return an error response
            $response = new Response();
            $response->headers->set('Content-Type', 'application/json');
            $response->setContent(json_encode([
                'message' => 'An error occurred while saving the domain and related data.',
                'error' => $e->getMessage(),
                'status' => Response::HTTP_INTERNAL_SERVER_ERROR,
            ]));
            $response->setStatusCode(Response::HTTP_INTERNAL_SERVER_ERROR);
            return $response;
        }
    }

    private function generateCustomerCode($patternCodeService): array
    {
        $params = [
            'domain' => $this->domain['global_id'],
            'table' => 'cor_customers',
            'prefix' => 'CUS-',
        ];

        $pattern = $patternCodeService->customerCode($params);

        return $pattern;
    }


    /**
     * Show the specified resource.
     */
    public function show($id)
    {
        $service = new JsonRequestResponse();

        // Fetch the domain entity
        $entity = DomainModel::find($id);

        if (!$entity) {
            return $service->returnJosnResponse([
                'message' => 'Domain not found',
                'status' => Response::HTTP_NOT_FOUND,
            ]);
        }

        // Retrieve the inventory config id based on domain_id
        $config = ConfigModel::where('domain_id', $id)->first();

        if (!$config) {
            return $service->returnJosnResponse([
                'message' => 'Inventory config not found for this domain',
                'status' => Response::HTTP_NOT_FOUND,
            ]);
        }

        $getInvConfigId = $config->id;

        // if product type not exists then create
        $productTypeExists = InventorySettingModel::where('config_id', $getInvConfigId)
            ->where('parent_slug', 'product-type')
            ->exists();

        if (!$productTypeExists) {
            $getProductType = UtilitySettingModel::getEntityDropdown('product-type');
            if (count($getProductType) > 0) {
                // Loop through each product type and either find or create inventory setting.
                foreach ($getProductType as $type) {
                    // If the inventory setting is not found, create a new one.
                    InventorySettingModel::create([
                        'config_id' => $getInvConfigId,
                        'setting_id' => $type->id,
                        'name' => $type->name,
                        'slug' => $type->slug,
                        'parent_slug' => 'product-type',
                        'is_production' => in_array($type->slug,
                            ['post-production', 'mid-production', 'pre-production']) ? 1 : 0,
                    ]);
                }
            }
        }

        // Fetch relevant product types settings as setting_id array
        $getInvProductType = InventorySettingModel::where('config_id', $getInvConfigId)
            ->where('parent_slug', 'product-type')
            ->where('status', 1)
            ->get('id')
            ->toArray();

        // Extract ids as strings
        $ids = array_map(function($module) {
            return (string)$module['id'];
        }, $getInvProductType);

        // Attach the product types to the entity
        $entity['product_types'] = $ids;

        // fetch inventory setting product type for generate checkbox
        $getInvProductTypeForCheckbox = InventorySettingModel::where('config_id', $getInvConfigId)
            ->where('parent_slug', 'product-type')
            ->get()
            ->toArray();
        $entity['product_types_checkbox'] = $getInvProductTypeForCheckbox;

        // Return a structured JSON response using your service
        return $service->returnJosnResponse($entity);
    }


    /**
     * Show the form for editing the specified resource.
     */
    public function edit($id)
    {
        $service = new JsonRequestResponse();
        $entity = DomainModel::find($id);
        if (!$entity){
            $entity = 'Data not found';
        }
        $data = $service->returnJosnResponse($entity);
        return $data;
    }

    /**
     * Update the specified resource in storage.
     */

    public function update(DomainRequest $request, $id)
    {
        $data = $request->validated();

        // Start the transaction.
        DB::beginTransaction();

        try {
            // Find inventory config id.
            $getInvConfigId = ConfigModel::where('domain_id', $id)->first('id')->id;

            // If no inventory config found, return JSON response.
            if (!$getInvConfigId) {
                DB::rollBack();  // Rollback if inventory config is not found.

                $response = new Response();
                $response->headers->set('Content-Type', 'application/json');
                $response->setContent(json_encode([
                    'message' => 'Inventory config not found',
                    'status' => Response::HTTP_NOT_FOUND,
                ]));
                $response->setStatusCode(Response::HTTP_OK);
                return $response;
            }

            $getInvSetting = InventorySettingModel::where('config_id', $getInvConfigId)
                ->where('parent_slug', 'product-type')
                ->get();

            // Loop through each product type and either find or create inventory setting.
            foreach ($getInvSetting as $type) {
                if (in_array($type->id, $data['product_types'])) {
                    $type->update(['status'=>true]);
                }else{
                    $type->update(['status'=>false]);
                }
            }

            // Find and update the domain entity.
            $entity = DomainModel::find($id);
            $entity->update($data);

            // If we got this far, everything is okay, commit the transaction.
            DB::commit();

            // Return a json response using your service.
            $service = new JsonRequestResponse();
            return $service->returnJosnResponse($entity);

        } catch (Exception $e) {
            // If there's an exception, rollback the transaction.
            DB::rollBack();

            // Optionally log the exception (for debugging purposes)
            \Log::error('Error updating domain and inventory settings: '.$e->getMessage());

            // Return an error response.
            $response = new Response();
            $response->headers->set('Content-Type', 'application/json');
            $response->setContent(json_encode([
                'message' => 'An error occurred while updating.',
                'error' => $e->getMessage(),
                'status' => Response::HTTP_INTERNAL_SERVER_ERROR,
            ]));
            $response->setStatusCode(Response::HTTP_INTERNAL_SERVER_ERROR);
            return $response;
        }
    }


    /**
     * Update the specified resource in storage.
     */
    public function subDomain(Request $request,$id, EntityManager $em)
    {

        $subDomain = $request->sub_domain;
        $entity = $em->getRepository(GlobalOption::class)->find($id);
        $em->getRepository(SubDomain::class)->insertUpdate($entity,$subDomain);
        $service = new JsonRequestResponse();
        return $service->returnJosnResponse($entity);
    }

     /**
     * Update the specified resource in storage.
     */
    public function inventorySetting(Request $request,$id, EntityManager $em)
    {
        $setting_id = $request->setting_id;
        $entity = $em->getRepository(GlobalOption::class)->find($id);
        $em->getRepository(Setting::class)->insertUpdate($entity,$setting_id);
        $service = new JsonRequestResponse();
        return $service->returnJosnResponse($entity);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        $service = new JsonRequestResponse();



        DomainModel::find($id)->delete();
        $entity = ['message'=>'delete'];
        $data = $service->returnJosnResponse($entity);
        return $data;
    }

    /**
     * Reset the specified resource from storage.
     */

    public function resetData($id)
    {
        // Ensure the domain exists
        $findDomain = DomainModel::findOrFail($id);

        // Fetch domain config
        $allConfigId = DomainModel::getDomainConfigData($id)->toArray();

        if (empty($allConfigId['inv_config'])) {
            return response()->json(['message' => 'Inventory config not found', 'status' => Response::HTTP_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        // Delete purchases and related data using chunking
        PurchaseModel::with('purchaseItems.stock.stockItemHistory')
            ->where('config_id', $allConfigId['inv_config'])
            ->chunk(100, function ($purchases) {
                $purchases->each(function ($purchase) {
                    $purchase->purchaseItems->each(function ($purchaseItem) {
                        if ($purchaseItem->stock && $purchaseItem->stock->stockItemHistory->isNotEmpty()) {
                            $purchaseItem->stock->stockItemHistory->each(function ($history) {
                                $history->delete();
                            });
                        }
                    });
                    $purchase->delete();
                });
            });

        // Bulk delete vendors and customers
        VendorModel::where('domain_id', $id)->delete();
        CustomerModel::where('domain_id', $id)->delete();

        // Delete sales and related data using chunking
        SalesModel::with('salesItems.stock.stockItemHistory')
            ->where('config_id', $allConfigId['inv_config'])
            ->chunk(100, function ($sales) {
                $sales->each(function ($sale) {
                    $sale->salesItems->each(function ($salesItem) {
                        if ($salesItem->stock && $salesItem->stock->stockItemHistory->isNotEmpty()) {
                            $salesItem->stock->stockItemHistory->each(function ($history) {
                                $history->delete();
                            });
                        }
                    });
                    $sale->delete();
                });
            });

        // Bulk update stock item quantities
        StockItemModel::where('config_id', $allConfigId['inv_config'])->update(['quantity' => 0]);

        return response()->json(['message' => 'Domain reset successfully', 'status' => Response::HTTP_OK], Response::HTTP_OK);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function deleteData($id)
    {

        $service = new JsonRequestResponse();
        $userData = DomainModel::getDomainConfigData($id);
        if($userData['acc_config']){
            AccountingModel::find($userData['acc_config'])->delete();
        }
        if($userData['pro_config']) {
            ProductionConfig::find($userData['pro_config'])->delete();
        }
        if($userData['nbr_config']) {
            NbrVatModel::find($userData['nbr_config'])->delete();
        }
        if($userData['config_id']) {
            ConfigModel::find($userData['config_id'])->delete();
        }
        TransactionModeModel::whereNull('config_id')->delete();
        DomainModel::find($id)->delete();
        $entity = ['message'=>'delete'];
        $data = $service->returnJosnResponse($entity);
        return $data;
    }
}
