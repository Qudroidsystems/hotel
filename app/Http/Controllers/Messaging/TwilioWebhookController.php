<?php

namespace App\Http\Controllers\Messaging;

use App\Http\Controllers\Controller;
use App\Services\Messaging\GuestMessenger;
use Illuminate\Http\Request;

/**
 * Twilio calls these: delivery reports for texts we sent, and texts guests send
 * back. Both are checked against Twilio's signature, so nobody else can post here.
 * In the Twilio console set the number's "A message comes in" webhook to
 * https://<your site>/twilio/inbound (HTTP POST).
 */
class TwilioWebhookController extends Controller
{
    public function __construct(private GuestMessenger $messenger)
    {
    }

    public function status(Request $request)
    {
        abort_unless($this->messenger->validSignature($request), 403);
        $this->messenger->statusUpdate($request->post());

        return response()->noContent();
    }

    public function inbound(Request $request)
    {
        abort_unless($this->messenger->validSignature($request), 403);
        $this->messenger->inbound($request->post());

        // An empty reply: the front desk answers from the system.
        return response('<?xml version="1.0" encoding="UTF-8"?><Response></Response>', 200, ['Content-Type' => 'text/xml']);
    }
}
