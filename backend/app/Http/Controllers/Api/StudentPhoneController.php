<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StudentPhone;
use App\Models\Student;
use App\Services\ParentLinker;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StudentPhoneController extends Controller
{
    public function __construct(private ParentLinker $parentLinker) {}
    public function index(Request $request, $studentId)
    {
        try {
            $student = Student::findOrFail($studentId);
            $phones = $student->phones()->orderByDesc('is_primary')->orderBy('created_at')->get();
            return response()->json(['data' => $phones]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function store(Request $request, $studentId)
    {
        try {
            $student = Student::findOrFail($studentId);

            $data = $request->validate([
                'phone_number' => ['required', 'string', 'max:20'],
                'country_code' => 'nullable|string|max:5',
                'is_personal' => 'boolean',
                'is_parent' => 'boolean',
                'is_whatsapp' => 'boolean',
                'is_call' => 'boolean',
                'is_primary' => 'boolean',
                'parent_name' => 'nullable|string|required_if:is_parent,true',
                'parent_relationship' => 'nullable|string|required_if:is_parent,true',
            ]);

            $data['student_id'] = $studentId;

            // Normalize phone number
            $data['phone_number'] = $this->normalizePhoneNumber($data['phone_number'], $data['country_code'] ?? $student->country_code ?? '+20');

            // منع تكرار نفس الرقم لنفس الطالب — برسالة عربية واضحة
            $duplicate = StudentPhone::where('student_id', $studentId)
                ->where('phone_number', $data['phone_number'])
                ->exists();

            if ($duplicate) {
                return response()->json(['error' => 'هذا الرقم مسجل بالفعل لهذا الطالب'], 422);
            }

            // Handle primary phone logic
            if (!empty($data['is_primary'])) {
                StudentPhone::where('student_id', $studentId)->update(['is_primary' => false]);
            }

            $phone = StudentPhone::create($data);

            // لو الرقم ده لولي أمر → نسجّله في جدول أولياء الأمور ونربطه بالطالب
            if ($phone->is_parent && $phone->parent_name) {
                $this->parentLinker->findOrCreate(
                    $studentId,
                    $phone->phone_number,
                    $phone->parent_name,
                    $phone->parent_relationship,
                    countryCode: $student->country_code ?? '+20',
                );
            }

            // Load student relationship for response
            $phone->load('student');

            return response()->json($phone, 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['error' => $e->validator->errors()->first()], 422);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function bulkStore(Request $request, $studentId)
    {
        try {
            $student = Student::findOrFail($studentId);

            $data = $request->validate([
                'phones' => 'required|array|min:1|max:10',
                'phones.*.phone_number' => 'required|string|max:20',
                'phones.*.country_code' => 'nullable|string|max:5',
                'phones.*.is_personal' => 'boolean',
                'phones.*.is_parent' => 'boolean',
                'phones.*.is_whatsapp' => 'boolean',
                'phones.*.is_call' => 'boolean',
                'phones.*.is_primary' => 'boolean',
                'phones.*.parent_name' => 'nullable|string',
                'phones.*.parent_relationship' => 'nullable|string',
            ]);

            $created = [];
            $errors = [];

            foreach ($data['phones'] as $index => $phoneData) {
                try {
                    // Validate parent fields
                    if (!empty($phoneData['is_parent']) && (empty($phoneData['parent_name']) || empty($phoneData['parent_relationship']))) {
                        throw new \Exception("السطر {$index}: رقم ولي الأمر يتطلب الاسم والعلاقة");
                    }

                    $phoneData['phone_number'] = $this->normalizePhoneNumber(
                        $phoneData['phone_number'],
                        $phoneData['country_code'] ?? $student->country_code ?? '+20'
                    );

                    // Check duplicate
                    $exists = StudentPhone::where('student_id', $studentId)
                        ->where('phone_number', $phoneData['phone_number'])
                        ->exists();
                    if ($exists) {
                        throw new \Exception("السطر {$index}: الرقم مكرر");
                    }

                    $phoneData['student_id'] = $studentId;

                    // Handle primary - only first one if multiple marked
                    static $primarySet = false;
                    if (!empty($phoneData['is_primary']) && !$primarySet) {
                        StudentPhone::where('student_id', $studentId)->update(['is_primary' => false]);
                        $primarySet = true;
                    } else {
                        $phoneData['is_primary'] = false;
                    }

                    $phone = StudentPhone::create($phoneData);

                    // ربط ولي الأمر في جدول أولياء الأمور
                    if ($phone->is_parent && $phone->parent_name) {
                        $this->parentLinker->findOrCreate(
                            (int) $studentId,
                            $phone->phone_number,
                            $phone->parent_name,
                            $phone->parent_relationship,
                            countryCode: $student->country_code ?? '+20',
                        );
                    }

                    $created[] = $phone;
                } catch (\Exception $e) {
                    $errors[] = $e->getMessage();
                }
            }

            // Ensure at least one primary
            if ($created && !collect($created)->where('is_primary', true)->first()) {
                $created[0]->update(['is_primary' => true]);
            }

            return response()->json([
                'created' => $created,
                'errors' => $errors,
            ], 201);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function update(Request $request, $studentId, $phoneId)
    {
        try {
            $phone = StudentPhone::where('student_id', $studentId)->findOrFail($phoneId);

            $data = $request->validate([
                'phone_number' => ['sometimes', 'string', 'max:20'],
                'country_code' => 'nullable|string|max:5',
                'is_personal' => 'boolean',
                'is_parent' => 'boolean',
                'is_whatsapp' => 'boolean',
                'is_call' => 'boolean',
                'is_primary' => 'boolean',
                'parent_name' => 'nullable|string',
                'parent_relationship' => 'nullable|string',
            ]);

            // منع تكرار نفس الرقم لنفس الطالب (مع استثناء نفس السجل)
            if (isset($data['phone_number'])) {
                $normalized = $this->normalizePhoneNumber(
                    $data['phone_number'],
                    $data['country_code'] ?? $phone->student->country_code ?? '+20'
                );

                $duplicate = StudentPhone::where('student_id', $studentId)
                    ->where('phone_number', $normalized)
                    ->where('id', '!=', $phoneId)
                    ->exists();

                if ($duplicate) {
                    return response()->json(['error' => 'هذا الرقم مسجل بالفعل لهذا الطالب'], 422);
                }

                $data['phone_number'] = $normalized;
            }

            // Validate parent fields
            $isParent = $data['is_parent'] ?? $phone->is_parent;
            $parentName = $data['parent_name'] ?? $phone->parent_name;
            $parentRelationship = $data['parent_relationship'] ?? $phone->parent_relationship;

            if ($isParent && (empty($parentName) || empty($parentRelationship))) {
                return response()->json(['error' => 'رقم ولي الأمر يتطلب الاسم والعلاقة'], 422);
            }

            if (!empty($data['is_primary'])) {
                StudentPhone::where('student_id', $studentId)->where('id', '!=', $phoneId)->update(['is_primary' => false]);
            }

            $phone->update($data);

            // لو الرقم ده لولي أمر → نسجّله في جدول أولياء الأمور ونربطه بالطالب
            $phone = $phone->fresh();
            if ($phone->is_parent && $phone->parent_name) {
                $this->parentLinker->findOrCreate(
                    (int) $studentId,
                    $phone->phone_number,
                    $phone->parent_name,
                    $phone->parent_relationship,
                    countryCode: $phone->student?->country_code ?? '+20',
                );
            }

            return response()->json($phone);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['error' => $e->validator->errors()->first()], 422);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function destroy($studentId, $phoneId)
    {
        try {
            $phone = StudentPhone::where('student_id', $studentId)->findOrFail($phoneId);
            $wasPrimary = $phone->is_primary;

            // لو ده رقم لولي أمر → نفكّ الربط (ومش دي كانت آخر علاقة، الوي اتشال)
            $this->parentLinker->cleanupForPhone($phone->load('student'));

            $phone->delete();

            // If deleted phone was primary, set next one as primary
            if ($wasPrimary) {
                $remaining = StudentPhone::where('student_id', $studentId)->first();
                if ($remaining) {
                    $remaining->update(['is_primary' => true]);
                }
            }

            return response()->json(['message' => 'تم حذف الرقم']);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function setPrimary($studentId, $phoneId)
    {
        try {
            StudentPhone::where('student_id', $studentId)->update(['is_primary' => false]);
            StudentPhone::where('id', $phoneId)->update(['is_primary' => true]);
            return response()->json(['message' => 'تم تحديث الرقم الأساسي']);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function toggleField(Request $request, $studentId, $phoneId)
    {
        try {
            $phone = StudentPhone::where('student_id', $studentId)->findOrFail($phoneId);

            $field = $request->string('field');
            $allowedFields = ['is_personal', 'is_parent', 'is_whatsapp', 'is_call'];

            if (!in_array($field, $allowedFields)) {
                return response()->json(['error' => 'حقل غير مسموح'], 422);
            }

            $phone->update([$field => !$phone->$field]);
            return response()->json($phone->fresh());
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /** أولياء أمر الطالب مع العلاقات */
    public function listParents(Request $request, $studentId)
    {
        try {
            $student = Student::findOrFail($studentId);
            return response()->json(['data' => $this->parentLinker->forStudent((int) $studentId)]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /** تغيير ولي الأمر الأساسي للطالب */
    public function setPrimaryParent(Request $request, $studentId, $parentId)
    {
        try {
            $exists = \Illuminate\Support\Facades\DB::table('student_parents')
                ->where('student_id', $studentId)
                ->where('parent_id', $parentId)
                ->exists();

            if (!$exists) {
                return response()->json(['error' => 'الولي الأمر ده مش مرتبط بالطالب'], 422);
            }

            \Illuminate\Support\Facades\DB::table('student_parents')
                ->where('student_id', $studentId)
                ->update(['is_primary' => false]);

            \Illuminate\Support\Facades\DB::table('student_parents')
                ->where('student_id', $studentId)
                ->where('parent_id', $parentId)
                ->update(['is_primary' => true, 'updated_at' => now()]);

            $parents = $this->parentLinker->forStudent((int) $studentId);

            return response()->json(['data' => $parents, 'message' => 'تم تغيير ولي الأمر الأساسي']);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /** الإكمال التلقائي: بحث في أولياء الأمر بالرقم أو الاسم */
    public function searchParents(Request $request)
    {
        $query = trim((string) $request->string('q'));

        if (strlen($query) < 2) {
            return response()->json(['data' => []]);
        }

        return response()->json(['data' => $this->parentLinker->search($query)]);
    }

    // Search phones across all students
    public function search(Request $request)
    {
        try {
            $query = $request->string('q');
            if (strlen($query) < 3) {
                return response()->json(['data' => []]);
            }

            $phones = StudentPhone::where('phone_number', 'like', "%{$query}%")
                ->with('student:id,first_name,last_name,student_code')
                ->limit(20)
                ->get();

            return response()->json(['data' => $phones]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    // Get phones by parent phone number
    public function searchByParentPhone(Request $request)
    {
        try {
            $query = $request->string('q');
            if (strlen($query) < 3) {
                return response()->json(['data' => []]);
            }

            $phones = StudentPhone::where('is_parent', true)
                ->where('phone_number', 'like', "%{$query}%")
                ->with('student:id,first_name,last_name,student_code')
                ->limit(20)
                ->get();

            return response()->json(['data' => $phones]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    private function normalizePhoneNumber(string $phoneNumber, string $countryCode = '+20'): string
    {
        // Remove all non-numeric characters
        $number = preg_replace('/[^0-9]/', '', $phoneNumber);

        // Remove country code if present
        $countryDigits = ltrim($countryCode, '+');
        if (str_starts_with($number, $countryDigits)) {
            $number = substr($number, strlen($countryDigits));
        }

        // Remove leading zero
        if (str_starts_with($number, '0')) {
            $number = substr($number, 1);
        }

        // Prepend country code
        return $countryDigits . $number;
    }
}