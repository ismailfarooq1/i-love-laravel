<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GitHubDelivery extends Model
{
    protected $fillable = ['delivery_id', 'event', 'payload', 'status', 'error'];

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }
}
