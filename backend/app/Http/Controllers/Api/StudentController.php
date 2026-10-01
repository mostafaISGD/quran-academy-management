<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Student;
use Illuminate\Http\Request;

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
}
