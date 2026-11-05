<?php

namespace App\Services\Mobile\Modules;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * One area of the hotel system in the staff app (tasks, visitors, incidents …).
 *
 * The app has a single screen that draws any module from the JSON this class
 * gives it: tabs, a list of items (each with its details and the buttons this
 * person may press), an optional "new" form and an optional code scanner. The
 * buttons run the same services as the website, with the website's permission
 * checks, so a module never has rules of its own.
 *
 * Field types the app knows: text, textarea, number, money, phone, email,
 * select, date, datetime, switch, photo (one picture), photos (up to four).
 */
abstract class StaffModule
{
    abstract public function key(): string;

    abstract public function title(): string;

    /** A Material icon name the app maps (see lib/screens/modules/module_icons.dart). */
    public function icon(): string
    {
        return 'apps';
    }

    /** Where it sits on the app's "More" page. */
    public function section(): string
    {
        return 'Operations';
    }

    /** One line under the title on the "More" page. */
    public function blurb(): string
    {
        return '';
    }

    abstract public function allowed(User $user): bool;

    /** A number on the module's tile (things waiting), or null. */
    public function count(User $user): ?int
    {
        return null;
    }

    /** [['key' => 'mine', 'label' => 'Mine'], …]; the first is the default. */
    public function tabs(User $user): array
    {
        return [];
    }

    /** The items for a tab (already as arrays made by item()). */
    abstract public function items(User $user, ?string $tab, ?string $search): array;

    /** The model behind an item id, or null. */
    abstract public function find(int $id): ?Model;

    /** One item as the app draws it. */
    abstract public function item(User $user, Model $model): array;

    /** The buttons on an item: [['id', 'label', 'tone' => primary|danger|neutral, 'confirm'?, 'fields'?]]. */
    abstract public function actions(User $user, Model $model): array;

    /** Run a button. Answers the message to show. Throw BookingException when the rules say no. */
    abstract public function perform(User $user, Model $model, string $action, array $input, array $files): string;

    /** The "new" form ['title', 'submit', 'fields'] for this person, or null. */
    public function form(User $user): ?array
    {
        return null;
    }

    /** Save the "new" form. Answers [message, the new model or null]. */
    public function create(User $user, array $input, array $files): array
    {
        abort(404);
    }

    /** ['label' => 'Scan a pass', 'hint' => …] when the module reads codes, else null. */
    public function scanner(User $user): ?array
    {
        return null;
    }

    /** A scanned or typed code: ['state' => ok|warn|bad, 'title', 'message', 'item'?]. */
    public function scan(User $user, string $code): array
    {
        abort(404);
    }

    public function searchable(): bool
    {
        return false;
    }

    public function emptyText(?string $tab): string
    {
        return 'Nothing here right now.';
    }

    // ── Shared by every module ─────────────────────────────────────────

    public function summary(User $user): array
    {
        return [
            'key' => $this->key(), 'title' => $this->title(), 'icon' => $this->icon(), 'section' => $this->section(),
            'blurb' => $this->blurb(), 'count' => $this->count($user),
        ];
    }

    public function page(User $user, ?string $tab, ?string $search): array
    {
        $tabs = $this->tabs($user);
        $keys = array_column($tabs, 'key');
        $tab = $tab && in_array($tab, $keys, true) ? $tab : ($keys[0] ?? null);

        return [
            'key' => $this->key(), 'title' => $this->title(), 'icon' => $this->icon(),
            'tabs' => $tabs, 'tab' => $tab,
            'items' => array_values($this->items($user, $tab, $search !== null && trim($search) !== '' ? trim($search) : null)),
            'form' => $this->form($user),
            'scanner' => $this->scanner($user),
            'searchable' => $this->searchable(),
            'empty' => $this->emptyText($tab),
        ];
    }

    /** The action named $id on this item for this person, or null. */
    public function action(User $user, Model $model, string $id): ?array
    {
        return collect($this->actions($user, $model))->firstWhere('id', $id);
    }

