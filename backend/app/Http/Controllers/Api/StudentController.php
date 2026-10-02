<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Lesson;
use App\Models\Notification;
use App\Models\Student;
use App\Models\StudentLedgerEntry;
use App\Models\Subscription;
use App\Models\Teacher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StudentController extends Controller
{
    public function index(Request $request)
    {
        $query = Student::query()
            ->with(['branch', 'user', 'parents', 'phones'])
            ->withCount(['lessons'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('branch_id'), fn ($q) => $q->where('branch_id', $request->string('branch_id')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $search = $request->string('search');
                $q->where(function ($sq) use ($search) {
                    $sq->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('student_code', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            });

        // رقم الصفحة والحجم
        $perPage = $request->integer('per_page') ?: 100;
        $students = $query->orderBy('created_at', 'desc')->paginate($perPage);

        // الإحصائيات الحقيقية من الداتابيز (مش من الصفحة الحالية)
        // بنحسبها من نفس الفلاتر عشان تتطابق مع الجدول
        $countsQuery = Student::query()
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('branch_id'), fn ($q) => $q->where('branch_id', $request->string('branch_id')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $search = $request->string('search');
                $q->where(function ($sq) use ($search) {
                    $sq->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('student_code', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        // نضيف counts للرد النهائي (الـ paginator بيتحويل لـ array عند التحويل لـ JSON)
        $response = $students->toArray();
        $response['counts'] = [
            'active' => (int) ($countsQuery['active'] ?? 0),
            'paused' => (int) ($countsQuery['paused'] ?? 0),
            'inactive' => (int) ($countsQuery['inactive'] ?? 0),
            'lead' => (int) ($countsQuery['lead'] ?? 0),
            'graduated' => (int) ($countsQuery['graduated'] ?? 0),
            'archived' => (int) ($countsQuery['archived'] ?? 0),
        ];

        // Add computed attributes using loaded relationships (no additional queries)
        $students->getCollection()->transform(function ($student) {
            // lessons_count comes from withCount
            $student->lessons_count = $student->lessons_count ?? 0;

            // next_lesson_date - skip for now to avoid subquery timeout
            $student->next_lesson_date = null;

            // Get primary parent from loaded parents relationship
            $primaryParent = $student->parents
                ->where('pivot.is_primary', true)
                ->first() ?? $student->parents->first();

            $student->parent_name = $primaryParent?->name ?? null;
            $student->parent_phone = $primaryParent?->phone ?? null;

            // Phones are already loaded via relationship
            $student->primary_phone = $student->phones
                ->where('is_primary', true)
                ->first() ?? $student->phones->first();

            return $student;
        });

        return response()->json($response);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'branch_id' => 'nullable|exists:branches,id',
            'first_name' => 'required|string|max:100',
            'middle_name' => 'nullable|string|max:100',
            'last_name' => 'required|string|max:100',
            'date_of_birth' => 'nullable|date',
            'gender' => 'nullable|in:male,female',
            'country_code' => 'nullable|string|max:5',
            'timezone' => 'nullable|string',
            'phone' => 'nullable|string',
            'email' => 'nullable|email',
            'status' => 'nullable|in:lead,active,paused,inactive,graduated,archived',
            'notes' => 'nullable|string',
            'phones' => 'nullable|array',
            'phones.*.phone_number' => 'required|string',
            'phones.*.country_code' => 'nullable|string',
            'phones.*.is_personal' => 'nullable|boolean',
            'phones.*.is_parent' => 'nullable|boolean',
            'phones.*.is_whatsapp' => 'nullable|boolean',
            'phones.*.is_call' => 'nullable|boolean',
            'phones.*.is_primary' => 'nullable|boolean',
            'phones.*.parent_name' => 'nullable|string',
            'phones.*.parent_relationship' => 'nullable|string',
        ]);

        $data['organization_id'] = $request->user()->organization_id;
        $data['student_code'] = 'STU-' . strtoupper(uniqid());

        $student = Student::create($data);

        // Save phone numbers if provided
        if (!empty($data['phones'])) {
            foreach ($data['phones'] as $index => $phoneData) {
                \App\Models\StudentPhone::create([
                    'student_id' => $student->id,
                    'phone_number' => $phoneData['phone_number'],
                    'country_code' => $phoneData['country_code'] ?? '+20',
                    'is_personal' => $phoneData['is_personal'] ?? true,
                    'is_parent' => $phoneData['is_parent'] ?? false,
                    'is_whatsapp' => $phoneData['is_whatsapp'] ?? false,
                    'is_call' => $phoneData['is_call'] ?? true,
                    'is_primary' => $phoneData['is_primary'] ?? ($index === 0),
                    'parent_name' => $phoneData['parent_name'] ?? null,
                    'parent_relationship' => $phoneData['parent_relationship'] ?? null,
                ]);
            }
        }

        return response()->json($student->load('phones'), 201);
    }

    public function show(Student $student)
    {
        return response()->json($student->load(['branch', 'user', 'parents', 'subscriptions', 'goals']));
    }

    public function update(Request $request, Student $student)
    {
        $data = $request->validate([
            'first_name' => 'sometimes|string|max:100',
            'middle_name' => 'nullable|string|max:100',
            'last_name' => 'sometimes|string|max:100',
            'date_of_birth' => 'nullable|date',
            'gender' => 'nullable|in:male,female',
            'country_code' => 'nullable|string|max:5',
            'timezone' => 'nullable|string',
            'phone' => 'nullable|string',
            'email' => 'nullable|email',
            'status' => 'sometimes|in:lead,active,paused,inactive,graduated,archived',
            'notes' => 'nullable|string',
        ]);

        $student->update($data);
        return response()->json($student->fresh());
    }

    public function destroy(Student $student)
    {
        $student->delete();
        return response()->json(['message' => 'تم حذف الطالب']);
    }

    public function schedule(Request $request, Student $student)
    {
        return response()->json($student->lessons()->with(['teacher', 'program', 'level'])->orderBy('scheduled_start_at', 'desc')->paginate(50));
    }

    public function attendance(Request $request, Student $student)
    {
        return response()->json($student->lessons()->with('attendance')->whereHas('attendance')->orderBy('scheduled_start_at', 'desc')->paginate(50));
    }

    public function progress(Request $request, Student $student)
    {
        return response()->json($student->lessons()->with('progressRecords')->whereHas('progressRecords')->orderBy('scheduled_start_at', 'desc')->paginate(50));
    }

    public function payments(Request $request, Student $student)
    {
        return response()->json($student->payments()->with(['invoice', 'receivedBy'])->orderBy('paid_at', 'desc')->paginate(50));
    }

    /**
     * تغيير المدرس لمجموعة طلاب مرة واحدة.
     *
     * بيغيّر teacher_id على الاشتراكات النشطة + الحصص المجدولة اللي بعد النهاردة.
     * الحصص السابقة (المكتملة/الملغاة) مش بتتغيّر عشان التقارير تبقى صحيحة.
     */
    public function bulkChangeTeacher(Request $request)
    {
        $data = $request->validate([
            'student_ids' => 'required|array|min:1|max:500',
            'student_ids.*' => 'exists:students,id',
            'teacher_id' => 'required|exists:teachers,id',
            'include_upcoming_lessons' => 'nullable|boolean',
        ]);

        $teacher = Teacher::findOrFail($data['teacher_id']);
        $ids = array_values(array_unique($data['student_ids']));
        $includeLessons = $request->boolean('include_upcoming_lessons', true);

        $subs = Subscription::whereIn('student_id', $ids)
            ->where('status', 'active')
            ->update(['teacher_id' => $teacher->id, 'updated_at' => now()]);

        $lessons = 0;
        if ($includeLessons) {
            $lessons = Lesson::whereIn('student_id', $ids)
                ->whereIn('status', ['scheduled', 'rescheduled'])
                ->where('scheduled_start_at', '>=', now())
                ->update(['teacher_id' => $teacher->id, 'updated_at' => now()]);
        }

        return response()->json([
            'message' => "تم تغيير المدرس إلى {$teacher->full_name}",
            'students' => count($ids),
            'subscriptions_updated' => $subs,
            'lessons_updated' => $lessons,
            'teacher' => ['id' => $teacher->id, 'full_name' => $teacher->full_name],
        ]);
    }

    /**
     * إنشاء فاتورة لكل طالب من اشتراكه النشط (دفعة واحدة).
     * المبلغ بيتحسب من سعر الاشتراك — أو مبلغ موحّد لو اتبعت.
     */
    public function bulkCreateInvoices(Request $request)
    {
        $data = $request->validate([
            'student_ids' => 'required|array|min:1|max:500',
            'student_ids.*' => 'exists:students,id',
            'issue_date' => 'nullable|date',
            'due_date' => 'nullable|date|after_or_equal:issue_date',
            'amount' => 'nullable|numeric|min:0',
            'description' => 'nullable|string|max:255',
        ]);

        $user = $request->user();
        $issueDate = $data['issue_date'] ?? now()->toDateString();
        $dueDate = $data['due_date'] ?? now()->addDays(14)->toDateString();
        $ids = array_values(array_unique($data['student_ids']));

        $subs = Subscription::with('student')
            ->whereIn('student_id', $ids)
            ->where('status', 'active')
            ->orderBy('start_date')
            ->get()
            ->groupBy('student_id')
            ->map(fn ($group) => $group->first());

        $created = [];
        $skipped = [];

        foreach ($ids as $studentId) {
            $sub = $subs->get($studentId);

            if (!$sub) {
                $skipped[] = ['student_id' => $studentId, 'reason' => 'مفيش اشتراك نشط'];
                continue;
            }

            $amount = isset($data['amount']) && $data['amount'] > 0
                ? (float) $data['amount']
                : (float) $sub->price;

            $invoice = DB::transaction(function () use ($sub, $amount, $issueDate, $dueDate, $user, $data) {
                $invoice = Invoice::create([
                    'organization_id' => $user->organization_id,
                    'student_id' => $sub->student_id,
                    'subscription_id' => $sub->id,
                    'invoice_number' => 'INV-' . strtoupper(uniqid()),
                    'currency' => $sub->currency ?? 'EGP',
                    'issue_date' => $issueDate,
                    'due_date' => $dueDate,
                    'subtotal' => $amount,
                    'total' => $amount,
                    'paid_amount' => 0,
                    'balance_due' => $amount,
                    'status' => 'issued',
                    'notes' => $data['description'] ?? null,
                ]);

                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'description' => $data['description'] ?? "اشتراك {$sub->plan?->name}",
                    'quantity' => 1,
                    'unit_price' => $amount,
                    'total' => $amount,
                ]);

                StudentLedgerEntry::create([
                    'student_id' => $sub->student_id,
                    'invoice_id' => $invoice->id,
                    'type' => 'invoice',
                    'debit' => $amount,
                    'balance_after' => $amount,
                    'currency' => $invoice->currency,
                    'description' => 'فاتورة ' . $invoice->invoice_number,
                    'reference_type' => 'invoice',
                    'reference_id' => $invoice->id,
                    'created_by' => $user->id,
                ]);

                return $invoice;
            });

            $created[] = [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'student_id' => $sub->student_id,
                'total' => (float) $invoice->total,
            ];
        }

        return response()->json([
            'message' => 'تم إنشاء ' . count($created) . ' فاتورة',
            'created_count' => count($created),
            'skipped_count' => count($skipped),
            'total_amount' => array_sum(array_column($created, 'total')),
            'invoices' => $created,
            'skipped' => $skipped,
        ]);
    }

    /**
     * إرسال إشعار جماعي لمجموعة طلاب.
     * بيتسجل كـ announcement في جدول الإشعارات (أو في كاش في الإرسال الفعلي).
     */
    public function bulkNotify(Request $request)
    {
        $data = $request->validate([
            'student_ids' => 'required|array|min:1|max:500',
            'student_ids.*' => 'exists:students,id',
            'title' => 'nullable|string|max:255',
            'message' => 'required|string|max:2000',
            'channel' => 'nullable|in:in_app,whatsapp,sms,email',
        ]);

        $channel = $data['channel'] ?? 'in_app';
        $title = $data['title'] ?? 'إشعار جديد';
        $ids = array_values(array_unique($data['student_ids']));

        $students = Student::whereIn('id', $ids)
            ->with('phones')
            ->get(['id', 'user_id', 'full_name']);

        $now = now();
        $rows = [];

        foreach ($students as $student) {
            $contact = $student->phones->first(fn ($p) => $p->is_parent) ?? $student->phones->first();

            $rows[] = [
                'user_id' => $student->user_id,
                'student_id' => $student->id,
                'event_type' => 'announcement',
                'channel' => $channel,
                'payload' => json_encode([
                    'title' => $title,
                    'message' => $data['message'],
                    'student_id' => $student->id,
                    'student_name' => $student->full_name,
                    'contact_phone' => $contact?->phone_number,
                    'sent_by' => $request->user()->id,
                    'sent_by_name' => $request->user()->name,
                ], JSON_UNESCAPED_UNICODE),
                'sent_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        // chunks عشان ما نعملش insert ضخم على SQLite
        foreach (array_chunk($rows, 100) as $chunk) {
            Notification::insert($chunk);
        }

        return response()->json([
            'message' => 'تم إرسال الإشعار لـ ' . count($rows) . ' طالب',
            'sent_count' => count($rows),
            'requested_count' => count($ids),
            'channel' => $channel,
        ]);
    }
}
