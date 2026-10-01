<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

/**
 * المرحلة الأخيرة: الإعدادات، العملاء المحتملين (leads)، اختبارات التقييم،
 * الإشعارات، وسجل التدقيق (audit logs).
 */
class SeedActivity extends Seeder
{
    /** @var array<string, mixed> */
    private array $d;

    public function __construct()
    {
        $this->d = require database_path('data/realistic.php');
    }

    public function run(): void
    {
        mt_srand(20260707);

        $now = now();

        // ============ 1) الإعدادات ============
        $settings = [];
        foreach ($this->d['settings'] as $i => [$key, $value, $group]) {
            $settings[] = [
                'organization_id' => 1,
                'key' => $key,
                'value' => json_encode($value, JSON_UNESCAPED_UNICODE),
                'created_at' => $now->format('Y-m-d H:i:s'), 'updated_at' => $now->format('Y-m-d H:i:s'),
            ];
        }
        $this->insert('settings', $settings);

        // ============ 2) العملاء المحتملين ============
        $leads = [];
        $leadId = 0;
        $maleFirst = $this->d['male_first'];
        $femaleFirst = $this->d['female_first'];
        $lastNames = $this->d['last'];

        for ($i = 1; $i <= 35; $i++) {
            $leadId++;
            $isMale = ($i % 2) === 1;
            $name = $this->pick($isMale ? $maleFirst : $femaleFirst) . ' ' . $this->pick($lastNames);

            $statusWeights = [
                'new' => 5, 'contacted' => 5, 'qualified' => 4, 'trial_booked' => 3,
                'trial_completed' => 3, 'offer_sent' => 2, 'converted' => 3, 'lost' => 2,
            ];
            $status = array_rand($statusWeights);

            // العملاء المحولين驾驶他们的 تاريخ أقدم
            $ageDays = match ($status) {
                'converted' => mt_rand(20, 60),
                'lost' => mt_rand(10, 45),
                default => mt_rand(1, 25),
            };

            $leads[] = [
                'id' => $leadId, 'organization_id' => 1,
                'branch_id' => ($i % 6 === 0) ? 2 : 1,
                'full_name' => $name,
                'phone' => $this->phone(),
                'email' => mt_rand(0, 100) < 40 ? 'lead' . $i . '@gmail.com' : null,
                'country_code' => '+20',
                'student_age' => mt_rand(4, 16),
                'interested_program_id' => mt_rand(1, 6),
                'source' => ['facebook', 'instagram', 'website', 'referral', 'other'][mt_rand(0, 4)],
                'assigned_staff_id' => 1,
                'status' => $status,
                'notes' => null,
                'created_at' => $now->copy()->subDays($ageDays)->format('Y-m-d H:i:s'),
                'updated_at' => $now->copy()->subDays(max(0, $ageDays - mt_rand(0, 10)))->format('Y-m-d H:i:s'),
            ];
        }
        $this->insert('leads', $leads);

        // ============ 3) اختبارات التقييم ============
        $assessments = [];
        $assessmentId = 0;

        // اختبارات للعملاء المحتملين اللي وصلوا لحد التجربة
        $trialLeads = array_filter($leads, fn ($l) => in_array($l['status'], ['trial_completed', 'offer_sent', 'converted'], true));

        foreach ($trialLeads as $lead) {
            $assessmentId++;
            $scheduled = Carbon::parse($lead['created_at'])->addDays(mt_rand(2, 8));

            $hasResult = in_array($lead['status'], ['trial_completed', 'offer_sent', 'converted'], true);
            $result = $lead['status'] === 'converted' ? 'ready_to_subscribe' : 'needs_follow_up';
            if (!$hasResult && mt_rand(0, 100) < 40) {
                $result = 'not_suitable';
            }

            $assessments[] = [
                'id' => $assessmentId,
                'lead_id' => $lead['id'], 'student_id' => null,
                'teacher_id' => mt_rand(1, 15),
                'scheduled_at' => $scheduled->format('Y-m-d H:i:s'),
                'reading_score' => $hasResult ? mt_rand(2, 5) : null,
                'tajweed_score' => $hasResult ? mt_rand(2, 5) : null,
                'memorization_score' => $hasResult ? mt_rand(1, 5) : null,
                'recommended_level' => $hasResult ? 'المستوى ' . mt_rand(1, 3) : null,
                'notes' => $hasResult ? 'أداء جيد في التسميع' : null,
                'result' => $result,
                'created_at' => $lead['created_at'], 'updated_at' => $now->format('Y-m-d H:i:s'),
            ];
        }

        // اختبارات للطلاب الحاليين
        $activeStudents = DB::table('students')->where('status', 'active')->inRandomOrder()->limit(12)->get();
        foreach ($activeStudents as $student) {
            $assessmentId++;
            $scheduled = $now->copy()->subDays(mt_rand(5, 120));

            $assessments[] = [
                'id' => $assessmentId,
                'lead_id' => null, 'student_id' => $student->id,
                'teacher_id' => mt_rand(1, 15),
                'scheduled_at' => $scheduled->format('Y-m-d H:i:s'),
                'reading_score' => mt_rand(2, 5),
                'tajweed_score' => mt_rand(2, 5),
                'memorization_score' => mt_rand(2, 5),
                'recommended_level' => 'المستوى ' . mt_rand(1, 6),
                'notes' => 'تقييم دوري للطالب',
                'result' => ['ready_to_subscribe', 'needs_follow_up'][mt_rand(0, 1)],
                'created_at' => $scheduled->format('Y-m-d H:i:s'), 'updated_at' => $scheduled->format('Y-m-d H:i:s'),
            ];
        }

        $this->insert('assessments', $assessments);

        // ============ 4) الإشعارات ============
        $notifications = [];
        $users = DB::table('users')->pluck('id');
        $eventTypes = [
            'lesson_reminder', 'subscription_expiring', 'subscription_expired',
            'payment_received', 'makeup_created', 'trial_reminder',
        ];

        for ($i = 0; $i < 60; $i++) {
            $userId = $users->random();
            $eventType = $eventTypes[mt_rand(0, count($eventTypes) - 1)];
            $sentAt = $now->copy()->subHours(mt_rand(1, 720));

            $notifications[] = [
                'user_id' => $userId,
                'event_type' => $eventType,
                'channel' => ['in_app', 'email', 'whatsapp', 'sms'][mt_rand(0, 3)],
                'payload' => json_encode([
                    'title' => $this->notificationTitle($eventType),
                    'message' => 'إشعار تجريبي للاختبار',
                    'entity_type' => 'lesson',
                    'entity_id' => mt_rand(1, 500),
                ], JSON_UNESCAPED_UNICODE),
                'sent_at' => $sentAt->format('Y-m-d H:i:s'),
                'read_at' => mt_rand(0, 100) < 55 ? $sentAt->copy()->addHours(mt_rand(1, 48))->format('Y-m-d H:i:s') : null,
                'created_at' => $sentAt->format('Y-m-d H:i:s'), 'updated_at' => $sentAt->format('Y-m-d H:i:s'),
            ];
        }
        $this->insert('notifications', $notifications);

        // ============ 5) سجل التدقيق ============
        $audit = [];
        $actions = ['create', 'update', 'delete', 'login', 'logout', 'export'];
        $entities = ['student', 'teacher', 'invoice', 'payment', 'lesson', 'subscription', 'program'];

        for ($i = 0; $i < 100; $i++) {
            $entity = $entities[mt_rand(0, count($entities) - 1)];
            $action = $actions[mt_rand(0, count($actions) - 1)];
            $createdAt = $now->copy()->subDays(mt_rand(0, 60))->subHours(mt_rand(0, 23));

            $audit[] = [
                'user_id' => $users->random(),
                'action' => $action,
                'entity_type' => $entity,
                'entity_id' => mt_rand(1, 100),
                'old_value' => $action === 'update' ? json_encode(['status' => 'draft']) : null,
                'new_value' => in_array($action, ['create', 'update'], true)
                    ? json_encode(['status' => 'active'], JSON_UNESCAPED_UNICODE)
                    : null,
                'ip_address' => '192.168.1.' . mt_rand(2, 250),
                'created_at' => $createdAt->format('Y-m-d H:i:s'),
            ];
        }
        $this->insert('audit_logs', $audit);

        $this->command?->info('   → ' . count($settings) . ' إعداد');
        $this->command?->info('   → ' . count($leads) . ' عميل محتمل');
        $this->command?->info('   → ' . count($assessments) . ' اختبار تقييم');
        $this->command?->info('   → ' . count($notifications) . ' إشعار');
        $this->command?->info('   → ' . count($audit) . ' سجل تدقيق');
    }

    private function notificationTitle(string $type): string
    {
        return match ($type) {
            'lesson_reminder' => 'تذكير بحصة قادمة',
            'subscription_expiring' => 'اشتراك على وشك الانتهاء',
            'subscription_expired' => 'انتهى الاشتراك',
            'payment_received' => 'تم استلام دفعة',
            'makeup_created' => 'تم إنشاء حصة تعويضية',
            'trial_reminder' => 'تذكير بموعد التجربة',
            default => 'إشعار',
        };
    }

    private function pick(array $arr): string
    {
        return $arr[mt_rand(0, count($arr) - 1)];
    }

    private function phone(): string
    {
        $prefix = ['010', '011', '012', '015'][mt_rand(0, 3)];
        return $prefix . mt_rand(10000000, 99999999);
    }

    private function insert(string $table, array $rows): void
    {
        if (!$rows) {
            return;
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table($table)->insert($chunk);
        }
    }
}