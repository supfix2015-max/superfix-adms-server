<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FingerLog extends Model
{
    use HasFactory;

    // Specify the table name if it's not the plural form of the model name
    protected $table = 'finger_log';

    // Fillable attributes for mass assignment
    protected $fillable = [
        'data',
        'url',
    ];

    // Timestamps are enabled by default, so you can omit this unless you need to customize
    public $timestamps = true;
}
