<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ParentalConsent;
use App\Models\Student;
use App\Services\StudentAccountEligibility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class StudentConsentController extends Controller
{
    public function grant(Request $request, Student $student): JsonResponse
    {
        Gate::authorize('update', $student);

        $data = $request->validate([
            'parent_id' => 'nullable|exists:parents,id',
            'scope' => 'sometimes|in:account_activation,view_progress,payments',
            'notes' => 'nullable|string|max:500',
        ]);

        $eligibility = app(StudentAccountEligibility::class);

        if (! $eligibility->hasActiveConsent($student, $data['scope'] ?? ParentalConsent::SCOPE_ACCOUNT)) {
            $consent = $eligibility->grant(
                $student,
                $data['parent_id'] ?? null,
                $request->user(),
                $data['scope'] ?? ParentalConsent::SCOPE_ACCOUNT,
                $data['notes'] ?? null,
            );

            return response()->json(['data' => $consent], 201);
        }

        return response()->json(['message' => 'الموافقة موجودة بالفعل'], 200);
    }

    public function revoke(Request $request, Student $student, ParentalConsent $consent): JsonResponse
    {
        Gate::authorize('update', $student);

        if ((int) $consent->student_id !== $student->id) {
            return response()->json(['message' => 'الموافقة لا تنتمي لهذا الطالب'], 403);
        }

        if ($consent->revoked_at !== null) {
            return response()->json(['message' => 'الموافقة مسحوبة بالفعل'], 200);
        }

        app(StudentAccountEligibility::class)->revoke($consent, $request->user());

        return response()->json(['message' => 'تم سحب الموافقة']);
    }
}
