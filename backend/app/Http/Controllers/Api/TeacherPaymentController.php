<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TeacherPayment;
use Illuminate\Http\Request;

class TeacherPaymentController extends Controller
{
    public function index()
    {
        return response()->json(['data' => TeacherPayment::with('teacher')->get()]);
    }
}
