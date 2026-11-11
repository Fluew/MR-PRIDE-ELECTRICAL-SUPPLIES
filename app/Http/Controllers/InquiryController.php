<?php

namespace App\Http\Controllers;

use App\Models\Inquiry;
use App\Models\Team;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\ResendService;
use App\Support\Csv;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InquiryController extends Controller
{
    /**
     * Display inquiries/messages management module.
     */
    public function index(Request $request, mixed $current_team = null): Response
    {
        $team = $this->getTeam($request, $current_team);
        abort_if(! $team, 403, 'No active team selected.');

        $search = $request->input('search');
        $status = $request->input('status', 'all');
        $priority = $request->input('priority', 'all');
        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');

        $query = Inquiry::where('team_id', $team->id)
            ->with([
                'assignedTo:id,name,email,role',
                'repliedBy:id,name,email',
            ]);

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('subject', 'like', "%{$search}%")
                    ->orWhere('message', 'like', "%{$search}%");
            });
        }

        if ($status && $status !== 'all') {
            $query->where('status', $status);
        }

        if ($priority && $priority !== 'all') {
            $query->where('priority', $priority);
        }

        if ($dateFrom) {
            $query->whereDate('created_at', '>=', $dateFrom);
        }

        if ($dateTo) {
            $query->whereDate('created_at', '<=', $dateTo);
        }

        // Metrics calculations
        $baseQuery = Inquiry::where('team_id', $team->id);
        $totalInquiries = (clone $baseQuery)->count();
        $unreadCount = (clone $baseQuery)->where('status', 'unread')->count();
        $inProgressCount = (clone $baseQuery)->where('status', 'in_progress')->count();
        $resolvedCount = (clone $baseQuery)->where('status', 'resolved')->count();
        $todayCount = (clone $baseQuery)->whereDate('created_at', Carbon::today())->count();

        // Eligible assignees (admin or customer service personnel)
        $staffMembers = User::where(function ($q) {
            $q->whereIn('role', [
                'admin',
                'superadmin',
                'owner',
                'staff_customer_service',
                'customer_service_representative',
                'customer_service',
            ]);
        })
            ->where('status', 'active')
            ->select(['id', 'name', 'email', 'role'])
            ->orderBy('name')
            ->get();

        $inquiries = $query->orderByDesc('created_at')->paginate(15)->withQueryString();

        return Inertia::render('Inquiries/Index', [
            'inquiries' => $inquiries,
            'metrics' => [
                'totalInquiries' => $totalInquiries,
                'unreadCount' => $unreadCount,
                'inProgressCount' => $inProgressCount,
                'resolvedCount' => $resolvedCount,
                'todayCount' => $todayCount,
            ],
            'filters' => [
                'search' => $search,
                'status' => $status,
                'priority' => $priority,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ],
            'staffMembers' => $staffMembers,
        ]);
    }

    /**
     * Store a new public inquiry from the Contact Us page.
     */
    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'subject' => ['nullable', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:5000'],
        ]);

        $defaultTeam = Team::first();
        $teamId = $defaultTeam?->id ?? 1;

        $inquiry = Inquiry::create([
            'team_id' => $teamId,
            'name' => trim($validated['name']),
            'email' => strtolower(trim($validated['email'])),
            'phone' => $validated['phone'] ? trim($validated['phone']) : null,
            'subject' => $validated['subject'] ? trim($validated['subject']) : 'General Inquiry',
            'message' => trim($validated['message']),
            'status' => 'unread',
            'priority' => 'normal',
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        AuditLogger::log(
            'inquiry.submitted',
            "New contact inquiry received from {$inquiry->name} ({$inquiry->email}) with subject '{$inquiry->subject}'.",
            null,
            [
                'inquiry_id' => $inquiry->id,
                'name' => $inquiry->name,
                'email' => $inquiry->email,
                'subject' => $inquiry->subject,
            ],
            $request,
            'audit',
            'info',
            null,
            'success'
        );

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Thank you for contacting us! We have received your message and will respond promptly.',
                'inquiry' => $inquiry,
            ]);
        }

        return back()->with('success', 'Thank you for reaching out! Your message has been sent successfully.');
    }

    /**
     * Update inquiry status.
     */
    public function updateStatus(Request $request, mixed $current_team = null, mixed $inquiry = null): RedirectResponse
    {
        $inquiry = $this->resolveInquiry($inquiry, $current_team, $request);

        $validated = $request->validate([
            'status' => ['required', Rule::in(['unread', 'read', 'in_progress', 'resolved', 'archived'])],
        ]);

        $oldStatus = $inquiry->status;
        $inquiry->update([
            'status' => $validated['status'],
        ]);

        AuditLogger::log(
            'inquiry.status_updated',
            "Inquiry #{$inquiry->id} status changed from {$oldStatus} to {$validated['status']}.",
            $request->user(),
            [
                'inquiry_id' => $inquiry->id,
                'old_status' => $oldStatus,
                'new_status' => $validated['status'],
            ],
            $request
        );

        return back()->with('success', "Inquiry marked as {$validated['status']}.");
    }

    /**
     * Update inquiry priority.
     */
    public function updatePriority(Request $request, mixed $current_team = null, mixed $inquiry = null): RedirectResponse
    {
        $inquiry = $this->resolveInquiry($inquiry, $current_team, $request);

        $validated = $request->validate([
            'priority' => ['required', Rule::in(['low', 'normal', 'high', 'urgent'])],
        ]);

        $inquiry->update([
            'priority' => $validated['priority'],
        ]);

        return back()->with('success', "Inquiry priority updated to {$validated['priority']}.");
    }

    /**
     * Update internal administrative/staff notes.
     */
    public function updateNotes(Request $request, mixed $current_team = null, mixed $inquiry = null): RedirectResponse
    {
        $inquiry = $this->resolveInquiry($inquiry, $current_team, $request);

        $validated = $request->validate([
            'admin_notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $inquiry->update([
            'admin_notes' => $validated['admin_notes'] ? trim($validated['admin_notes']) : null,
        ]);

        return back()->with('success', 'Internal notes updated successfully.');
    }

    /**
     * Assign inquiry to a team staff member.
     */
    public function assign(Request $request, mixed $current_team = null, mixed $inquiry = null): RedirectResponse
    {
        $inquiry = $this->resolveInquiry($inquiry, $current_team, $request);

        $validated = $request->validate([
            'assigned_to_id' => ['nullable', 'exists:users,id'],
        ]);

        $inquiry->update([
            'assigned_to_id' => $validated['assigned_to_id'],
        ]);

        $assigneeName = $inquiry->assignedTo?->name ?? 'Unassigned';

        AuditLogger::log(
            'inquiry.assigned',
            "Inquiry #{$inquiry->id} assigned to {$assigneeName}.",
            $request->user(),
            ['inquiry_id' => $inquiry->id, 'assigned_to_id' => $validated['assigned_to_id']],
            $request
        );

        return back()->with('success', "Inquiry assigned to {$assigneeName}.");
    }

    /**
     * Reply to an inquiry.
     */
    public function reply(Request $request, mixed $current_team = null, mixed $inquiry = null): RedirectResponse
    {
        $inquiry = $this->resolveInquiry($inquiry, $current_team, $request);

        $validated = $request->validate([
            'reply_message' => ['required', 'string', 'max:10000'],
            'status' => ['nullable', Rule::in(['in_progress', 'resolved', 'read'])],
        ]);

        $replyText = trim($validated['reply_message']);
        $newStatus = $validated['status'] ?? 'resolved';

        $inquiry->update([
            'reply_message' => $replyText,
            'replied_at' => now(),
            'replied_by_id' => $request->user()?->id,
            'status' => $newStatus,
        ]);

        // Attempt sending email via Resend
        $resendService = app(ResendService::class);
        $emailResult = $resendService->sendInquiryReply(
            $inquiry->email,
            $inquiry->name,
            $inquiry->subject ?: 'Customer Inquiry',
            $replyText,
            $inquiry->message
        );

        AuditLogger::log(
            'inquiry.replied',
            "Sent reply to inquiry #{$inquiry->id} ({$inquiry->email}). Status set to {$newStatus}.",
            $request->user(),
            [
                'inquiry_id' => $inquiry->id,
                'email' => $inquiry->email,
                'email_sent' => $emailResult['success'] ?? false,
            ],
            $request
        );

        $msg = (! empty($emailResult['success']))
            ? 'Reply recorded and email sent to customer successfully!'
            : 'Reply recorded successfully (email logged).';

        return back()->with('success', $msg);
    }

    /**
     * Delete an inquiry.
     */
    public function destroy(Request $request, mixed $current_team = null, mixed $inquiry = null): RedirectResponse
    {
        $inquiry = $this->resolveInquiry($inquiry, $current_team, $request);

        $id = $inquiry->id;
        $inquiryName = $inquiry->name;
        $inquiryEmail = $inquiry->email;

        $inquiry->delete();

        AuditLogger::log(
            'inquiry.deleted',
            "Deleted inquiry #{$id} from {$inquiryName} ({$inquiryEmail}).",
            $request->user(),
            ['inquiry_id' => $id, 'name' => $inquiryName, 'email' => $inquiryEmail],
            $request,
            'audit',
            'warning'
        );

        return back()->with('success', 'Inquiry deleted successfully.');
    }

    /**
     * Export inquiries as CSV.
     */
    public function exportCsv(Request $request, mixed $current_team = null): StreamedResponse
    {
        $team = $this->getTeam($request, $current_team);
        abort_if(! $team, 403, 'No active team selected.');

        $query = Inquiry::where('team_id', $team->id)->with(['assignedTo', 'repliedBy']);

        if ($request->filled('status') && $request->input('status') !== 'all') {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('priority') && $request->input('priority') !== 'all') {
            $query->where('priority', $request->input('priority'));
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->input('date_to'));
        }

        $inquiries = $query->orderByDesc('created_at')->get();

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="inquiries_'.now()->format('Ymd_His').'.csv"',
        ];

        return response()->stream(function () use ($inquiries) {
            $handle = fopen('php://output', 'w');
            Csv::put($handle, [
                'ID',
                'Date Received',
                'Customer Name',
                'Email',
                'Phone',
                'Subject',
                'Message',
                'Status',
                'Priority',
                'Assigned To',
                'Replied At',
                'Replied By',
                'Reply Message',
                'Internal Notes',
            ]);

            foreach ($inquiries as $inquiry) {
                Csv::put($handle, [
                    $inquiry->id,
                    $inquiry->created_at?->format('Y-m-d H:i:s'),
                    $inquiry->name,
                    $inquiry->email,
                    $inquiry->phone ?? '',
                    $inquiry->subject ?? '',
                    $inquiry->message,
                    strtoupper($inquiry->status),
                    strtoupper($inquiry->priority),
                    $inquiry->assignedTo?->name ?? 'Unassigned',
                    $inquiry->replied_at ? $inquiry->replied_at->format('Y-m-d H:i:s') : '',
                    $inquiry->repliedBy?->name ?? '',
                    $inquiry->reply_message ?? '',
                    $inquiry->admin_notes ?? '',
                ]);
            }

            fclose($handle);
        }, 200, $headers);
    }

    /**
     * Resolve inquiry from route parameters.
     */
    protected function resolveInquiry(mixed $inquiry, mixed $fallback = null, ?Request $request = null): Inquiry
    {
        if ($inquiry instanceof Inquiry) {
            return $inquiry;
        }

        $candidates = array_filter([
            $inquiry,
            $fallback,
            $request?->route('inquiry'),
        ], fn ($val) => $val !== null && $val !== '');

        foreach ($candidates as $cand) {
            if ($cand instanceof Inquiry) {
                return $cand;
            }
            if (is_numeric($cand)) {
                $found = Inquiry::find((int) $cand);
                if ($found) {
                    return $found;
                }
            }
        }

        abort(404, 'Inquiry record not found.');
    }

    /**
     * Resolve team from request.
     */
    protected function getTeam(Request $request, mixed $teamParam = null): ?Team
    {
        if ($teamParam instanceof Team) {
            return $teamParam;
        }

        if (is_string($teamParam) && ! empty($teamParam)) {
            $found = Team::where('slug', $teamParam)->first();
            if ($found) {
                return $found;
            }
        }

        $team = $request->route('current_team') ?? $request->route('team') ?? $teamParam;
        if (is_string($team)) {
            $found = Team::where('slug', $team)->first();
            if ($found) {
                return $found;
            }
        }
        if ($team instanceof Team) {
            return $team;
        }

        return $request->user()?->currentTeam ?: Team::first();
    }
}
