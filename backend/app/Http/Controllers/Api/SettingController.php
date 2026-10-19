<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    public function index(Request $request)
    {
        if ($request->filled('key')) {
            $value = Setting::get($request->string('key'));
            return response()->json(['key' => $request->string('key'), 'value' => $value]);
        }
        $settings = Setting::all()->mapWithKeys(fn ($s) => [$s->key => $s->value]);
        return response()->json($settings);
    }

    public function store(Request $request)
    {
        $data = $request->validate(['key' => 'required|string|max:100', 'value' => 'nullable|json']);
        $data['organization_id'] = $request->user()->organization_id;
        /**
         * ⭐ PR 2.8 — إصلاح الـbug: قبلها الـorg كان بيتحسب في
         * `$data` وبعدين **مهمل** في النداء → كتابة بـorganization_id
         * فاضية (constraint violation ع الموصل).
         */
        Setting::set($data['key'], $data['value'] ?? null, $data['organization_id']);
        return response()->json(['message' => 'تم حفظ الإعداد', 'key' => $data['key']], 201);
    }

    public function destroy(string $key)
    {
        Setting::where('key', $key)->delete();
        return response()->json(['message' => 'تم حذف الإعداد']);
    }
}
