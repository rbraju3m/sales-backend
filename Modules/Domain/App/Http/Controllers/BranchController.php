<?php

namespace Modules\Domain\App\Http\Controllers;

use App\Http\Controllers\Controller;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Accounting\App\Entities\AccountHead;
use Modules\Accounting\App\Entities\Config;
use Modules\Accounting\App\Models\AccountHeadModel;
use Modules\Accounting\App\Models\AccountingModel;
use Modules\AppsApi\App\Services\GeneratePatternCodeService;
use Modules\AppsApi\App\Services\JsonRequestResponse;
use Modules\Core\App\Entities\Customer;
use Modules\Core\App\Models\CustomerModel;
use Modules\Core\App\Models\SettingModel;
use Modules\Core\App\Models\SettingTypeModel;
use Modules\Core\App\Models\VendorModel;
use Modules\Domain\App\Http\Requests\BranchPriceUpdateRequest;
use Modules\Domain\App\Http\Requests\BranchRequest;
use Modules\Core\App\Models\UserModel;
use Modules\Domain\App\Models\DomainModel;
use Modules\Domain\App\Models\SubdomainCategory;
use Modules\Domain\App\Models\SubDomainModel;
use Modules\Inventory\App\Models\CategoryModel;
use Modules\Inventory\App\Models\ConfigModel;
use Modules\Inventory\App\Models\ParticularModel;
use Modules\Inventory\App\Models\ProductModel;
use Modules\Inventory\App\Models\StockItemModel;


