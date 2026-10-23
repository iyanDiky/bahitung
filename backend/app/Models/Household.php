<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Household extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'timezone', 'created_by_user_id'];

    protected function casts(): array
    {
        return ['archived_at' => 'datetime'];
    }

    public function members(): HasMany
    {
        return $this->hasMany(HouseholdMember::class);
    }
}
