<?php

use App\Http\Controllers\Web\JoinLinkController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/**
 * ⭐⭐⭐ **PR 3.11 — رابط الدخول الوسيط `/join/{token}`.**
 *
 * عمومی عام (token يمثل الهوية) — الأمان قيود الرمز نفسه:
 * تاريخ انتهاء + رمز من PR 1.12 + القيد الفريد على الدخول.
 * السجل يكتب ويحوّل للرابط الحقيقي (302) — قبول: الرمز المنتهي = 410.
 */
Route::get('/join/{token}', [JoinLinkController::class, 'go']);

