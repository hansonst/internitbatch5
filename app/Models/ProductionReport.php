<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class ProductionReport extends Model
{
    /**
     * The table associated with the model.
     */
    protected $table = 'production_report';

    /**
     * The primary key associated with the table.
     */
    protected $primaryKey = 'header_id';

    /**
     * Indicates if the model should be timestamped.
     */
    public $timestamps = false;

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'actual_time' => 'datetime',
        'total_qty' => 'decimal:2',
        'fact_index' => 'decimal:6',
        'shift_id' => 'integer',
        'id_bom' => 'integer',
    ];

    /**
     * The attributes that are mass assignable.
     * Empty array since this is a read-only view.
     */
    protected $fillable = [];

    /**
     * The attributes that should be guarded.
     * All attributes are guarded since this is read-only.
     */
    protected $guarded = ['*'];

    /**
     * Prevent any insert operations
     */
    public function save(array $options = [])
    {
        throw new \Exception('Cannot save to production_report view - it is read-only');
    }

    /**
     * Prevent any update operations
     */
    public function update(array $attributes = [], array $options = [])
    {
        throw new \Exception('Cannot update production_report view - it is read-only');
    }

    /**
     * Prevent any delete operations
     */
    public function delete()
    {
        throw new \Exception('Cannot delete from production_report view - it is read-only');
    }

    /**
     * Scope for filtering by material code
     */
    public function scopeByMaterialCode(Builder $query, $materialCode): Builder
    {
        return $query->where('material_code', $materialCode);
    }

    /**
     * Scope for filtering by shift
     */
    public function scopeByShift(Builder $query, $shiftId): Builder
    {
        return $query->where('shift_id', $shiftId);
    }

    /**
     * Scope for filtering by batch number
     */
    public function scopeByBatchNumber(Builder $query, $batchNumber): Builder
    {
        return $query->where('batch_number', 'LIKE', "%{$batchNumber}%");
    }

    /**
     * Scope for filtering by date range
     */
    public function scopeByDateRange(Builder $query, $startDate, $endDate): Builder
    {
        return $query->whereBetween('actual_time', [$startDate, $endDate]);
    }

    /**
     * Scope for filtering by single date
     */
    public function scopeByDate(Builder $query, $date): Builder
    {
        return $query->whereDate('actual_time', $date);
    }
}
