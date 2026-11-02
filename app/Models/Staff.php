<?php

namespace App\Models;

use App\Enums\StaffDesignation;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\StaffFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A crew member who can be assigned to a train run.
 *
 * @property int $id
 * @property string $staff_no
 * @property string $name
 * @property StaffDesignation $designation
 * @property string|null $phone
 * @property int|null $home_station_id
 * @property string|null $licence_no
 * @property CarbonImmutable|null $licence_expires_on
 * @property int|null $user_id
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Station|null $homeStation
 * @property-read User|null $user
 */
#[Fillable(['staff_no', 'name', 'designation', 'phone', 'home_station_id', 'licence_no', 'licence_expires_on', 'user_id', 'is_active'])]
class Staff extends Model
{
    /** @use HasFactory<StaffFactory> */
    use HasFactory, LogsActivity;

    protected $table = 'staff';

    protected function casts(): array
    {
        return [
            'designation' => StaffDesignation::class,
            'licence_expires_on' => 'immutable_date',
            'is_active' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty();
    }

    /**
     * @return BelongsTo<Station, $this>
     */
    public function homeStation(): BelongsTo
    {
        return $this->belongsTo(Station::class, 'home_station_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Holds a licence that is still valid on $date (always true for roles that need none).
     */
    public function hasValidLicence(CarbonInterface $date): bool
    {
        if (! $this->designation->needsLicence()) {
            return true;
        }

        return $this->licence_expires_on !== null && $this->licence_expires_on->gte($date->startOfDay());
    }

    public function label(): string
    {
        return "{$this->name} ({$this->staff_no})";
    }
}
