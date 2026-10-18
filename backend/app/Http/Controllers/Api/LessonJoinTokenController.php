<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lesson;
use App\Models\Student;
use App\Services\LessonJoinTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class LessonJoinTokenController extends Controller
{
    public function issue(Request $request, Lesson $lesson): JsonResponse
    {
        Gate::authorize('update', $lesson);

        $data = $request->validate([
            'student_id' => 'required|exists:students,id',
        ]);

        $student = Student::findOrFail($data['student_id']);

        $issued = app(LessonJoinTokenService::class)->issue($lesson, $student, $request->user());

        return response()->json(['data' => $issued], 201);
    }

    public function redeem(Request $request): JsonResponse
    {
        $data = $request->validate([
            'pin_or_token' => 'required|string',
            'lesson_id' => 'required|exists:lessons,id',
        ]);

        $result = app(LessonJoinTokenService::class)->redeem(
            $data['pin_or_token'],
            (int) $data['lesson_id'],
        );

        if (! $result['ok']) {
            return response()->json(['message' => $result['reason']], 422);
        }

        return response()->json(['data' => $result]);
    }
}
