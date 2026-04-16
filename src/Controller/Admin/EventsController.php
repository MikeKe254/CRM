<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Services\ActivityLog\UserActivityLogService;
use App\Services\Auth\AuthService;
use App\Services\Branch\BranchResolverService;
use App\Services\Permission\CheckPermissionService;
use App\Services\Permission\PlatformCheckPermissionService;
use App\Services\Revenue\EventService;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(
    '/{branch}/dashboard/admin/events',
    host: '{subdomain}.{domain}',
    requirements: ['subdomain' => '(?!admin\.)[A-Za-z0-9-]+', 'domain' => '.+', 'branch' => '[A-Za-z0-9-]+'],
)]
class EventsController extends AdminBaseController
{
    public function __construct(
        AuthService                    $auth,
        CheckPermissionService         $can,
        PlatformCheckPermissionService $platformCan,
        BranchResolverService          $branchResolver,
        Connection                     $db,
        private readonly EventService             $events,
        private readonly UserActivityLogService   $activityLog,
    ) {
        parent::__construct($auth, $can, $platformCan, $branchResolver, $db);
    }

    // =========================================================================
    // GET — List
    // =========================================================================

    #[Route('', name: 'admin_events', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $session = $this->requireAdmin($request, 'view_events');
        if ($session instanceof Response) return $session;

        $showAll = (bool) $request->query->get('all', false);
        $events  = $this->events->list($session->company->id, $session->branch->id, $showAll);

        // Annotate each event with its current running state and human description
        $now = new \DateTimeImmutable('now');
        foreach ($events as &$event) {
            $event['_is_running']   = $this->events->isRunningNow($event, $now);
            $event['_recurrence']   = EventService::describeRecurrence($event);
        }
        unset($event);

