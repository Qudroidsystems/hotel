<?php

namespace App\Services\Security;

use App\Exceptions\BookingException;
use App\Models\Incident;
use App\Models\IncidentUpdate;
use App\Models\LostItem;
use App\Models\User;
use App\Models\Visitor;
use App\Support\Audit;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Visitor register, incidents and lost & found (spec §10, §12.3).
 * Visitors get a random pass code printed as a QR; scanning it at the gate
 * shows whether the pass is still valid. Serious incidents are flagged on the
 * audit trail so management sees them.
 */
class SecurityService
{
    public const PHOTO_DIR = 'security';

    // ── Visitors ──────────────────────────────────────────────────────

    public function signIn(array $data, User $by): Visitor
    {
        $visitor = Visitor::create([
            'pass_code'       => $this->newPassCode(),
            'full_name'       => $data['full_name'],
            'phone'           => $data['phone'] ?? null,
            'id_type'         => $data['id_type'] ?? null,
            'id_number'       => $data['id_number'] ?? null,
            'company'         => $data['company'] ?? null,
            'purpose'         => $data['purpose'],
            'host'            => $data['host'] ?? null,
            'host_user_id'    => $data['host_user_id'] ?? null,
            'reservation_id'  => $data['reservation_id'] ?? null,
            'vehicle_plate'   => isset($data['vehicle_plate']) ? Str::upper($data['vehicle_plate']) : null,
            'party_size'      => $data['party_size'] ?? 1,
            'notes'           => $data['notes'] ?? null,
            'signed_in_at'    => now(),
            'signed_in_by'    => $by->id,
            'expected_out_at' => isset($data['expected_out_at']) ? Carbon::parse($data['expected_out_at']) : now()->addHours(config('hotel.security.pass_hours', 4)),
        ]);
        Audit::record('security.visitor_in', "Signed in visitor {$visitor->full_name} ({$visitor->purpose_label}) to see {$visitor->visiting}", $visitor, by: $by);

        return $visitor;
    }

    public function signOut(Visitor $visitor, User $by): void
    {
        if (! $visitor->isOnSite()) {
            throw new BookingException("{$visitor->full_name} already signed out at " . $visitor->signed_out_at->format('g:i A') . '.');
        }
        $visitor->forceFill(['signed_out_at' => now(), 'signed_out_by' => $by->id])->save();
        Audit::record('security.visitor_out', "Signed out visitor {$visitor->full_name}", $visitor, by: $by);
    }

    /** What the gate sees when a pass is scanned. */
    public function checkPass(string $code): array
    {
        $visitor = Visitor::where('pass_code', Str::upper(trim($code)))->first();
        if (! $visitor) {
            Audit::record('security.pass_rejected', 'Unknown visitor pass scanned', flagged: true, new: ['code' => Str::limit($code, 20)]);

            return ['state' => 'invalid', 'message' => 'Not a pass issued here.', 'visitor' => null];
        }
        if (! $visitor->isOnSite()) {
            return ['state' => 'used', 'message' => 'This visitor signed out ' . $visitor->signed_out_at->diffForHumans() . '. The pass is no longer valid.', 'visitor' => $visitor];
        }
        if ($visitor->isOverstayed()) {
            return ['state' => 'expired', 'message' => 'Pass expired ' . $visitor->expected_out_at->diffForHumans() . '. Ask the visitor to sign out or extend at the desk.', 'visitor' => $visitor];
        }

        return ['state' => 'valid', 'message' => 'Valid until ' . $visitor->expected_out_at?->format('g:i A') . '.', 'visitor' => $visitor];
    }

    public function extend(Visitor $visitor, Carbon $until): void
    {
        if (! $visitor->isOnSite()) {
            throw new BookingException('This visitor has already left.');
        }
        if ($until->isPast()) {
            throw new BookingException('Choose a time later than now.');
        }
        $visitor->forceFill(['expected_out_at' => $until])->save();
    }

    /** Clears personal details of visits older than the retention period. */
    public function pruneVisitors(): int
    {
        $before = now()->subDays(max(1, (int) config('hotel.security.retention_days', 180)));

        return Visitor::whereNull('anonymised_at')->whereNotNull('signed_out_at')->where('signed_out_at', '<', $before)
            ->update(['phone' => null, 'id_number' => null, 'vehicle_plate' => null, 'notes' => null, 'anonymised_at' => now()]);
    }

    // ── Incidents ─────────────────────────────────────────────────────

    /** @param UploadedFile[] $photos */
    public function report(array $data, array $photos, User $by): Incident
    {
        return DB::transaction(function () use ($data, $photos, $by) {
            $incident = Incident::create([
                'type'             => $data['type'],
                'severity'         => $data['severity'],
                'title'            => $data['title'],
                'description'      => $data['description'],
                'location'         => $data['location'] ?? null,
                'room_id'          => $data['room_id'] ?? null,
                'reservation_id'   => $data['reservation_id'] ?? null,
                'occurred_at'      => isset($data['occurred_at']) ? Carbon::parse($data['occurred_at']) : now(),
                'people_involved'  => $data['people_involved'] ?? null,
                'police_reference' => $data['police_reference'] ?? null,
                'reported_by'      => $by->id,
                'assigned_to'      => $data['assigned_to'] ?? null,
            ]);
            $incident->forceFill([
                'number' => 'INC-' . str_pad((string) $incident->id, 6, '0', STR_PAD_LEFT),
                'photos' => $this->storePhotos($photos) ?: null,
            ])->save();
            $this->note($incident, $by, 'Reported.', 'open');

            Audit::record('security.incident_reported', "Reported {$incident->number} ({$incident->severity_label}, {$incident->type_label}) at {$incident->where}: {$incident->title}",
                $incident, new: ['severity' => $incident->severity, 'type' => $incident->type], flagged: $incident->isSerious(), by: $by);

            return $incident;
        });
    }

