<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * ⭐⭐⭐ **PR 4.3 — تفضيلات الإشعارات الذاتية.**
 *
 * المستخدم يوقف/يشغّل القنوات الخارجية بنفسه، بلا صلاحية إدارية:
 * القرار قراره، والحماية بالملكية (`user_id` من التوكن نفسه).
 *
 * ⭐ القاعدة محفوظة من PR 4.1: **غياب الصف = القناة مفعّلة**.
 * هذا الـcontroller يكتب صفوفاً صريحة فقط عند تغيير الحالة.
 *
 * ⭐ `database` ليست ضمن القائمة: الصندوق الوارد ليس قناة
 * «اختيارية» — إشعارات حسابك تُقرأ دائماً.
 */
class NotificationPreferenceController extends Controller
{
    /** قنوات الخدمة الذاتية — التسمية العربية للعرض. */
    private const CHANNELS = [
        'email' => 'البريد الإلكتروني',
        'whatsapp' => 'واتساب',
        'sms' => 'رسائل SMS',
    ];

    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'channels' => $this->channelsFor($request->user()),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'preferences' => ['required', 'array', 'min:1'],
            'preferences.*.channel' => [
                'required',
                'string',
                Rule::in(array_keys(self::CHANNELS)),
            ],
            'preferences.*.is_enabled' => ['required', 'boolean'],
        ]);

        $user = $request->user();

        DB::transaction(function () use ($data, $user): void {
            foreach ($data['preferences'] as $preference) {
                NotificationPreference::updateOrCreate(
                    [
                        'user_id' => $user->id,
                        'channel' => $preference['channel'],
                    ],
                    [
                        'organization_id' => $user->organization_id,
                        'is_enabled' => (bool) $preference['is_enabled'],
                    ],
                );
            }
        });

        return response()->json([
            'channels' => $this->channelsFor($user),
        ]);
    }

    /**
     * @return list<array{channel: string, label: string, is_enabled: bool, available: bool, note: string|null}>
     */
    private function channelsFor(User $user): array
    {
        $saved = NotificationPreference::query()
            ->where('user_id', $user->id)
            ->get()
            ->keyBy('channel');

        return array_map(
            function (string $label, string $channel) use ($saved): array {
                $available = $this->isAvailable($channel);

                return [
                    'channel' => $channel,
                    'label' => $label,
                    'is_enabled' => $saved->has($channel)
                        ? (bool) $saved->get($channel)->is_enabled
                        : true,
                    'available' => $available,
                    'note' => $available
                        ? null
                        : 'القناة غير مفعّلة من إدارة الأكاديمية بعد — سيُحفظ تفضيلك ويُطبَّق عند تفعيلها.',
                ];
            },
            array_values(self::CHANNELS),
            array_keys(self::CHANNELS),
        );
    }

    /**
     * هل القناة قابلة للإرسال فعلياً الآن؟ (المزود موجود/العلم مفتوح)
     */
    private function isAvailable(string $channel): bool
    {
        if ($channel === 'whatsapp') {
            return (bool) config('notifications.whatsapp_enabled');
        }

        return ! empty(config("notifications.providers.{$channel}"));
    }
}
