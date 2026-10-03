<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LogsheetImport extends Model
{
    use HasFactory;

    protected $fillable = [
        'date_from',
        'date_to',
        'original_filename',
        'file_path',
        'uploaded_by',
        'row_count',
        'consolidated_count',
        'duplicate_count',
        'invalid_count',
        'status',
        'warnings',
        'total_amount',
        'total_booked_amount',
        'total_diff',
        'total_gross_wt',
        'out_of_range_rows',
        'skipped_out_of_range_groups',
        'fully_out_of_range_groups',
    ];

    protected $casts = [
        'date_from' => 'date',
        'date_to' => 'date',
        'warnings' => 'array',
        'total_amount' => 'decimal:2',
        'total_booked_amount' => 'decimal:2',
        'total_diff' => 'decimal:2',
        'total_gross_wt' => 'decimal:3',
        'out_of_range_rows' => 'integer',
        'skipped_out_of_range_groups' => 'integer',
        'fully_out_of_range_groups' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function rawRows(): HasMany
    {
        return $this->hasMany(LogsheetRawRow::class, 'import_id');
    }

    public function logsheets(): HasMany
    {
        return $this->hasMany(Logsheet::class, 'last_import_id');
    }

    public function validRawRows(): HasMany
    {
        return $this->hasMany(LogsheetRawRow::class, 'import_id')->where('is_valid', true);
    }

    public function invalidRawRows(): HasMany
    {
        return $this->hasMany(LogsheetRawRow::class, 'import_id')->where('is_valid', false);
    }
}
