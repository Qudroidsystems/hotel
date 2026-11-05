<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BookingException;
use App\Services\Mobile\Modules\ModuleRegistry;
use App\Services\Mobile\Modules\StaffModule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The staff app's "More" page: every other area of the hotel system (tasks,
 * visitors, incidents, check-in …), drawn by one screen from what each
 * module says. See App\Services\Mobile\Modules\StaffModule.
 */
class ModuleController extends ApiController
{
    public function __construct(private ModuleRegistry $modules)
    {
    }

    /** GET /modules */
    public function index(Request $request)
    {
        $user = $request->user();

        return $this->ok($this->modules->for($user)->map(fn (StaffModule $m) => $m->summary($user))->values());
    }

    /** GET /modules/{key}?tab=&q= */
    public function show(Request $request, string $key)
    {
        $m = $this->module($request, $key);

        return $this->ok($m->page($request->user(), $request->query('tab'), $request->query('q')));
    }

    /** GET /modules/{key}/items/{id} */
    public function item(Request $request, string $key, int $id)
    {
        $m = $this->module($request, $key);
        $model = $m->find($id) ?? abort(404, 'That is no longer there.');

        return $this->ok($m->item($request->user(), $model));
    }

    /** POST /modules/{key}/items/{id}/actions/{action}  (fields of the action; multipart when it has photos) */
    public function act(Request $request, string $key, int $id, string $action)
    {
        $m = $this->module($request, $key);
        $user = $request->user();
        $model = $m->find($id) ?? throw new BookingException('That is no longer there. Pull down to refresh.');
        $m->item($user, $model);   // may this person see it at all?
        $spec = $m->action($user, $model, $action);
        if (! $spec) {
            throw new BookingException('That can’t be done now — someone may have done it already. Pull down to refresh.');
        }
        [$input, $files] = StaffModule::validate(array_values(array_filter($spec['fields'] ?? [])), $request->except(['_token']), $this->files($request));

        $message = DB::transaction(fn () => $m->perform($user, $model, $action, $input, $files));
        $fresh = $m->find($id);

        return $this->ok(['item' => $fresh ? $this->safeItem($m, $request, $fresh) : null], $message);
    }

    /** POST /modules/{key}  (the "new" form) */
    public function create(Request $request, string $key)
    {
        $m = $this->module($request, $key);
        $form = $m->form($request->user()) ?? abort(403, 'You can’t add here.');
        [$input, $files] = StaffModule::validate($form['fields'], $request->except(['_token']), $this->files($request));
        [$message, $model] = DB::transaction(fn () => $m->create($request->user(), $input, $files));

        return $this->ok(['item' => $model ? $this->safeItem($m, $request, $model->fresh()) : null], $message, status: 201);
    }

    /** POST /modules/{key}/scan {code} */
    public function scan(Request $request, string $key)
    {
        $m = $this->module($request, $key);
        abort_unless($m->scanner($request->user()), 404);
        $code = trim((string) $request->validate(['code' => 'required|string|max:200'])['code']);
        // A QR code may hold a whole link: the code is its last part.
        if (str_contains($code, '/')) {
            $code = basename(parse_url($code, PHP_URL_PATH) ?: $code);
        }

        return $this->ok($m->scan($request->user(), $code));
    }

    private function module(Request $request, string $key): StaffModule
    {
        $m = $this->modules->get($key) ?? abort(404, 'Not in this version of the hotel system.');
        abort_unless($m->allowed($request->user()), 403, 'You don’t have access to ' . $m->title() . '.');

        return $m;
    }

    private function files(Request $request): array
    {
        return collect($request->allFiles())->map(fn ($f) => $f)->all();
    }

    /** The item after a change, or null when this person can no longer see it. */
    private function safeItem(StaffModule $m, Request $request, $model): ?array
    {
        try {
            return $m->item($request->user(), $model);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException) {
            return null;
        }
    }
}
