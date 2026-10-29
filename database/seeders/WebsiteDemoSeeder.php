<?php

namespace Database\Seeders;

use App\Models\Website\WebsiteItem;
use App\Support\Website;
use Illuminate\Database\Seeder;

/**
 * Sample content for the public website, so it looks finished on first open. Replace it under Website in the
 * sidebar. Pictures come from the admin theme when it is installed. Only adds what is missing.
 * php artisan db:seed --class=WebsiteDemoSeeder
 */
class WebsiteDemoSeeder extends Seeder
{
    private const IMG = 'theme/layouts/assets/images/';

    public function run(): void
    {
        $pic = fn (string $f) => is_file(public_path(self::IMG . $f)) ? self::IMG . $f : null;

        if (! Website::all()) {
            Website::save([
                'home.stats' => ['24|Hour room service', '4|Places to eat & drink', '500|Guests in our halls', '15|Minutes from the airport'],
                'contact.whatsapp' => config('hotel.booking.contact_phone'),
                'announcement' => 'Book direct: breakfast for two is on us on every stay this season.',
                'announcement_url' => '/offers',
                'contact.directions' => "Fifteen minutes from the airport by car. Ask for our airport pick-up when you book and a driver will meet you at arrivals.\n\nFree, secure parking for guests and visitors, with a 24-hour guard.",
            ]);
        }

        $sets = [
            'slide' => [
                ['title' => 'A sanctuary in the heart of the city', 'subtitle' => 'Welcome', 'summary' => 'Calm rooms, an all-day kitchen and a pool to slow down by.', 'image' => $pic('real-estate/img-01.jpg')],
                ['title' => 'Slow mornings, long evenings', 'subtitle' => 'Rooms & suites', 'summary' => 'Generous rooms with everything you need to rest and work.', 'image' => $pic('real-estate/overview-01.jpg'), 'link_label' => 'See the rooms', 'link_url' => '/accommodation'],
                ['title' => 'Celebrate the moments that matter', 'subtitle' => 'Weddings & events', 'summary' => 'Halls for ten guests or five hundred, with a team that has done it many times before.', 'image' => $pic('real-estate/img-07.jpg'), 'link_label' => 'Plan your event', 'link_url' => '/meetings-events'],
            ],
            'amenity' => [
                ['title' => 'Fast Wi-Fi', 'summary' => 'Free in every room and around the hotel.', 'icon' => 'ri-wifi-line'],
                ['title' => 'Airport pick-up', 'summary' => 'A driver waiting at arrivals, booked with your room.', 'icon' => 'ri-plane-line'],
                ['title' => 'Pool & sun deck', 'summary' => 'Open from early morning to late evening.', 'icon' => 'ri-water-flash-line'],
                ['title' => 'Around-the-clock desk', 'summary' => 'Reception and room service, 24 hours a day.', 'icon' => 'ri-customer-service-2-line'],
                ['title' => 'Fitness studio', 'summary' => 'Cardio, weights and towels, any time.', 'icon' => 'ri-run-line'],
                ['title' => 'Secure parking', 'summary' => 'Free for guests, guarded day and night.', 'icon' => 'ri-parking-box-line'],
                ['title' => 'Steady power', 'summary' => 'Back-up power so nothing stops.', 'icon' => 'ri-flashlight-line'],
                ['title' => 'Laundry', 'summary' => 'Same-day pressing and cleaning.', 'icon' => 'ri-t-shirt-air-line'],
            ],
            'dining' => [
                ['title' => 'The Terrace', 'subtitle' => 'All-day dining · African & continental', 'category' => 'Restaurant', 'summary' => 'Breakfast in the sun, long lunches and dinners by candlelight, with a menu built on the best of the local market.', 'body' => "Our main restaurant opens early for breakfast and stays open late.\n\nOn Sundays the family brunch fills every table, so book ahead.", 'meta' => 'Daily, 6:30 AM – 11:00 PM', 'image' => $pic('real-estate/overview-03.jpg')],
                ['title' => 'Amber Lounge', 'subtitle' => 'Cocktails · small plates · live music on Fridays', 'category' => 'Bar', 'summary' => 'A low-lit lounge for an after-work drink, a nightcap, or a long conversation.', 'body' => "Signature cocktails, a short list of wines and a kitchen that keeps going late.\n\nLive music every Friday from 8 PM.", 'meta' => 'Daily, 4:00 PM – 1:00 AM', 'image' => $pic('small/img-12.jpg')],
                ['title' => 'Poolside Grill', 'subtitle' => 'Grills, salads and fresh juice', 'category' => 'Pool bar', 'summary' => 'Easy food and cold drinks served right to your sun lounger.', 'meta' => 'Daily, 10:00 AM – 7:00 PM', 'image' => $pic('real-estate/img-06.jpg')],
            ],
            'experience' => [
                ['title' => 'Pool days', 'category' => 'Leisure', 'summary' => 'A heated pool, cabanas and a bar that brings drinks to you.', 'image' => $pic('real-estate/img-03.jpg'), 'icon' => 'ri-water-flash-line', 'meta' => 'Daily, 7 AM – 9 PM'],
                ['title' => 'City discoveries', 'category' => 'Tours', 'summary' => 'Markets, galleries and the best food in town, with a guide who grew up here.', 'image' => $pic('small/img-3.jpg'), 'icon' => 'ri-map-pin-line', 'meta' => 'Half day, from ₦25,000'],
                ['title' => 'Wellness & massage', 'category' => 'Wellness', 'summary' => 'Massages and treatments in your room or by the pool.', 'image' => $pic('small/img-11.jpg'), 'icon' => 'ri-leaf-line'],
                ['title' => 'Family time', 'category' => 'Family', 'summary' => 'Kids eat free at the Terrace and there are games by the pool every weekend.', 'image' => $pic('real-estate/img-05.jpg'), 'icon' => 'ri-team-line'],
                ['title' => 'Work in comfort', 'category' => 'Business', 'summary' => 'Quiet corners, fast Wi-Fi, printing and meeting rooms by the hour.', 'image' => $pic('real-estate/overview-02.jpg'), 'icon' => 'ri-briefcase-4-line'],
                ['title' => 'Sundowners', 'category' => 'Evenings', 'summary' => 'Golden-hour cocktails on the terrace, every evening from six.', 'image' => $pic('real-estate/img-08.jpg'), 'icon' => 'ri-goblet-line'],
            ],
            'offer' => [
                ['title' => 'Stay longer, pay less', 'subtitle' => 'Three nights or more', 'summary' => 'Stay three nights or more and save on every night, with breakfast included.', 'body' => "Book three nights or more and save 20% on the room.\n\nBreakfast for two is included every morning. Subject to availability.", 'meta' => 'Save 20%', 'promo_code' => 'STAY3', 'image' => $pic('real-estate/overview-01.jpg')],
                ['title' => 'Weekend escape', 'subtitle' => 'Friday to Sunday', 'summary' => 'Two nights, late check-out and a welcome drink at the Amber Lounge.', 'meta' => 'From ₦85,000 a night', 'promo_code' => 'WEEKEND', 'image' => $pic('real-estate/img-01.jpg')],
                ['title' => 'Business traveller', 'subtitle' => 'Work trips made easy', 'summary' => 'Airport pick-up, pressing of one suit and a quiet room on a high floor.', 'meta' => 'Free airport pick-up', 'promo_code' => 'BIZ', 'image' => $pic('real-estate/img-08.jpg')],
            ],
            'gallery' => collect([
                ['real-estate/img-01.jpg', 'Pool', 'The pool at sunset'], ['real-estate/overview-01.jpg', 'Rooms', 'A deluxe room'], ['real-estate/img-03.jpg', 'Pool', 'Morning swim'],
                ['real-estate/overview-03.jpg', 'Dining', 'The Terrace'], ['small/img-12.jpg', 'Dining', 'Fresh juice at the pool bar'], ['real-estate/img-07.jpg', 'Events', 'Garden reception'],
                ['real-estate/overview-02.jpg', 'Rooms', 'Executive suite'], ['real-estate/img-06.jpg', 'Pool', 'Cabanas'], ['real-estate/img-08.jpg', 'Hotel', 'Arrival'],
                ['small/img-3.jpg', 'Experiences', 'Day trips from the hotel'], ['real-estate/img-05.jpg', 'Hotel', 'The gardens'], ['small/img-11.jpg', 'Experiences', 'Escape to the coast'],
            ])->filter(fn ($g) => $pic($g[0]))->map(fn ($g) => ['title' => $g[2], 'summary' => $g[2], 'category' => $g[1], 'image' => $pic($g[0])])->values()->all(),
            'post' => [
                ['title' => 'A new menu for the season', 'category' => 'Dining', 'summary' => 'Our chef has rewritten the Terrace menu around what is best at the market this month.', 'body' => "Every season our kitchen starts again from the market.\n\nThis time: grilled fish with pepper sauce, a slow-cooked goat stew and a coconut tart we think you'll remember.", 'meta' => 'The kitchen team', 'image' => $pic('real-estate/overview-03.jpg'), 'published_at' => now()->subDays(3)],
                ['title' => 'Five things to do this weekend', 'category' => 'The city', 'summary' => 'Markets, music and the best place for suya, picked by our concierge.', 'body' => "Our concierge desk is asked this every Friday, so here is the list.\n\nAsk at reception and we will book a car for any of them.", 'meta' => 'Concierge desk', 'image' => $pic('small/img-3.jpg'), 'published_at' => now()->subDays(9)],
                ['title' => 'Weddings at the hotel: what to know', 'category' => 'Events', 'summary' => 'Halls, menus, rooms for the family and how far ahead to book.', 'body' => "Most couples book six to nine months ahead, especially for Saturdays in December.\n\nOur events team can show you every hall and taste the menu with you.", 'meta' => 'Events team', 'image' => $pic('real-estate/img-07.jpg'), 'published_at' => now()->subDays(20)],
            ],
            'review' => [
                ['title' => 'Adaeze O.', 'summary' => 'The staff remembered our names from the second day. Spotless room, lovely pool and the best breakfast we had all trip.', 'meta' => 'Lagos · stayed in a Deluxe room', 'rating' => 5],
                ['title' => 'Michael B.', 'summary' => 'I travel for work every month. Quiet rooms, fast internet and a car waiting at the airport. Exactly what I needed.', 'meta' => 'London · business trip', 'rating' => 5],
                ['title' => 'Fatima & Yusuf', 'summary' => 'We held our wedding reception here and the team took care of everything. Our guests still talk about the food.', 'meta' => 'Abuja · wedding reception', 'rating' => 5],
            ],
            'faq' => [
                ['title' => 'What time is check-in and check-out?', 'body' => 'Check-in is from ' . config('hotel.booking.check_in_time', '14:00') . ' and check-out is by ' . config('hotel.booking.check_out_time', '12:00') . '. Ask at reception for an early check-in or late check-out; we will do our best.'],
                ['title' => 'Do you offer airport pick-up?', 'body' => 'Yes. Add it when you book, or ask us at least a day ahead. A driver will meet you at arrivals.'],
                ['title' => 'Is parking free?', 'body' => 'Yes, parking is free for guests and visitors, and guarded around the clock.'],
                ['title' => 'Can I change or cancel my booking?', 'body' => 'Open your booking with the link in your confirmation e-mail, or use "My booking" at the top of this page.'],
            ],
            'page' => [
                ['title' => 'Careers', 'subtitle' => 'Join our team', 'summary' => 'Work with us.', 'body' => "We are always glad to meet people who love looking after others.\n\nSend your CV to our HR team using the contact form, with \"Careers\" in your message."],
            ],
        ];

        foreach ($sets as $kind => $rows) {
            if (WebsiteItem::ofKind($kind)->exists()) {
                continue;
            }
            foreach (array_values($rows) as $i => $row) {
                WebsiteItem::create($row + ['kind' => $kind, 'sort_order' => $i + 1, 'is_active' => true, 'is_featured' => false]);
            }
        }
    }
}
