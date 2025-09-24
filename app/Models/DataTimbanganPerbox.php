<?php
// File: app/Models/DataTimbanganPerbox.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DataTimbanganPerbox extends Model
{
    // This disables Laravel's automatic created_at and updated_at management
    public $timestamps = false;

    protected $table = 'data_timbangan_perbox';

    protected $fillable = [
        'data_timbangan_id',
        'box_no',
        'weight_perbox',
        'category',
        'weighed_at',
        'timbangan_name',
    ];

    protected $casts = [
        'weighed_at' => 'datetime',
    ];

    const CATEGORIES = [
        'Runner',
        'Sapuan',
        'Purging',
        'Defect',
        'Finished Good'
    ];

    // Relationships
    public function dataTimbangan()
    {
        return $this->belongsTo(DataTimbangan::class, 'data_timbangan_id');
    }
}