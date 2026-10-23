<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Wallet extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'type', 'is_archived', 'household_id', 'created_by_member_id'];

    protected function casts(): array
    {
        return ['is_archived' => 'boolean', 'balance' => 'decimal:2'];
    }
}
