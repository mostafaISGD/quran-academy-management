<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\SettingVersion;
use App\Services\Settings as SettingsService;
use App\Support\SettingsRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ⭐⭐⭐ **PR 3.12 — مركز الإعدادات (الخلفية).**
 *
 * ⭐ **قبل PR ده:**
 *
 * - `store` كان بيكتب `value` كنص json (أي `7` بتتقرأ نص)، والتحقق
 *   من النوع **مفيش** (أي json بيتقبّل).
 * - `index` كان يقرأ من **كل** المؤسسات (القاموس يتكرر بيعرض
 *   قيم مؤسسة تانية).
 * - `destroy` كان يمسح صف **كل** المؤسسات للمفتاح — مؤسسة
 *   تانية تفققد إعدادتِها!
 * - مفيش سجل (قديم/جديد/فَاعِل) ولا نسخ للرجوع.
 *
 * ⭐ دلوقتي السجل (`SettingsRegistry`) هو مصدر الحقيقة:
 * المفاتيح المعرّفة بس، مع تحقق النوع/الحدود، وسجل نسخ
 * (قديم/جديد/فَاعِل)، والنطاق المؤسسي في كل العمليات، و
 * `rollback` للنسخة السابقة.
 */
class SettingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $orgId = (int) $request->user()->organization_id;

        // ⭐ القراءة بمفتاح مفرد — قيمتها المؤسسية (أو الافتراضي).
        if ($request->filled('key')) {
            $key = (string) $request->string('key');
            $definition = SettingsRegistry::definition($key);

            if ($definition === null) {
                return response()->json(['message' => 'مفتاح الإعداد غير معرَف'], 404);
            }

            return response()->json([
                'key' => $key,
                'value' => settings()->get($key, $orgId),
                'meta' => $definition,
            ]);
        }

        /**
         * ⭐ القائمة كاملة من **السجل** مش من الجدول — المفاتيح
         * المعرّفة بس، وقيمة كل مفتاح من المؤسسة (أو الافتراضية)،
         * فما تنعدش قيم مؤسسة تانية.
         */
        $rows = Setting::query()
            ->where('organization_id', $orgId)
            ->pluck('value', 'key');

        $service = app(SettingsService::class);
        $settings = [];

        foreach (SettingsRegistry::KEYS as $key => $definition) {
            $raw = $rows->get($key);

            $settings[$key] = [
                'value' => $raw === null
                    ? $definition['default'] ?? null
                    : $service->castValue($definition['type'], $raw),
                'type' => $definition['type'],
                'group' => $definition['group'],
                'label' => $definition['label'],
                'default' => $definition['default'] ?? null,
                'min' => $definition['min'] ?? null,
                'max' => $definition['max'] ?? null,
            ];
        }

        return response()->json($settings);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'key' => 'required|string|max:100',
            'value' => 'present',
        ]);

        $organizationId = (int) $request->user()->organization_id;

        /**
         * ⭐ **التحقق من السجل** — النوع (`int`/`string`/`bool`/
         * `list`) والحدود (`min`/`max`). ورفض مفتاح غير معرّف
         * بمنع مفاتيح مفككة: السجل بيلك التسمية الموحدة
         * والقيمة الافتراضية الواحدة.
         */
        $result = SettingsRegistry::validate($data['key'], $data['value']);

        if (isset($result['error'])) {
            return response()->json([
                'message' => $result['error'],
                'errors' => ['value' => [$result['error']]],
            ], 422);
        }

        Setting::set(
            $data['key'],
            $result['value'] ?? $data['value'],
            $organizationId,
            $request->user()->id,
        );

        return response()->json([
            'message' => 'تم حفظ الإعداد',
            'key' => $data['key'],
        ], 201);
    }

    public function destroy(string $key, Request $request): JsonResponse
    {
        $organizationId = (int) $request->user()->organization_id;

        /**
         * ⭐ الحذف **أدبيًا** — صف مؤسسة واحدة بس، ويُسجِّل version
         * (قديم → null). قبله كان يمسح أكاديميات تانية!
         */
        $deleted = Setting::forget($key, $organizationId, $request->user()->id);

        if (! $deleted) {
            return response()->json([
                'message' => 'مفيش صف إعداد للمؤسسة دي بالاسم ده',
            ], 404);
        }

        return response()->json(['message' => 'تم حذف الإعداد']);
    }

    /**
     * ⭐ **الرجوع للقيمة السابقة** (سجل النسخ).
     *
     * ⭐ **اللوجيك**: بندوّر على **النسخة اللي أنتجت القيمة
     * الحالية** (`new_value` منسوخ كنص متساوي مع الخام الحالي)،
     * ونرجّع `old_value` منها (قد يكون null = الافتراضي).
     *
     * ⭐ الرجوع **ما بتسجلش نسخة** — السلسلة تبقى سلسلة الحقيقة
     * الأصلية، والتكرار كذا مرة شاكِر: بعد رجوعين ما ضاعش
     * النسخة الأصلية.
     */
    public function rollback(string $key, Request $request): JsonResponse
    {
        $organizationId = (int) $request->user()->organization_id;

        if (SettingsRegistry::definition($key) === null) {
            return response()->json(['message' => 'مفتاح الإعداد غير معرَف'], 404);
        }

        $currentRow = Setting::query()
            ->where('key', $key)
            ->where('organization_id', $organizationId)
            ->first();

        // ⭐ مفيش صف = نلغ بالقيمة الافتراضية — مفيش نسخة يرجّعها.
        if ($currentRow === null) {
            return response()->json([
                'message' => 'الإعداد حاليًا على قيمته الافتراضية — مفيش نسخة يرجّعها',
            ], 422);
        }

        $currentRaw = (string) $currentRow->getRawOriginal('value');

        /** ⭐ أقرب نسخة إنتاجت القيمة الحالية (من الأدنى للأعلى). */
        $produced = SettingVersion::query()
            ->where('key', $key)
            ->where('organization_id', $organizationId)
            ->where('new_value', $currentRaw)
            ->orderByDesc('id')
            ->first();

        if ($produced === null) {
            return response()->json([
                'message' => 'القيمة الحالية ما اخذتش نسخة مسجلة — مفيش نسخة يرجّعها',
            ], 422);
        }

        Setting::set(
            $key,
            $produced->old_value,
            $organizationId,
            $request->user()->id,
            false, // ⭐ بلا نسخة إضافية — التراجع يقرا النسخة الأصلية نفسها.
        );

        return response()->json([
            'key' => $key,
            'value' => settings()->get($key, $organizationId),
        ]);
    }
}