    /**
     * Check what was typed against a list of field specs (the same specs the app draws).
     *
     * @return array{0: array, 1: array<string, UploadedFile|UploadedFile[]>}
     */
    public static function validate(array $fields, array $input, array $files = []): array
    {
        $rules = [];
        $names = [];
        foreach ($fields as $f) {
            $name = $f['name'];
            $names[$name] = $f['label'] ?? $name;
            $req = ! empty($f['required']) ? 'required' : 'nullable';
            $max = $f['max'] ?? null;
            $rules[$name] = match ($f['type'] ?? 'text') {
                'textarea' => [$req, 'string', 'max:' . ($max ?? 2000)],
                'number'   => [$req, 'numeric', ...(isset($f['min']) ? ['min:' . $f['min']] : []), ...($max !== null ? ['max:' . $max] : [])],
                'money'    => [$req, 'numeric', 'min:' . ($f['min'] ?? 0), 'max:' . ($max ?? 999999999)],
                'select'   => [$req, Rule::in(array_map(fn ($o) => (string) $o['value'], $f['options'] ?? []))],
                'date', 'datetime' => [$req, 'date'],
                'email'    => [$req, 'email', 'max:150'],
                'phone'    => [$req, 'string', 'max:30'],
                'switch'   => ['nullable', 'boolean'],
                'photo'    => [$req, 'image', 'mimes:jpeg,png,jpg,webp', 'max:5120'],
                'photos'   => [$req, 'array', 'max:4'],
                default    => [$req, 'string', 'max:' . ($max ?? 255)],
            };
            if (($f['type'] ?? '') === 'photos') {
                $rules[$name . '.*'] = ['image', 'mimes:jpeg,png,jpg,webp', 'max:5120'];
            }
        }
        $data = validator($input + $files, $rules, [], $names)->validate();
        $clean = [];
        $uploads = [];
        foreach ($fields as $f) {
            $name = $f['name'];
            $type = $f['type'] ?? 'text';
            if (in_array($type, ['photo', 'photos'], true)) {
                $uploads[$name] = $files[$name] ?? ($type === 'photos' ? [] : null);
                continue;
            }
            $v = $data[$name] ?? null;
            $clean[$name] = match ($type) {
                'switch' => filter_var($v, FILTER_VALIDATE_BOOLEAN),
                'number', 'money' => $v === null || $v === '' ? null : $v + 0,
                default => is_string($v) ? (trim($v) === '' ? null : trim($v)) : $v,
            };
        }

        return [$clean, $uploads];
    }

    // ── Helpers for building items ─────────────────────────────────────

    protected static function field(string $name, string $label, string $type = 'text', array $extra = []): array
    {
        return ['name' => $name, 'label' => $label, 'type' => $type] + $extra;
    }

    /** [value => label] (or [value => [label, …]]) as select options. */
    protected static function options(array $map): array
    {
        return collect($map)->map(fn ($v, $k) => ['value' => (string) $k, 'label' => is_array($v) ? (string) $v[0] : (string) $v])->values()->all();
    }

    protected static function when(?Carbon $at): ?string
    {
        if (! $at) {
            return null;
        }

        return $at->isToday() ? 'Today ' . $at->format('g:i A') : ($at->isYesterday() ? 'Yesterday ' . $at->format('g:i A') : $at->format('D j M, g:i A'));
    }

    /** Pill tones the app knows: success, warning, danger, info, muted. */
    protected static function tone(string $pill): string
    {
        return match (true) {
            str_contains($pill, 'success') => 'success',
            str_contains($pill, 'danger')  => 'danger',
            str_contains($pill, 'warning') => 'warning',
            str_contains($pill, 'info')    => 'info',
            default => 'muted',
        };
    }

    protected static function lines(array $pairs): array
    {
        return collect($pairs)->filter(fn ($v) => $v !== null && $v !== '')->map(fn ($v, $k) => ['label' => $k, 'value' => (string) $v])->values()->all();
    }

    protected static function money(float|int|string|null $v): string
    {
        return config('hotel.currency_symbol') . number_format((float) $v, 2);
    }
}
