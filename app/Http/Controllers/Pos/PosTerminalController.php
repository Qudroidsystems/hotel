<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Services\Pos\PosService;
use Illuminate\Http\Request;

/**
 * The till. The page loads once with everything it needs, then works on its
 * own: sales are saved on the device and sent to /pos/sync whenever the
 * network is up. /pos/ping tells the till whether the server can be reached.
 */
class PosTerminalController extends Controller
{
    public function __construct(private PosService $pos)
    {
        $this->middleware('permission:Use POS');
    }

    public function terminal(Request $request)
    {
        $boot = $this->pos->terminalData($request->user());

        return response()
            ->view('pos.terminal', compact('boot'))
            // Let the offline copy of the till be refreshed whenever we're online.
            ->header('Cache-Control', 'no-cache, private');
    }

    public function data(Request $request)
    {
        return response()->json($this->pos->terminalData($request->user()) + ['csrf' => csrf_token()]);
    }

    public function ping(Request $request)
    {
        return response()->json(['ok' => true, 'time' => now()->toIso8601String(), 'user' => $request->user()->id]);
    }

    public function sync(Request $request)
    {
        $data = $request->validate(['orders' => 'required|array|max:100']);

        return response()->json(['results' => $this->pos->sync($data['orders'], $request->user())]);
    }
}
