<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TeacherEarning;
use Illuminate\Http\Request;

class TeacherEarningController extends Controller
{
    public function index()
    {
        return response()->json(['data' => TeacherEarning::with('teacher')->get()]);
    }
}
