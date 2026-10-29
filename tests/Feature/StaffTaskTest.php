<?php

use App\Models\Attendance\Department;
use App\Models\Attendance\StaffProfile;
use App\Models\AuditLog;
use App\Models\StaffTask;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    auth()->logout();
    Storage::fake('public');
    $this->kitchen = Department::create(['name' => 'Kitchen', 'code' => 'KIT']);
    $this->events = Department::create(['name' => 'Events', 'code' => 'EVT']);
    $n = 0;
    $make = function (string $name, string $role, ?Department $dept = null) use (&$n) {
        $u = User::create(['name' => $name, 'email' => str($name)->slug() . '@test.local', 'password' => bcrypt('Secret@123')]);
        $u->assignRole($role);
        if ($dept) {
            StaffProfile::create(['user_id' => $u->id, 'staff_no' => 'S' . (++$n), 'department_id' => $dept->id, 'badge_token' => str()->random(32)]);
        }

        return $u;
    };
    $this->gm = $make('Gbenga Manager', 'General Manager');
    $this->chef = $make('Chika Chef', 'Cashier', $this->kitchen);           // kitchen manager below
    $this->cook = $make('Kunle Cook', 'Cashier', $this->kitchen);
    $this->cook2 = $make('Tolu Cook', 'Cashier', $this->kitchen);
    $this->usher = $make('Efe Usher', 'Cashier', $this->events);
    $this->desk = $make('Ada Desk', 'Front Desk');
    $this->kitchen->update(['manager_id' => $this->chef->id]);
});

test('a task for a department is seen by its staff, taken by one, done and checked by the supervisor', function () {
    $this->actingAs($this->desk)->post(route('tasks.store'), [
        'title' => 'Prepare 30 packed lunches for the tour group', 'department_id' => $this->kitchen->id, 'priority' => 'high',
        'due_at' => now()->addHours(3)->format('Y-m-d H:i'), 'needs_check' => 1,
        'files' => [UploadedFile::fake()->image('menu.jpg'), UploadedFile::fake()->create('list.pdf', 20, 'application/pdf')],
    ])->assertRedirect();
    $task = StaffTask::firstOrFail();
    expect($task->number)->toBe('TSK-000001')->and($task->attachments)->toHaveCount(2)->and($task->assigned_to)->toBeNull();
    Storage::disk('public')->assertExists($task->attachments[0]['path']);

    // Everyone in the kitchen sees it; events staff don't.
    $this->actingAs($this->cook)->get(route('tasks.index'))->assertOk()->assertSee('Prepare 30 packed lunches');
    $this->actingAs($this->usher)->get(route('tasks.index'))->assertOk()->assertDontSee('Prepare 30 packed lunches');
    $this->get(route('tasks.show', $task))->assertForbidden();
    // Kitchen staff were alerted.
    expect($this->cook->notifications()->count())->toBe(1)->and($this->usher->notifications()->count())->toBe(0);

    $this->actingAs($this->cook)->post(route('tasks.take', $task))->assertSessionHas('success');
    $this->actingAs($this->cook2)->post(route('tasks.start', $task))->assertSessionHas('error');   // it's Kunle's now
    $this->actingAs($this->cook)->post(route('tasks.start', $task))->assertSessionHas('success');
    $this->post(route('tasks.finish', $task), ['note' => 'Packed in the cold room', 'files' => [UploadedFile::fake()->image('done.jpg')]])->assertSessionHas('success');
    $task->refresh();
    expect($task->status)->toBe(StaffTask::AWAITING_CHECK)->and($task->attachments)->toHaveCount(3);

    // The person who did it can't check it; the kitchen manager sends it back, then confirms.
    $this->post(route('tasks.check', $task), ['decision' => 'ok'])->assertSessionHas('error');
    $this->actingAs($this->chef)->get(route('tasks.index', ['view' => 'check']))->assertSee('Prepare 30 packed lunches');
    $this->post(route('tasks.check', $task), ['decision' => 'redo'])->assertSessionHas('error');   // needs a note
    $this->post(route('tasks.check', $task), ['decision' => 'redo', 'note' => 'Add the fruit'])->assertSessionHas('success');
    expect($task->fresh()->status)->toBe(StaffTask::IN_PROGRESS);
    $this->actingAs($this->cook)->post(route('tasks.finish', $task), ['note' => 'Fruit added']);
    $this->actingAs($this->chef)->post(route('tasks.check', $task), ['decision' => 'ok'])->assertSessionHas('success');
    $task->refresh();
    expect($task->status)->toBe(StaffTask::DONE)->and($task->checked_by)->toBe($this->chef->id)
        ->and($task->updates()->where('kind', 'returned')->count())->toBe(1);

    $this->actingAs($this->cook)->get(route('tasks.show', $task))->assertOk()->assertSee('Sent back: Add the fruit')->assertSee('Checked and confirmed');
    expect(AuditLog::where('action', 'tasks.checked')->count())->toBe(1);
});