    public function addUpdate(Incident $incident, User $by, string $note, ?string $status = null): void
    {
        if ($status !== null && ! array_key_exists($status, Incident::STATUSES)) {
            throw new BookingException('Unknown status.');
        }
        if ($status === $incident->status) {
            $status = null;
        }
        if (! $incident->isActive() && $status === null) {
            throw new BookingException("This incident is {$incident->status_label}. Reopen it to add updates.");
        }
        if (in_array($status, ['resolved', 'closed'], true)) {
            $this->resolve($incident, $by, $note, $status);

            return;
        }

        DB::transaction(function () use ($incident, $by, $note, $status) {
            $old = $incident->status;
            if ($status) {
                $incident->forceFill(['status' => $status] + ($status === 'open' || $status === 'investigating' ? ['resolved_at' => null, 'resolved_by' => null] : []))->save();
                Audit::record('security.incident_status', "{$incident->number} moved from {$old} to {$status}", $incident, old: ['status' => $old], new: ['status' => $status], reason: $note, by: $by);
            }
            $this->note($incident, $by, $note, $status);
        });
    }

    public function assign(Incident $incident, ?User $to, User $by): void
    {
        if (! $incident->isActive()) {
            throw new BookingException("This incident is already {$incident->status_label}.");
        }
        $incident->forceFill(['assigned_to' => $to?->id])->save();
        $this->note($incident, $by, $to ? "Assigned to {$to->name}." : 'Unassigned.');
    }

    public function resolve(Incident $incident, User $by, string $resolution, string $status = 'resolved'): void
    {
        if ($incident->status === 'closed') {
            throw new BookingException('This incident is already closed.');
        }
        DB::transaction(function () use ($incident, $by, $resolution, $status) {
            $old = $incident->status;
            $incident->forceFill([
                'status'      => $status,
                'resolution'  => $resolution,
                'resolved_at' => $incident->resolved_at ?? now(),
                'resolved_by' => $incident->resolved_by ?? $by->id,
            ])->save();
            $this->note($incident, $by, $resolution, $status);
            Audit::record('security.incident_' . $status, ucfirst($status) . " {$incident->number}: {$incident->title}", $incident,
                old: ['status' => $old], new: ['status' => $status], reason: $resolution, by: $by);
        });
    }

    private function note(Incident $incident, User $by, string $note, ?string $status = null): void
    {
        IncidentUpdate::create(['incident_id' => $incident->id, 'user_id' => $by->id, 'note' => $note, 'status' => $status]);
    }

    // ── Lost & found ──────────────────────────────────────────────────

    public function logFound(array $data, ?UploadedFile $photo, User $by): LostItem
    {
        $item = LostItem::create([
            'description'      => $data['description'],
            'category'         => $data['category'],
            'valuable'         => ! empty($data['valuable']),
            'found_at'         => isset($data['found_at']) ? Carbon::parse($data['found_at']) : now(),
            'found_location'   => $data['found_location'],
            'room_id'          => $data['room_id'] ?? null,
            'found_by'         => $data['found_by'] ?? $by->id,
            'storage_location' => $data['storage_location'] ?? null,
            'photo_path'       => $photo && $photo->isValid() ? $photo->store(self::PHOTO_DIR, 'public') : null,
        ]);
        $item->forceFill(['number' => 'LF-' . str_pad((string) $item->id, 6, '0', STR_PAD_LEFT)])->save();
        Audit::record('security.item_found', "Logged {$item->number}: {$item->description} found at {$item->found_location}", $item,
            new: ['category' => $item->category, 'valuable' => $item->valuable], by: $by);

        return $item;
    }

    public function handOver(LostItem $item, array $claim, User $by): void
    {
        $this->ensureStored($item);
        $item->forceFill([
            'status'           => 'claimed',
            'claimant_name'    => $claim['claimant_name'],
            'claimant_contact' => $claim['claimant_contact'] ?? null,
            'claimant_id'      => $claim['claimant_id'],
            'handed_over_at'   => now(),
            'handed_over_by'   => $by->id,
        ])->save();
        Audit::record('security.item_returned', "Returned {$item->number} ({$item->description}) to {$item->claimant_name}", $item,
            new: ['claimant' => $item->claimant_name, 'id_checked' => $item->claimant_id], flagged: $item->valuable, by: $by);
    }

    public function dispose(LostItem $item, string $note, User $by): void
    {
        $this->ensureStored($item);
        $item->forceFill(['status' => 'disposed', 'disposal_note' => $note, 'handed_over_at' => now(), 'handed_over_by' => $by->id])->save();
        Audit::record('security.item_disposed', "Disposed of {$item->number} ({$item->description})", $item, reason: $note, flagged: $item->valuable, by: $by);
    }

    private function ensureStored(LostItem $item): void
    {
        if ($item->status !== 'stored') {
            throw new BookingException("{$item->number} is no longer in storage ({$item->status_label}).");
        }
    }

    // ------------------------------------------------------------------

    private function newPassCode(): string
    {
        do {
            $code = 'V' . Str::upper(Str::random(9));
        } while (Visitor::where('pass_code', $code)->exists());

        return $code;
    }

    /** @param UploadedFile[] $photos */
    private function storePhotos(array $photos): array
    {
        $saved = [];
        foreach ($photos as $file) {
            if ($file instanceof UploadedFile && $file->isValid()) {
                $saved[] = $file->store(self::PHOTO_DIR, 'public');
            }
        }

        return $saved;
    }
}
