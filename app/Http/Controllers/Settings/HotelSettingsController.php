<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\HotelSetting;
use App\Support\Audit;
use App\Support\HotelSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Settings → Hotel Settings: branding, tax, approval limits and the other
 * values in config/hotel.php, editable by the administrator. Every change is
 * written to the audit trail with its old and new value.
 */
class HotelSettingsController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:Manage hotel settings');
    }

    public function index(Request $request)
    {
        $groups = HotelSettings::groups();
        $tab = array_key_exists($request->query('tab'), $groups) ? $request->query('tab') : array_key_first($groups);
        $saved = HotelSetting::with('updatedBy:id,name')->get()->keyBy('key');
        $pagetitle = 'Hotel Settings';

        return view('settings.hotel', compact('pagetitle', 'groups', 'tab', 'saved'));
    }

    public function update(Request $request, string $group)
    {
        $groups = HotelSettings::groups();
        abort_unless(isset($groups[$group]), 404);
        $request->validate(HotelSettings::rules($group), [], $this->attributeNames($groups[$group]['fields']));

        $old = $new = $labels = [];
        DB::transaction(function () use ($request, $groups, $group, &$old, &$new, &$labels) {
            foreach ($groups[$group]['fields'] as $key => $field) {
                $name = HotelSettings::input($key);

                if (HotelSettings::isSecret($field)) {
                    // Keys and passwords: never shown, kept when left empty, stored encrypted.
                    $typed = trim((string) $request->input($name));
                    if ($request->boolean($name . '__remove')) {
                        if (HotelSetting::where('key', $key)->delete()) {
                            $old[$key] = 'set';
                            $new[$key] = HotelSettings::display($field, HotelSettings::default($key)) . ' (from .env)';
                            $labels[] = $field['label'];
                        }
                    } elseif ($typed !== '') {
                        HotelSetting::updateOrCreate(['key' => $key], ['value' => Crypt::encryptString($typed), 'updated_by' => $request->user()->id]);
                        $old[$key] = HotelSettings::display($field, HotelSettings::value($key));
                        $new[$key] = 'changed';
                        $labels[] = $field['label'];
                    }
                    continue;
                }

                if ($field['type'] === 'image') {
                    if ($request->boolean($name . '__remove')) {
                        $value = null;           // back to the default logo
                    } elseif ($request->hasFile($name)) {
                        $value = $this->storeImage($request->file($name), $key);
                    } else {
                        continue;                // nothing uploaded — keep what is there
                    }
                } else {
                    $value = HotelSettings::cast($field, $field['type'] === 'bool' ? $request->boolean($name) : $request->input($name));
                    if ($value === '') {
                        $value = null;           // an emptied box: back to the default
                    }
                }

                // An emptied field goes back to the default.
                $current = HotelSettings::value($key);
                if ($this->same($current, $value ?? HotelSettings::default($key))) {
                    continue;
                }

                // Saving the default value itself just removes the override.
                if ($value === null || $this->same(HotelSettings::default($key), $value)) {
                    HotelSetting::where('key', $key)->delete();
                } else {
                    HotelSetting::updateOrCreate(['key' => $key], ['value' => $value, 'updated_by' => $request->user()->id]);
                }

                $old[$key] = HotelSettings::display($field, $current);
                $new[$key] = HotelSettings::display($field, $value ?? HotelSettings::default($key));
                $labels[] = $field['label'];
            }
        });

        if (! $labels) {
            return redirect()->route('settings.hotel.index', ['tab' => $group])->with('success', 'Nothing changed.');
        }

        Audit::record('settings.updated', 'Changed hotel settings (' . $groups[$group]['title'] . '): ' . implode(', ', $labels), null, $old, $new);

        return redirect()->route('settings.hotel.index', ['tab' => $group])
            ->with('success', count($labels) === 1 ? $labels[0] . ' saved.' : count($labels) . ' settings saved.');
    }

    /** "Use default": drop the saved value so config/.env applies again. */
    public function reset(Request $request)
    {
        $fields = HotelSettings::fields();
        $key = (string) $request->input('key');
        abort_unless(isset($fields[$key]), 404);
        $field = $fields[$key];

        $row = HotelSetting::where('key', $key)->first();
        if ($row) {
            $before = HotelSettings::display($field, $row->value);
            $row->delete();
            Audit::record('settings.reset', 'Reset hotel setting "' . $field['label'] . '" to the default', null,
                [$key => $before], [$key => HotelSettings::display($field, HotelSettings::default($key))]);
        }

        return redirect()->route('settings.hotel.index', ['tab' => $field['group']])->with('success', $field['label'] . ' is back to the default.');
    }

    private function storeImage($file, string $key): string
    {
        $dir = public_path('uploads/branding');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $name = Str::slug($key) . '-' . now()->format('YmdHis') . '-' . Str::lower(Str::random(6)) . '.' . strtolower($file->getClientOriginalExtension() ?: $file->guessExtension());
        $file->move($dir, $name);

        return 'uploads/branding/' . $name;
    }

    private function same(mixed $a, mixed $b): bool
    {
        if (is_numeric($a) && is_numeric($b)) {
            return abs((float) $a - (float) $b) < 0.000001;
        }
        if (is_bool($a) || is_bool($b)) {
            return (bool) $a === (bool) $b;
        }

        return json_encode($a) === json_encode($b);
    }

    private function attributeNames(array $fields): array
    {
        $names = [];
        foreach ($fields as $key => $f) {
            $names[HotelSettings::input($key)] = Str::lower($f['label']);
        }

        return $names;
    }
}