        return $this->render('admin/events/index.html.twig', [
            'session' => $session,
            'events'  => $events,
            'showAll' => $showAll,
            'can'     => [
                'create' => $this->can->check($session, 'create_events'),
                'edit'   => $this->can->check($session, 'edit_events'),
                'delete' => $this->can->check($session, 'delete_events'),
            ],
        ]);
    }

    // =========================================================================
    // POST — Create
    // =========================================================================

    #[Route('/create', name: 'admin_events_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $session = $this->requireAdmin($request, 'create_events');
        if ($session instanceof Response) return $this->error('Unauthorized.', 403);

        $entryType      = $request->request->get('entry_type', 'event');
        $name           = trim((string) $request->request->get('name', ''));
        $description    = trim((string) $request->request->get('description', '')) ?: null;
        $recurrenceType = $request->request->get('recurrence_type', 'none');

        if (!in_array($entryType, ['event', 'offer'], true)) $entryType = 'event';
        if ($name === '') return $this->error('Name is required.');
        if (strlen($name) > 120) return $this->error('Name must be 120 characters or fewer.');
        if (!in_array($recurrenceType, ['none', 'daily', 'weekly', 'biweekly', 'monthly'], true)) {
            return $this->error('Invalid recurrence type.');
        }

        // One-off fields
        $startsAt = null;
        $endsAt   = null;
        if ($recurrenceType === 'none') {
            $startsAt = trim((string) $request->request->get('starts_at', '')) ?: null;
            $endsAt   = trim((string) $request->request->get('ends_at',   '')) ?: null;
            if ($startsAt && $endsAt && $endsAt <= $startsAt) {
                return $this->error('End time must be after start time.');
            }
        }

        // Recurrence fields
        $recurrenceDays       = null;
        $recurrenceMonthlyDay = null;
        $recurrenceTimeStart  = trim((string) $request->request->get('recurrence_time_start', '')) ?: null;
        $recurrenceTimeEnd    = trim((string) $request->request->get('recurrence_time_end',   '')) ?: null;
        $recurrenceValidFrom  = trim((string) $request->request->get('recurrence_valid_from',  '')) ?: null;
        $recurrenceValidUntil = trim((string) $request->request->get('recurrence_valid_until', '')) ?: null;

        if (in_array($recurrenceType, ['weekly', 'biweekly'], true)) {
            $daysRaw = $request->request->all('recurrence_days');
            $recurrenceDays = array_values(array_filter(
                array_map('intval', (array) $daysRaw),
                fn($d) => $d >= 0 && $d <= 6,
            ));
            if (empty($recurrenceDays)) {
                return $this->error('Select at least one day for weekly/biweekly events.');
            }
        }

        if ($recurrenceType === 'monthly') {
            $monthlyMode = $request->request->get('monthly_mode', 'date');
            $monthlyDayRaw = (int) $request->request->get('recurrence_monthly_day', 0);

            if ($monthlyMode === 'weekday') {
                $daysRaw = $request->request->all('recurrence_days');
                $recurrenceDays = array_values(array_filter(
                    array_map('intval', (array) $daysRaw),
                    fn($d) => $d >= 0 && $d <= 6,
                ));
                if (empty($recurrenceDays)) {
                    return $this->error('Select the day of the week for monthly events.');
                }
                if ($monthlyDayRaw < 1 || $monthlyDayRaw > 5) {
                    return $this->error('Select which occurrence (1st–Last) for monthly events.');
                }
                $recurrenceMonthlyDay = $monthlyDayRaw;
            } else {
                if ($monthlyDayRaw < 1 || $monthlyDayRaw > 31) {
                    return $this->error('Select a valid day of the month (1–31).');
                }
                $recurrenceMonthlyDay = $monthlyDayRaw;
            }
        }

        if (in_array($recurrenceType, ['daily', 'weekly', 'biweekly', 'monthly'], true)) {
            if (!$recurrenceTimeStart || !$recurrenceTimeEnd) {
                return $this->error('A time window (start and end time) is required for recurring events.');
            }
        }

        $id = $this->events->create(
            $session->company->id,
            $session->branch->id,
            $name,
            $description,
            $startsAt,
            $endsAt,
            $recurrenceType,
            $recurrenceDays,
            $recurrenceTimeStart,
            $recurrenceTimeEnd,
            $recurrenceValidFrom,
            $recurrenceValidUntil,
            $recurrenceMonthlyDay,
            $entryType,
        );

        $this->activityLog->record($session, 'event.created', [
            'event_id'        => $id,
            'event_name'      => $name,
            'recurrence_type' => $recurrenceType,
        ]);

        return $this->success('Event created.', ['id' => $id, 'name' => $name, 'recurrence_type' => $recurrenceType]);
    }

    // =========================================================================
    // POST — Update
    // =========================================================================

    #[Route('/{id}/update', name: 'admin_events_update', methods: ['POST'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $session = $this->requireAdmin($request, 'edit_events');
        if ($session instanceof Response) return $this->error('Unauthorized.', 403);

        $event = $this->events->findById($id, $session->company->id, $session->branch->id);
        if (!$event) return $this->error('Event not found.', 404);

        $entryType      = $request->request->get('entry_type', 'event');
        $name           = trim((string) $request->request->get('name', ''));
        $description    = trim((string) $request->request->get('description', '')) ?: null;
        $recurrenceType = $request->request->get('recurrence_type', 'none');

        if (!in_array($entryType, ['event', 'offer'], true)) $entryType = 'event';
        if ($name === '') return $this->error('Name is required.');
        if (strlen($name) > 120) return $this->error('Name must be 120 characters or fewer.');
        if (!in_array($recurrenceType, ['none', 'daily', 'weekly', 'biweekly', 'monthly'], true)) {
            return $this->error('Invalid recurrence type.');
        }

        $startsAt = null;
        $endsAt   = null;
        if ($recurrenceType === 'none') {
            $startsAt = trim((string) $request->request->get('starts_at', '')) ?: null;
            $endsAt   = trim((string) $request->request->get('ends_at',   '')) ?: null;
            if ($startsAt && $endsAt && $endsAt <= $startsAt) {
                return $this->error('End time must be after start time.');
            }
        }

        $recurrenceDays       = null;
        $recurrenceMonthlyDay = null;
        $recurrenceTimeStart  = trim((string) $request->request->get('recurrence_time_start', '')) ?: null;
        $recurrenceTimeEnd    = trim((string) $request->request->get('recurrence_time_end',   '')) ?: null;
        $recurrenceValidFrom  = trim((string) $request->request->get('recurrence_valid_from',  '')) ?: null;
        $recurrenceValidUntil = trim((string) $request->request->get('recurrence_valid_until', '')) ?: null;

        if (in_array($recurrenceType, ['weekly', 'biweekly'], true)) {
            $daysRaw = $request->request->all('recurrence_days');
            $recurrenceDays = array_values(array_filter(
                array_map('intval', (array) $daysRaw),
                fn($d) => $d >= 0 && $d <= 6,
            ));
            if (empty($recurrenceDays)) {
                return $this->error('Select at least one day for weekly/biweekly events.');
            }
        }

        if ($recurrenceType === 'monthly') {
            $monthlyMode   = $request->request->get('monthly_mode', 'date');
            $monthlyDayRaw = (int) $request->request->get('recurrence_monthly_day', 0);

            if ($monthlyMode === 'weekday') {
                $daysRaw = $request->request->all('recurrence_days');
                $recurrenceDays = array_values(array_filter(
                    array_map('intval', (array) $daysRaw),
                    fn($d) => $d >= 0 && $d <= 6,
                ));
                if (empty($recurrenceDays)) {
                    return $this->error('Select the day of the week for monthly events.');
                }
                if ($monthlyDayRaw < 1 || $monthlyDayRaw > 5) {
                    return $this->error('Select which occurrence (1st–Last) for monthly events.');
                }
                $recurrenceMonthlyDay = $monthlyDayRaw;
            } else {
                if ($monthlyDayRaw < 1 || $monthlyDayRaw > 31) {
                    return $this->error('Select a valid day of the month (1–31).');
                }
                $recurrenceMonthlyDay = $monthlyDayRaw;
            }
        }

        if (in_array($recurrenceType, ['daily', 'weekly', 'biweekly', 'monthly'], true)) {
            if (!$recurrenceTimeStart || !$recurrenceTimeEnd) {
                return $this->error('A time window (start and end time) is required for recurring events.');
            }
        }

        $this->events->update(
            $id,
            $session->company->id,
            $session->branch->id,
            $name,
            $description,
            $startsAt,
            $endsAt,
            $recurrenceType,
            $recurrenceDays,
            $recurrenceTimeStart,
            $recurrenceTimeEnd,
            $recurrenceValidFrom,
            $recurrenceValidUntil,
            $recurrenceMonthlyDay,
            $entryType,
        );

        $this->activityLog->record($session, 'event.updated', [
            'event_id'        => $id,
            'event_name'      => $name,
            'recurrence_type' => $recurrenceType,
        ]);

        return $this->success('Event updated.', ['id' => $id, 'name' => $name]);
    }

    // =========================================================================
    // POST — Set status (cancel / restore to draft)
    // =========================================================================

    #[Route('/{id}/set-status', name: 'admin_events_set_status', methods: ['POST'])]
    public function setStatus(int $id, Request $request): JsonResponse
    {
        $session = $this->requireAdmin($request, 'edit_events');
        if ($session instanceof Response) return $this->error('Unauthorized.', 403);

        $event = $this->events->findById($id, $session->company->id, $session->branch->id);
        if (!$event) return $this->error('Event not found.', 404);

        $newStatus = $request->request->get('status', '');
        if (!in_array($newStatus, ['draft', 'cancelled'], true)) {
            return $this->error('Invalid status. Use cancelled or draft.');
        }

        $this->events->setStatus($id, $session->company->id, $session->branch->id, $newStatus);

        $this->activityLog->record($session, 'event.status_changed', [
            'event_id'   => $id,
            'event_name' => $event['name'],
            'new_status' => $newStatus,
        ]);

        return $this->success('Event status updated.', ['id' => $id, 'status' => $newStatus]);
    }

    // =========================================================================
    // POST — Delete
    // =========================================================================

    #[Route('/{id}/delete', name: 'admin_events_delete', methods: ['POST'])]
    public function delete(int $id, Request $request): JsonResponse
    {
        $session = $this->requireAdmin($request, 'delete_events');
        if ($session instanceof Response) return $this->error('Unauthorized.', 403);

        $event = $this->events->findById($id, $session->company->id, $session->branch->id);
        if (!$event) return $this->error('Event not found.', 404);

        $this->events->delete($id, $session->company->id, $session->branch->id);

        $this->activityLog->record($session, 'event.deleted', [
            'event_id'   => $id,
            'event_name' => $event['name'],
        ]);

        return $this->success('Event deleted.');
    }
}
