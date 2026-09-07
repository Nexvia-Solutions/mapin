<?php

declare(strict_types=1);

namespace Fixture\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Author extends Model
{
    protected $casts = [
        'born_on' => 'date',
    ];

    public function books(): HasMany
    {
        return $this->hasMany(Book::class);
    }
}