class BranchController extends Controller
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

    public function domainForBranch()
    {
        $domains = DomainModel::getDomainsForBranch($this->domain['global_id']);
        $data = [];

        if (count($domains) > 0) {
            foreach ($domains as $domain) {
                // sub domain exists
                $getCustomerPriceData = CustomerModel::where('sub_domain_id', $domain['id'])
                    ->where('domain_id', $this->domain['global_id'])
                    ->select('discount_percent', 'bonus_percent', 'monthly_target_amount','id','status')
                    ->first();

                if ($getCustomerPriceData) {
                    $domain['customer_id'] = $getCustomerPriceData->id;
                    $domain['is_sub_domain'] = $getCustomerPriceData->status==1?true:false;
                    $domain['prices'] = [
                        ['discount_percent' => $getCustomerPriceData->discount_percent,'label'=> 'Discount Percent'],
                        ['bonus_percent' => $getCustomerPriceData->bonus_percent,'label'=> 'Bonus Percent'],
                        ['monthly_target_amount' => $getCustomerPriceData->monthly_target_amount,'label'=> 'Monthly Target Amount'],
                    ];
                } else {
                    $domain['customer_id'] = null;
                    $domain['is_sub_domain'] = false;
                    $domain['prices'] = [
                        ['discount_percent' => null,'label'=> 'Discount Percent'],
                        ['bonus_percent' => null,'label'=> 'Bonus Percent'],
                        ['monthly_target_amount' => null,'label'=> 'Monthly Target Amount'],
                    ];
                }
                $domain['categories'] = CategoryModel::getCategoryDropdown($this->domain);

                // get assign category
                $invConfig = ConfigModel::where('domain_id', $domain['id'])->value('id');

                $categories = SubdomainCategory::where('config_id', $invConfig)->where('status',true)
                    ->pluck('category_id')
                    ->toArray();

                // Directly map "category_id" to the required format "category_id#domain_id"
                $checkCategory = array_map(fn($categoryId) => $categoryId . '#' . $domain['id'], $categories);

                $domain['check_category'] = $checkCategory;

                $data[] = $domain;
            }
        }

        $response = new Response();
        $response->headers->set('Content-Type','application/json');
        $response->setContent(json_encode([
            'message' => 'success',
            'status' => Response::HTTP_OK,
            'total' => count($data),
            'data' => $data
        ]));
        $response->setStatusCode(Response::HTTP_OK);
        return $response;
    }


    /**
     * Store a newly created resource in storage.
     */

    public function store(BranchRequest $request, GeneratePatternCodeService $patternCodeService, JsonRequestResponse $service) {

        $input = $request->validated();
        DB::beginTransaction();

        try {
            // Fetch the child and parent domains
            $childDomain = DomainModel::findOrFail($input['child_domain_id']);
            $parentDomain = DomainModel::findOrFail($input['parent_domain_id']);

            // create domain wise sub domain
            SubDomainModel::updateOrCreate(
                ['domain_id' => $parentDomain->id, 'sub_domain_id' => $childDomain->id],
                ['status' => $input['checked'] ? 1 : 0]
            );


            // Handle Customer
            $customer = $this->handleEntity(
                CustomerModel::class,
                [
                    'sub_domain_id' => $input['child_domain_id'],
                    'domain_id' => $input['parent_domain_id']
                ],
                $input['checked'],
                fn() => $this->prepareCustomerData($childDomain, $patternCodeService)
            );

            if ($customer) {
                $this->ensureCustomerLedger($customer);
            }

            // Handle Vendor
            $vendor = $this->handleEntity(
                VendorModel::class,
                [
                    'sub_domain_id' => $input['parent_domain_id'],
                    'domain_id' => $input['child_domain_id']
                ],
                $input['checked'],
                fn() => $this->prepareVendorData($childDomain, $parentDomain, $patternCodeService,$customer)
            );

            if ($vendor) {
                $this->ensureVendorLedger($vendor, $childDomain->id);
            }


            $customer = CustomerModel::where('domain_id', $this->domain['global_id'])
                ->where('sub_domain_id', $input['child_domain_id'])
                ->first();

            if ($customer){
                // Fetch configuration by sub_domain_id
                $childAccConfig = ConfigModel::where('domain_id', $customer->sub_domain_id)
                    ->value('id');

                if (!$childAccConfig) {
                    return response()->json(['status' => 404, 'success' => false, 'message' => 'Config not found']);
                }

                // Fetch all subdomain category IDs for the config
                $getSubDomainCategory = SubdomainCategory::where('config_id', $childAccConfig)
                    ->pluck('category_id')
                    ->toArray();
                if ($getSubDomainCategory) {
                    // Fetch products belonging to the fetched categories and config
                    $getProducts = ProductModel::whereIn('category_id', $getSubDomainCategory)
                        ->where('config_id', $childAccConfig)
                        ->whereNotNull('parent_id')
                        ->pluck('id')
                        ->toArray();

                    foreach ($getProducts as $productId) {
                        ProductModel::find($productId)->update(['status' => $input['checked']]);
                    }

                    // Fetch stock items for the products
                    $getStocks = StockItemModel::whereIn('product_id', $getProducts)->get();

                    // Update stock items if any exist
                    if ($getStocks->isNotEmpty()) {
                        foreach ($getStocks as $stock) {
                            $stock->update(
                                ['status' => $input['checked']]
                            );
                        }
                    }
                }
            }
            // Commit transaction
            DB::commit();

            // Return success response
            return $service->returnJosnResponse(CustomerModel::getCustomerDetails($customer->id));
        } catch (\Exception $e) {
            // Rollback transaction on failure
            DB::rollBack();

            // Handle the exception and return error response
            return response()->json([
                'status' => 'error',
                'message' => 'An error occurred while storing the branch data.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // Helper function to handle customer/vendor creation and update
    protected function handleEntity($modelClass, $whereConditions, $isChecked, $createCallback)
    {
        $entity = $modelClass::where($whereConditions)->first();

        if ($isChecked) {
            // Create or Update the entity
            if (!$entity) {
                $entityData = $createCallback();
                return $modelClass::create($entityData);
            }
            $entity->update(['status' => true]);
            return $entity->fresh();
        } else {
            if ($entity) {
                $entity->update(['status' => false]);
            }
            return null;
        }
    }

    private function prepareCustomerData($childDomain, $patternCodeService): array
    {
        $code = $this->generateCustomerCode($patternCodeService);
        $getCoreSettingTypeId = SettingTypeModel::where('slug','customer-group')->first();

        $getCustomerGroupId = SettingModel::where('setting_type_id', $getCoreSettingTypeId->id)
            ->where('name', 'Domain')->where('domain_id', $this->domain['global_id'])->first();

        if(empty($getCustomerGroupId)){
            $getCustomerGroupId = SettingModel::create([
                'domain_id' => $this->domain['global_id'],
                'name' => 'Domain',
                'setting_type_id' => $getCoreSettingTypeId->id, // Ensure this variable has a value
                'slug' => 'domain',
                'status' => 1,
                'created_at' => now(),  // Add the current timestamp
                'updated_at' => now()   // If you also have `updated_at`
            ]);
        }

        return [
            'domain_id' => $this->domain['global_id'],
            'customer_unique_id' => "{$this->domain['global_id']}@{$childDomain->mobile}-{$childDomain->name}",
            'code' => $code['code'],
            'name' => $childDomain->name,
            'mobile' => $childDomain->mobile,
            'email' => $childDomain->email,
            'status' => true,
            'address' => $childDomain->address,
            'customer_group_id' => $getCustomerGroupId->id ?? null, // Default group
            'slug' => Str::slug($childDomain->name),
            'sub_domain_id' => $childDomain->id,
            'customer_id' => $code['generate_id'], // Generated ID from the pattern code
        ];
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


    private function prepareVendorData($childDomain, $parentDomain, $patternCodeService,$customer): array
    {
        $params = [
            'domain' => $this->domain['global_id'],
            'table' => 'cor_vendors',
            'prefix' => '',
        ];

        $pattern = $patternCodeService->customerCode($params);

        return [
            'name' => $parentDomain->name,
            'company_name' => $parentDomain->company_name,
            'mobile' => $parentDomain->mobile,
            'email' => $parentDomain->email,
            'status' => true,
            'domain_id' => $childDomain->id,
            'sub_domain_id' => $this->domain['global_id'],
            'slug' => Str::slug($childDomain->name),
            'code' => $pattern['code'],
            'vendor_code' => $pattern['generateId'],
            'customer_id' => $customer->id,
        ];
    }

    private function ensureCustomerLedger(CustomerModel $customer)
    {
        $ledgerExist = AccountHeadModel::where('customer_id', $customer->id)
            ->where('config_id', $this->domain['acc_config'])
            ->exists();

        if (!$ledgerExist) {
            AccountHeadModel::insertCustomerLedger($this->domain['acc_config'], $customer);
        }
    }

    private function ensureVendorLedger(VendorModel $vendor, $childDomainId)
    {
        $childAccConfig = DB::table('acc_config')
            ->where('domain_id', $childDomainId)
            ->value('id');

        $ledgerExist = AccountHeadModel::where('vendor_id', $vendor->id)
            ->where('config_id', $childAccConfig)
            ->exists();

        if (!$ledgerExist) {
            AccountHeadModel::insertVendorLedger($childAccConfig, $vendor);
        }
    }


    public function priceUpdate(BranchPriceUpdateRequest $request)
    {
        $input = $request->validated();

        // Update the specific field for the customer
        CustomerModel::findOrFail($input['customer_id'])->update([
            $input['field_name'] => $input['value']
        ]);

        // Check if discount_percent is being updated
        if ($input['field_name'] === 'discount_percent') {
            $customer = CustomerModel::select('discount_percent', 'domain_id', 'sub_domain_id')
                ->findOrFail($input['customer_id']);

            if (!$customer) {
                return response()->json(['status' => 404, 'success' => false]);
            }

            // Fetch configuration by sub_domain_id
            $childAccConfig = ConfigModel::where('domain_id', $customer->sub_domain_id)
                ->value('id');

            if (!$childAccConfig) {
                return response()->json(['status' => 404, 'success' => false, 'message' => 'Config not found']);
            }

            // Fetch all subdomain category IDs for the config
            $getSubDomainCategory = SubdomainCategory::where('config_id', $childAccConfig)
                ->pluck('category_id')
                ->toArray();

            // Fetch products belonging to the fetched categories and config
            $getProducts = ProductModel::whereIn('category_id', $getSubDomainCategory)
                ->where('config_id', $childAccConfig)
                ->whereNotNull('parent_id')
                ->pluck('id')
                ->toArray();

            // Fetch stock items for the products
            $getStocks = StockItemModel::whereIn('product_id', $getProducts)->get();

            // Update stock items if any exist
            if ($getStocks->isNotEmpty()) {
                foreach ($getStocks as $stock) {
                    $stock->update(
                        $this->prepareStockData($stock, $input['value'])
                    );
                }
            }
        }

        return response()->json(['status' => 200, 'success' => true, 'data' => $input]);
    }

    public function categoryUpdate(Request $request)
    {
        try {
            DB::beginTransaction(); // Start transaction

            // Existing validation and parsing logic
            $request->validate([
                'value' => ['required', 'string', 'regex:/^\d+#\d+$/'],
                'check' => 'required|boolean',
                'customer_id' => 'required'
            ], [
                'value.regex' => 'The value must be in the format "categoryId#domainId".',
                'check.required' => 'The check field is required.',
                'customer_id.required' => 'The customer id field is required.',
            ]);

            [$categoryId, $domainId] = explode('#', $request->input('value'));
            $customerId = $request->input('customer_id');
            $findVendor = VendorModel::where('customer_id',$customerId)->first();

            if (!is_numeric($categoryId) || !is_numeric($domainId)) {
                return response()->json(['status' => 422, 'success' => false, 'message' => 'Category ID or Domain ID is invalid.']);
            }

            $childAccConfig = ConfigModel::where('domain_id', $domainId)->value('id');
            if (!$childAccConfig) {
                return response()->json(['status' => 404, 'success' => false, 'message' => 'Configuration not found.']);
            }

            $category = CategoryModel::find($categoryId);
            if (!$category) {
                return response()->json(['status' => 404, 'success' => false, 'message' => 'Category not found.']);
            }
            $categoryGroup = $category->parent;

            $subCategory = SubdomainCategory::firstOrCreate(
                [
                    'category_id' => $categoryId,
                    'config_id' => $childAccConfig,
                ],
                [
                    'category_group_id' => $categoryGroup ?? null,
                    'created_by_id' => $this->domain['user_id'],
                    'status' => true,
                ]
            );

            if (!$subCategory->wasRecentlyCreated) {
                $subCategory->update([
                    'status' => $request->input('check') ? true : false,
                ]);
            }

            $this->handleCategoryProduct($categoryId, $childAccConfig, $request->input('check'), $domainId,$findVendor);

            DB::commit(); // Commit transaction

            return response()->json([
                'status' => 200,
                'success' => true,
                'message' => 'Category updated successfully.',
                'data' => $subCategory,
            ]);
        } catch (\Exception $e) {
            DB::rollBack(); // Rollback transaction on error
            \Log::error('Category update failed: ' . $e->getMessage());
            return response()->json(['status' => 500, 'success' => false, 'message' => 'An internal error occurred.']);
        }
    }



    private function handleCategoryProduct($categoryId, $childAccConfig, $shouldUpdate, $domainId,$findVendor) {
        // Fetch all products for the given category and config
        $products = ProductModel::where('category_id', $categoryId)
            ->where('config_id', $this->domain['config_id'])
            ->where('status', true)
            ->get();

        // Fetch all child products for given config in one query
        $productUpdates = ProductModel::where('config_id', $childAccConfig)
            ->whereIn('parent_id', $products->pluck('id')) // Batch query
            ->get()
            ->keyBy('parent_id');

        // Pre-fetch customer discounts to avoid redundant calls
        $discountPercent = $this->getCustomerDiscount($domainId);

        // Pre-fetch all stock items for efficiency
        $parentStocks = StockItemModel::whereIn('product_id', $products->pluck('id'))
            ->get()
            ->keyBy('product_id');

        // Iterate through all parent products
        foreach ($products as $parentProduct) {
            $productUpdate = $productUpdates[$parentProduct->id] ?? null;
            $parentStock = $parentStocks[$parentProduct->id] ?? null;

            if ($shouldUpdate && !$productUpdate) {
                $this->createChildProduct($parentProduct, $categoryId, $childAccConfig, $parentStock, $discountPercent,$findVendor);
            } elseif ($productUpdate) {
                $this->updateProductAndStock($parentProduct, $productUpdate, $parentStock, $shouldUpdate, $discountPercent,$findVendor);
            }
        }

        return true;
    }

    private function createChildProduct($parentProduct, $categoryId, $childAccConfig, $parentStock, $discountPercent,$findVendor) {
        // Create a new child product
        $childProduct = ProductModel::create([
            'category_id' => $categoryId,
            'name' => $parentProduct->name,
            'slug' => $this->generateUniqueSlug($parentProduct->slug),
            'config_id' => $childAccConfig,
            'barcode' => $parentProduct->barcode,
            'alternative_name' => $parentProduct->alternative_name,
            'unit_id' => $parentProduct->unit_id,
            'product_type_id' => $parentProduct->product_type_id,
            'parent_id' => $parentProduct->id,
            'description' => $parentProduct->description,
            'vendor_id' => $findVendor->id,
        ]);

        // Fetch parent product stock
        $getStocks = StockItemModel::where([
            ['product_id', $parentProduct->id],
            ['config_id', $parentProduct->config_id],
            ['status', 1],
            ['is_delete', 0]
        ])->get();

        if (count($getStocks) > 0) {
            foreach ($getStocks as $stock) {
                // Prepare stock data and insert
                $stockData = $this->prepareStockData($stock, $discountPercent,[
                    'product_id'         => $childProduct->id,
                    'config_id'          => $childAccConfig,
                    'barcode'            => random_int(10000000, 99999999),
                    'sku'                => random_int(10000000, 99999999),
                    'status'             => $stock->status ?? null,
                    'is_delete'          => $stock->is_delete ?? null,
                    'is_master'          => $stock->is_master ?? null,
                    'name'               => $stock->name ?? null,
                    'display_name'       => $stock->display_name ?? null,
                    'uom'                => $stock->uom ?? null,
                    'bangla_name'        => $stock->bangla_name ?? null,
                    'parent_stock_item'  => $stock->id ?? null,
                ]);

                // Attributes processing
                $attributes = ['color_id', 'grade_id', 'brand_id', 'size_id', 'model_id'];
                foreach ($attributes as $attribute) {
                    if (!empty($stock->$attribute)) {
                        $stockData[$attribute] = $this->createParticularForBranch($stock->$attribute, $childAccConfig);
                    }
                }
                $stockData['barcode'] = random_int(10000000, 99999999);
                $stockData['sku'] = $stockData['barcode'];
                // Save stock data
                StockItemModel::create($stockData);
            }
        }
        return true;
    }

    private function createParticularForBranch($parentParticularId, $childAccConfig) {
        $parentParticular = ParticularModel::find($parentParticularId);

        if ($parentParticular) {
            $childParticular = ParticularModel::firstOrCreate(
                [
                    'particular_type_id' => $parentParticular->particular_type_id,
                    'config_id' => $childAccConfig,
                ],
                [
                    'name' => $parentParticular->name,
                    'slug' => $parentParticular->slug,
                    'status' => 1,
                ]
            );
            return $childParticular->id;
        }
    }


    private function updateProductAndStock($parentProduct, $productUpdate, $parentStock, $shouldUpdate, $discountPercent,$findVendor) {
        $status = $shouldUpdate ? true : false;

        // Update child product's status
        $productUpdate->update([
            'status' => $status,
            'vendor_id' => $findVendor->id
        ]);

        $productStockUpdate = StockItemModel::where('product_id', $productUpdate->id)->get();

        if ($productStockUpdate) {
            // Update child stock
            foreach ($productStockUpdate as $productStock) {
                $productStock->update(
                    $this->prepareStockData($parentStock, $discountPercent, ['status' => $status])
                );
            }
        }

        return true;
    }

    private function getCustomerDiscount($domainId) {
        // Fetch customer and return discount percentage
        $customer = CustomerModel::where('domain_id', $this->domain['global_id'])
            ->where('sub_domain_id', $domainId)
            ->first();

        return $customer->discount_percent ?? 0;
    }

    private function prepareStockData($parentStock, $discountPercent, $additionalData = []) {
        // Centralized stock calculation logic with additional fields
        $baseStockData = [
            'purchase_price' => $parentStock ? $parentStock->sales_price * ((100 - $discountPercent) / 100) : 0.0,
            'sales_price' => $parentStock->sales_price ?? 0.0,
            'min_quantity' => $parentStock->min_quantity ?? 0.0,
        ];

        return array_merge($baseStockData, $additionalData);
    }

    private function generateUniqueSlug($slug) {
        // Generate a unique slug by appending random characters
        return $slug . '-' . substr(uniqid(), -6);
    }

}
