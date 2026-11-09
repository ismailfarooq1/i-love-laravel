<?php

namespace App\Models;

use Database\Factories\BacklogProjectFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BacklogProject extends Model
{
    /** @use HasFactory<BacklogProjectFactory> */
    use HasFactory;

    protected $fillable = ['workspace', 'version', 'state'];

    protected function casts(): array
    {
        return ['state' => 'array', 'version' => 'integer'];
    }
}
