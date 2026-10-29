<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\Website\WebsiteService;
use Illuminate\Http\Request;

/**
 * The guest app's home screen: the same pictures, rooms, offers, dining and news as the public website
 * (Website in the sidebar), and its enquiry and newsletter forms. No sign-in.
 */
class GuestWebsiteController extends ApiController
{
    public function __construct(private WebsiteService $site)
    {
    }

    public function show()
    {
        return $this->ok($this->site->appHome())->header('Cache-Control', 'public, max-age=300');
    }

    public function enquiry(Request $request)
    {
        $kind = in_array($request->input('kind'), ['contact', 'event', 'dining'], true) ? $request->input('kind') : 'contact';
        $data = $request->validate([
            'name'       => 'required|string|max:120',
            'email'      => 'required|email:rfc|max:150',
            'phone'      => ['nullable', 'string', 'max:30', 'regex:/^[0-9+()\-\s]{7,30}$/'],
            'message'    => $kind === 'contact' ? 'required|string|max:4000' : 'nullable|string|max:4000',
            'date'       => [$kind === 'contact' ? 'nullable' : 'required', 'date', 'after_or_equal:today'],
            'time'       => 'nullable|date_format:H:i',
            'guests'     => [$kind === 'contact' ? 'nullable' : 'required', 'integer', 'min:1', 'max:5000'],
            'event_type' => 'nullable|string|max:60',
            'venue'      => 'nullable|string|max:120',
        ], ['phone.regex' => 'Enter a valid phone number.']);
        $m = $this->site->receive($data + ['source' => 'Guest app'], $kind, $request->ip());

        return $this->ok(['id' => $m->id], 'Thank you, ' . $m->name . '. We will get back to you soon.', status: 201);
    }

    public function subscribe(Request $request)
    {
        $data = $request->validate(['email' => 'required|email:rfc|max:150', 'name' => 'nullable|string|max:120']);
        $this->site->subscribe($data['email'], $data['name'] ?? null, 'app');

        return $this->ok(null, 'Thank you, you are on the list.');
    }
}
