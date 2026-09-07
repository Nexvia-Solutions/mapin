<?php

declare(strict_types=1);

namespace Fixture\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Book extends Model
{
    protected $table = 'library_books';

    public function author(): BelongsTo
    {
        return $this->belongsTo(Author::class);
    }
}
