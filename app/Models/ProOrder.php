<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProOrder extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     */
    protected $table = 'pro_order';

    /**
     * The primary key for the model.
     */
    protected $primaryKey = 'order_id';
    
    /**
     * Indicates if the primary key is auto-incrementing.
     */
    public $incrementing = false;
    
    /**
     * The data type of the primary key.
     */
    protected $keyType = 'string';

    /**
     * Indicates if the model should be timestamped.
     * Assuming pro_order table doesn't have created_at/updated_at
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'order_id',
        'material_id',
        'material_desc',
        'material_code',
        'plant',
        'production_version',
        'batch',
        'order_quantity',
        'unit_of_measure',
        'basic_start_date',
        'basic_finish_date'
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'basic_start_date' => 'date',
        'basic_finish_date' => 'date',
        'order_quantity' => 'decimal:2',
        'material_id' => 'integer',
        'batch' => 'integer'
    ];

    /**
     * Get the material associated with this pro order.
     */
    public function material()
    {
        return $this->belongsTo(Material::class, 'material_id', 'id_mat');
    }

    /**
     * Get the production orders created from this pro order.
     */
    public function productionOrders()
    {
        return $this->hasMany(ProductionOrder::class, 'no_pro', 'order_id');
    }

    /**
     * Scope to get orders by material.
     */
    public function scopeByMaterial($query, $materialId)
    {
        return $query->where('material_id', $materialId);
    }

    /**
     * Scope to get orders by plant.
     */
    public function scopeByPlant($query, $plant)
    {
        return $query->where('plant', $plant);
    }

    /**
     * Scope to get orders within date range.
     */
    public function scopeWithinDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('basic_start_date', [$startDate, $endDate]);
    }

    /**
     * Scope to get orders by production version.
     */
    public function scopeByProductionVersion($query, $version)
    {
        return $query->where('production_version', $version);
    }

    /**
     * Check if this pro order has been used to create production orders.
     */
    public function hasProductionOrders()
    {
        return $this->productionOrders()->exists();
    }

    /**
     * Get the completion status based on production orders.
     */
    public function getCompletionStatusAttribute()
    {
        $productionOrders = $this->productionOrders;
        
        if ($productionOrders->isEmpty()) {
            return 'Not Started';
        }
        
        $totalRequired = $productionOrders->sum('quantity_required');
        $totalFulfilled = $productionOrders->sum('quantity_fulfilled');
        
        if ($totalFulfilled >= $totalRequired) {
            return 'Completed';
        } elseif ($totalFulfilled > 0) {
            return 'In Progress';
        } else {
            return 'Planned';
        }
    }

    /**
     * Get the total quantity required from all production orders.
     */
    public function getTotalQuantityRequiredAttribute()
    {
        return $this->productionOrders->sum('quantity_required');
    }

    /**
     * Get the total quantity fulfilled from all production orders.
     */
    public function getTotalQuantityFulfilledAttribute()
    {
        return $this->productionOrders->sum('quantity_fulfilled');
    }

    /**
     * Get the completion percentage.
     */
    public function getCompletionPercentageAttribute()
    {
        $totalRequired = $this->total_quantity_required;
        
        if ($totalRequired <= 0) {
            return 0;
        }
        
        return min(100, ($this->total_quantity_fulfilled / $totalRequired) * 100);
    }

    /**
     * Check if the pro order is overdue.
     */
    public function getIsOverdueAttribute()
    {
        return $this->basic_finish_date < now() && $this->completion_status !== 'Completed';
    }

    /**
     * Get formatted order display name.
     */
    public function getDisplayNameAttribute()
    {
        return $this->order_id . ' - ' . $this->material_desc;
    }

    /**
     * Get the remaining days until finish date.
     */
    public function getRemainingDaysAttribute()
    {
        if ($this->basic_finish_date < now()) {
            return 0;
        }
        
        return now()->diffInDays($this->basic_finish_date);
    }

    /**
     * Scope to get orders that are due within specified days.
     */
    public function scopeDueWithin($query, $days = 7)
    {
        $targetDate = now()->addDays($days);
        return $query->where('basic_finish_date', '<=', $targetDate)
                     ->where('basic_finish_date', '>=', now());
    }

    /**
     * Scope to get overdue orders.
     */
    public function scopeOverdue($query)
    {
        return $query->where('basic_finish_date', '<', now());
    }

    /**
     * Scope to get active orders (not overdue and not completed).
     */
    public function scopeActive($query)
    {
        return $query->where('basic_finish_date', '>=', now())
                     ->whereHas('productionOrders', function($q) {
                         $q->where('quantity_fulfilled', '<', $q->raw('quantity_required'));
                     });
    }

    /**
     * Get orders formatted for dropdown selection.
     */
    public static function getForDropdown()
    {
        return self::select([
                'order_id',
                'material_id',
                'material_desc',
                'material_code',
                'plant',
                'production_version',
                'batch',
                'order_quantity',
                'unit_of_measure',
                'basic_start_date',
                'basic_finish_date'
            ])
            ->orderBy('order_id')
            ->orderBy('batch')
            ->get()
            ->map(function($order) {
                return [
                    'order_id' => $order->order_id,
                    'display_name' => $order->display_name,
                    'material_id' => $order->material_id,
                    'material_desc' => $order->material_desc,
                    'material_code' => $order->material_code,
                    'plant' => $order->plant,
                    'production_version' => $order->production_version,
                    'batch' => $order->batch,
                    'order_quantity' => $order->order_quantity,
                    'unit_of_measure' => $order->unit_of_measure,
                    'basic_start_date' => $order->basic_start_date,
                    'basic_finish_date' => $order->basic_finish_date,
                    'remaining_days' => $order->remaining_days,
                    'is_overdue' => $order->is_overdue
                ];
            });
    }

    /**
     * Boot method to handle model events.
     */
    protected static function boot()
    {
        parent::boot();

        // Log when accessing pro orders
        static::retrieved(function ($proOrder) {
            \Log::debug('Pro Order Retrieved:', [
                'order_id' => $proOrder->order_id,
                'material_desc' => $proOrder->material_desc
            ]);
        });
    }

    /**
     * Convert dates to proper format for JSON responses.
     */
    protected function serializeDate(\DateTimeInterface $date)
    {
        return $date->format('Y-m-d');
    }
}