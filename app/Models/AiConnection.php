<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AiConnection extends Model
{
    use HasFactory;

    protected $fillable = ['team_id', 'provider', 'model', 'api_key', 'revision'];

    protected $hidden = ['api_key'];

    protected function casts(): array
    {
        return ['api_key' => 'encrypted', 'revision' => 'integer'];
    }
}
