<?php

namespace Modules\Inventory\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class StockItemInventoryHistoryModel extends Model
{
    use HasFactory;

    protected $table = 'inv_stock_item_inventory_history';
    public $timestamps = true;
    protected $guarded = ['id'];

    protected $fillable = [
        'stock_item_history_id',
        'quantity',
        'brand',
        'category',
        'purchase_item_id',
        'purchase_id',
        'price',
        'purchase_price',
        'sales_price',
        'sub_total',
        'config_id',
        'total'
    ];

    public static function boot() {
        parent::boot();
        self::creating(function ($model) {

        });

        self::updating(function ($model) {

        });
    }

    public static function openingInventoryHistory($item, $stockItemHistory,$process)
    {
        // Check for existing record
        $exist = self::where('stock_item_history_id', $stockItemHistory->id)->first();

        // Fetch related stock item details with eager loading
        $findStockItem = StockItemModel::with(['brand', 'product.category'])->find($item->stock_item_id);

        if (!$findStockItem) {
            return; // Exit if stock item doesn't exist
        }

        // Get related details using relationships if available
        $brandName = $findStockItem->brand->name ?? null;
        $categoryName = $findStockItem->product->category->name ?? null;

        if (!$exist) {
            self::create([
                'stock_item_history_id' => $stockItemHistory->id,
                'quantity' => $item->quantity,
                'brand' => $brandName,
                'category' => $categoryName,
                'purchase_item_id' => $item->id ?? null,
                'purchase_id' => $item->purchase_id ?? null,
                'price' => $item->purchase_price,
                'purchase_price' => $item->purchase_price,
                'sales_price' => $item->sales_price,
                'sub_total' => $item->sub_total,
                'total' => $item->sub_total,
                'config_id' => $item->config_id,
            ]);
        }
    }


}
