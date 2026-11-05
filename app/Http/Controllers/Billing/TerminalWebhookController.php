<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Services\Billing\Terminals\MoniepointTerminal;
use App\Services\Billing\TerminalService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Providers tell us a terminal payment changed. We never take their word for
 * it: the payment it names is looked up and the provider is asked directly.
 */
class TerminalWebhookController extends Controller
{
    public function __construct(private TerminalService $terminals)
    {
    }

    public function moniepoint(Request $request)
    {
        if (! MoniepointTerminal::validWebhook($request->header('moniepoint-webhook-id'), $request->header('moniepoint-webhook-timestamp'), $request->getContent(), $request->header('moniepoint-webhook-signature'))) {
            Log::warning('Moniepoint webhook with a bad signature', ['ip' => $request->ip()]);

            return response()->json(['ok' => false], 401);
        }
        $this->terminals->poke('moniepoint', [(string) $request->json('data.merchantReference'), (string) $request->json('data.customFields.merchantReference')]);

        return response()->json(['ok' => true]);
    }

    /** OPay's notification is encrypted for us; it names the order in a header, which is enough to ask OPay. */
    public function opay(Request $request)
    {
        $this->terminals->poke('opay', [(string) $request->header('X-Opay-Tranid'), (string) $request->input('outOrderNo'), (string) $request->input('orderNo')]);

        return response()->json(['code' => '00000', 'message' => 'SUCCESSFUL']);
    }
}
