<?php

namespace App\Filament\Resources\AuditLogs;

use App\Filament\Resources\AuditLogs\Pages\ListAuditLogs;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use UnitEnum;

/**
 * Who changed what: every change recorded by spatie/laravel-activitylog. Read-only, admin only.
 */
class AuditLogResource extends Resource
{
    protected static ?string $model = Activity::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Admin';

    protected static ?string $navigationLabel = 'Audit log';

    protected static ?string $modelLabel = 'audit entry';

    protected static ?string $slug = 'audit-log';

    /** Field names that are never shown, even if a model logs them. */
    public const array HIDDEN_FIELDS = ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'];

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('causer'))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('When')->dateTime('d M Y H:i:s')->sortable(),
                TextColumn::make('causer.name')->label('Who')->placeholder('System')->searchable(),
                TextColumn::make('event')
                    ->badge()
                    ->color(fn (?string $state) => match ($state) {
                        'created' => 'success',
                        'updated' => 'warning',
                        'deleted' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('subject_type')
                    ->label('Record')
                    ->formatStateUsing(fn (?string $state, Activity $record) => self::recordLabel($record))
                    ->searchable(query: fn (Builder $query, string $search) => $query->where('subject_id', $search)),
                TextColumn::make('changes_summary')
                    ->label('Changed')
                    ->state(fn (Activity $record) => self::summary($record))
                    ->wrap()
                    ->limit(80),
            ])
            ->filters([
                SelectFilter::make('subject_type')
                    ->label('Record type')
                    ->options(fn () => Activity::query()->distinct()->whereNotNull('subject_type')->pluck('subject_type')
                        ->mapWithKeys(fn (string $type) => [$type => self::typeLabel($type)])
                        ->sort()
                        ->all()),
                SelectFilter::make('event')->options(['created' => 'Created', 'updated' => 'Updated', 'deleted' => 'Deleted']),
                SelectFilter::make('causer_id')
                    ->label('Who')
                    ->options(fn () => User::query()->whereIn('id', Activity::query()->whereNotNull('causer_id')->select('causer_id'))->orderBy('name')->pluck('name', 'id')->all())
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null, fn (Builder $q, $id) => $q->where('causer_type', (new User)->getMorphClass())->where('causer_id', $id))),
                Filter::make('dates')
                    ->schema([
                        DatePicker::make('from'),
                        DatePicker::make('until'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $d) => $q->whereDate('created_at', '>=', $d))
                        ->when($data['until'] ?? null, fn (Builder $q, $d) => $q->whereDate('created_at', '<=', $d))),
            ])
            ->recordActions([
                Action::make('details')
                    ->label('Details')
                    ->icon(Heroicon::OutlinedEye)
                    ->color('gray')
                    ->modalHeading(fn (Activity $record) => self::recordLabel($record).' · '.ucfirst((string) $record->event))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->modalContent(fn (Activity $record) => view('filament.audit.details', [
                        'activity' => $record,
                        'changes' => self::changes($record),
                    ])),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAuditLogs::route('/'),
        ];
    }

    public static function typeLabel(?string $type): string
    {
        return $type === null ? '—' : Str::headline(class_basename($type));
    }

    public static function recordLabel(Activity $activity): string
    {
        return self::typeLabel($activity->subject_type).($activity->subject_id === null ? '' : " #{$activity->subject_id}");
    }

    /**
     * Field => [old, new]; old is null for a created record.
     *
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    public static function changes(Activity $activity): array
    {
        $changes = $activity->attribute_changes?->toArray() ?? [];
        $new = (array) ($changes['attributes'] ?? []);
        $old = (array) ($changes['old'] ?? []);
        $rows = [];

        foreach (array_unique([...array_keys($old), ...array_keys($new)]) as $field) {
            if (! in_array($field, self::HIDDEN_FIELDS, true)) {
                $rows[(string) $field] = [$old[$field] ?? null, $new[$field] ?? null];
            }
        }

        return $rows;
    }

    public static function summary(Activity $activity): string
    {
        $changes = self::changes($activity);

        if ($changes === []) {
            return (string) $activity->description;
        }

        if ($activity->event === 'created' || $activity->event === 'deleted') {
            return implode(', ', array_map(fn ($f) => Str::headline($f), array_keys($changes)));
        }

        return implode('; ', array_map(
            fn (string $field, array $pair) => Str::headline($field).': '.self::show($pair[0]).' → '.self::show($pair[1]),
            array_keys($changes),
            $changes,
        ));
    }

    public static function show(mixed $value): string
    {
        return match (true) {
            $value === null => '—',
            is_bool($value) => $value ? 'yes' : 'no',
            is_array($value) => json_encode($value, JSON_UNESCAPED_UNICODE) ?: '',
            default => (string) $value,
        };
    }
}