test('a task without a check is done straight away; only some people can give, change or cancel', function () {
    $this->actingAs($this->cook)->get(route('tasks.index'))->assertOk()->assertDontSee('Give a task');
    $this->post(route('tasks.store'), ['title' => 'x', 'department_id' => $this->kitchen->id, 'priority' => 'normal'])->assertSessionHas('error');

    $this->actingAs($this->gm)->post(route('tasks.store'), ['title' => 'Count the cutlery', 'assigned_to' => $this->cook2->id, 'priority' => 'low'])->assertSessionHasNoErrors()->assertSessionMissing('error');
    $task = StaffTask::firstOrFail();
    expect($task->department_id)->toBe($this->kitchen->id);   // taken from the person

    $this->actingAs($this->cook)->post(route('tasks.finish', $task))->assertSessionHas('error');   // not his
    $this->actingAs($this->cook)->post(route('tasks.cancel', $task), ['reason' => 'x'])->assertSessionHas('error');
    $this->actingAs($this->chef)->post(route('tasks.change', $task), ['assigned_to' => $this->cook->id, 'department_id' => $this->kitchen->id, 'priority' => 'high'])->assertSessionHas('success');
    expect($task->fresh()->assigned_to)->toBe($this->cook->id);

    $this->actingAs($this->cook)->post(route('tasks.finish', $task))->assertSessionHas('success');
    expect($task->fresh()->status)->toBe(StaffTask::DONE);

    $this->actingAs($this->gm)->post(route('tasks.store'), ['title' => 'Nobody', 'priority' => 'low'])->assertSessionHas('error');
    $this->post(route('tasks.store'), ['title' => 'Set chairs', 'department_id' => $this->events->id, 'priority' => 'normal']);
    $t2 = StaffTask::latest('id')->first();
    $this->post(route('tasks.cancel', $t2), ['reason' => 'Event moved'])->assertSessionHas('success');
    expect($t2->fresh()->status)->toBe(StaffTask::CANCELLED);
    $this->get(route('tasks.index', ['view' => 'all', 'done' => 1]))->assertOk()->assertSee('Set chairs')->assertSee('Count the cutlery');
});

test('overdue tasks remind the department first, then escalate to management', function () {
    $this->actingAs($this->desk)->post(route('tasks.store'), ['title' => 'Clean the grease trap', 'department_id' => $this->kitchen->id, 'priority' => 'high', 'due_at' => now()->addMinutes(30)->format('Y-m-d H:i')]);
    $task = StaffTask::firstOrFail();
    auth()->logout();
    $before = $this->gm->notifications()->count();

    Artisan::call('tasks:escalate');
    expect($task->fresh()->escalation_level)->toBe(0);   // not due yet

    $this->travel(40)->minutes();
    Artisan::call('tasks:escalate');
    $task->refresh();
    expect($task->escalation_level)->toBe(1)
        ->and($this->chef->notifications()->where('data->title', 'like', 'Overdue%')->count())->toBe(1)
        ->and($this->desk->notifications()->where('data->title', 'like', 'Overdue%')->count())->toBe(1)
        ->and($this->gm->notifications()->count())->toBe($before);

    Artisan::call('tasks:escalate');   // nothing more until the grace time (2 h for high) has passed
    expect($task->fresh()->escalation_level)->toBe(1);

    $this->travel(2)->hours();
    Artisan::call('tasks:escalate');
    expect($task->fresh()->escalation_level)->toBe(2)
        ->and($this->gm->notifications()->where('data->title', 'like', 'Escalated%')->count())->toBe(1)
        ->and(AuditLog::where('action', 'tasks.escalated')->where('flagged', true)->count())->toBe(1);
    $this->actingAs($this->gm)->get(route('tasks.index', ['view' => 'overdue']))->assertSee('Clean the grease trap')->assertSee('escalated');

    // A new due time starts the clock again.
    $this->post(route('tasks.change', $task), ['department_id' => $this->kitchen->id, 'priority' => 'high', 'due_at' => now()->addDay()->format('Y-m-d H:i')]);
    expect($task->fresh()->escalation_level)->toBe(0);
});
