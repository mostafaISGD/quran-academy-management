<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ParentModel;
use Illuminate\Http\Request;

class ParentController extends Controller
{
    public function index(Request $request)
    {
        try {
            $query = ParentModel::query()
                ->with(['students' => function ($q) {
                    $q->select('students.id', 'students.first_name', 'students.middle_name', 'students.last_name', 'students.student_code');
                }])
                ->withCount('students')
                ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
                ->when($request->filled('search'), fn ($q) => $this->applySearch($q, (string) $request->string('search')))
                ->orderBy('name');

            $countsQuery = ParentModel::query()
                ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
                ->when($request->filled('search'), fn ($q) => $this->applySearch($q, (string) $request->string('search')));

            return $this->paginatedWithCounts($query, $countsQuery, $request, ['status']);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * بحث ذكي: الاسم/البريد زي ما هو، والرقم بيتجرّب بكل صيغه
     * (مع أو بدون كود الدولة) عشان المستخدم يقدر يكتبه بأي شكل.
     */
    private function applySearch($query, string $raw): void
    {
        $raw = trim($raw);
        if ($raw === '') {
            return;
        }

        $digits = preg_replace('/[^0-9]/', '', $raw);
        $variants = [$raw];

        if ($digits !== '') {
            $variants[] = $digits;
            // لو المستخدم كتب الرقم بكود الدولة، جرّب من غيره والعكس
            foreach (['20', '966', '971', '965', '974', '962', '212', '218'] as $cc) {
                if (str_starts_with($digits, $cc) && strlen($digits) > 7) {
                    $variants[] = substr($digits, strlen($cc));
                }
            }
            // لو كتب الرقم بحرف 0 في الأول (0xxxxxxxxx)
            if (strlen($digits) === 10 && str_starts_with($digits, '0')) {
                foreach (['20'] as $cc) {
                    $variants[] = $cc . substr($digits, 1);
                }
            }
        }

        $variants = array_values(array_unique(array_filter($variants)));

        $query->where(function ($sq) use ($variants) {
            foreach ($variants as $v) {
                $sq->orWhere('name', 'like', "%{$v}%")
                    ->orWhere('phone', 'like', "%{$v}%")
                    ->orWhere('email', 'like', "%{$v}%");
            }
        });
    }

    public function store(Request $request)
    {
        try {
            $data = $request->validate([
                'name' => 'required|string',
                'phone' => 'required|string',
                'email' => 'nullable|email',
                'country_code' => 'nullable|string',
                'relationship' => 'nullable|string',
                'student_id' => 'nullable|exists:students,id',
            ]);

            // relationship و student_id للربط فقط — مش أعمدة في parents
            $relationship = $data['relationship'] ?? null;
            $studentId = $data['student_id'] ?? null;
            unset($data['relationship'], $data['student_id']);

            $data['organization_id'] = $request->user()->organization_id;
            $data['status'] = 'active';

            $parent = ParentModel::create($data);

            if ($studentId) {
                $isFirst = !\Illuminate\Support\Facades\DB::table('student_parents')
                    ->where('student_id', $studentId)
                    ->exists();

                \Illuminate\Support\Facades\DB::table('student_parents')->insert([
                    'student_id' => $studentId,
                    'parent_id' => $parent->id,
                    'relationship' => $relationship,
                    'is_primary' => $isFirst,
                    'can_manage' => true,
                    'can_pay' => true,
                    'can_receive_notifications' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return response()->json($parent->fresh(), 201);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
