<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ المجموعات الأونلاين + قائمة الانتظار.
 *
 * ─────────────────────────────────────────────────────────────
 * ليه «مجموعة» كيان جديد ومش «مستوى»؟
 *
 * المستوى موجود فعلاً جوّه البرنامج، بس المستوى مش مجموعة:
 *  - نفس المستوى ممكن ياخده **معلمين أكتر** بمجموعتين مختلفتين
 *  - المستوى مالوش **عدد أقصى** — المجموعة ليها
 *  - المستوى بيتقفل أول ما يتقفل البرنامج، والمجموعة بتتغيّر
 *
 * فبصلّنا «المجموعة» ككيان لوحدها، ونربطها بالبرنامج (لازم)
 * وبالمستوى (اختياري) وبالمعلم (اختياري).
 *
 * ─────────────────────────────────────────────────────────────
 * ⚠️ حدود اللي بنعمله دلوقتي
 *
 * الحصة نفسها لسه **طالب واحد** في جدول `lessons` (و
 * `lesson_attendance` فيه `unique('lesson_id')`). فالمجموعة
 * دلوقتي = **قائمة طلاب + عدد أقصى + قائمة انتظار**.
 *
 * تغيير ده لازم يتعمل مع تغير في: `lessons` و`lesson_attendance`
 * و`teacher_earnings`. شغل تاني لو حبينا نعمله.
 *
 * ─────────────────────────────────────────────────────────────
 * ⭐ ليه `capacity` اختياري (`nullable`)؟
 *
 * عشان نعمل «مجموعة مفتوحة» من غير حد. لو خلّيناها إجبارية
 * هنضطر نحط رقم وهمية. `null` معناها **مفيش حد** — وده
 * نفس معنى «مفيش حد» في الواجهة.
 *
 * ⭐ وليه **مافيش عمود للأعضاء المشغولين**؟
 *
 * عشان الرقم يتحسب من `group_members` كل مرة. لو خزّنّاه
 * هيتخرّب في لحظتين: (١) الطالب اتشال من المجموعة،
 * (٢) حد اتضاف. أي حد فيهم بيخلي الرقم غلط لحد ما حد يعمل
 * «تحديث». التحسبي في لحظته مش ممكن يغلط.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ============================================================
        // ① المجموعات
        // ============================================================
        Schema::create('group_classes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();

            // ⭐ لازم — المجموعة جوه برنامج
            $table->foreignId('program_id')->constrained('programs')->cascadeOnDelete();

            // اختياري: المجموعة ممكن تكون «تحت» مستوى أو لأ
            $table->foreignId('level_id')->nullable()->constrained('levels')->nullOnDelete();
            $table->foreignId('teacher_id')->nullable()->constrained('teachers')->nullOnDelete();

            $table->string('name');

            /**
             * ⭐ العدد الأقصى — `null` = مفتوحة (مفيش حد).
             *
             * مش `0`، لأن الصفر معناها «مفيش حد يدخل» وده
             * هيخلي كل مجموعة جديدة مغلقة بالغلط.
             */
            $table->unsignedSmallInteger('capacity')->nullable();

            // ===== أونلاين =====
            // المجموعات أونلاين، فـ meeting_url هو الأساس. مفيد لو
            // الأدمن عايز يبعت اللينك من غير ما يدوّر في كل حصة.
            $table->string('meeting_url', 500)->nullable();
            $table->string('meeting_provider', 50)->nullable();

            // ===== الميعاد الأسبوعي =====
            // `weekday`: صفر = الأحد … ستة = السبت (زي Carbon)
            $table->unsignedTinyInteger('weekday')->nullable();
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();

            /**
             * `active`   = بتزود أعضاء
             * `paused`   = بتوقف مؤقتاً (مفيش إضافة جديد، الأعضاء موجودين)
             * `archived` = خلصت (بتظهر في السجل، مش في العرض العام)
             *
             * ⚠️ «امتلأت» **مش** عمود — بيتحسب
             * (انظر `GroupClass::seatsLeft()`). تخزينه معناها رقم
             * بيكذب بعد أول إضافة أو شيل.
             */
            $table->enum('status', ['active', 'paused', 'archived'])->default('active');

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'sort_order']);
            $table->index('program_id');
        });

        // ============================================================
        // ② الأعضاء
        // ============================================================
        Schema::create('group_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_class_id')->constrained('group_classes')->cascadeOnDelete();

            // ⭐ لازم — العضو طالب في النظام فعلاً (دخل فعلاً)
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();

            // `manual`   = الأدمن ضافه بإيده
            // `waitlist` = دخل من قائمة الانتظار (بعد ما يفيق مقعد)
            $table->enum('source', ['manual', 'waitlist'])->default('manual');

            /**
             * `active` = داخل المجموعة
             * `left`   = ساب (اشتراك خلص، أو خلاص ما عايزش)
             *
             * السبب: لو حذفنا السطر عند خروجه، ضاع تاريخ «كان
             * هنا ٣ شهور». الصف بيفضل، وبيبقى عندنا رقم.
             */
            $table->enum('status', ['active', 'left'])->default('active');

            $table->timestamp('joined_at')->nullable();
            $table->timestamp('left_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            /**
             * ⭐ الطالب في مجموعة واحدة مرة واحدة بس.
             *
             * من غير القيد ده ممكن نفس الطالب يتضاف مرتين فيتحسب
             * مرتين في المقاعد الفاضية.
             */
            $table->unique(['group_class_id', 'student_id']);

            $table->index(['group_class_id', 'status']);
        });

        // ============================================================
        // ③ قائمة الانتظار
        // ============================================================
        Schema::create('waiting_list_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('group_class_id')->constrained('group_classes')->cascadeOnDelete();

            /**
             * ⭐ الاسم + الموبايل — **من غير حساب**.
             *
             * السبب: أكتر الناس اللي بتطلب في المجموعات هم اللي
             * لسه ما عندهمش اشتراك. لو ربطنا السطر بـ `students`
             * كإلزامي، اللي هييجي جديد مش هيقدر يسجّل خالص.
             *
             * الاسم/الموبايل هما اللي بنعرضهم ونتصل بيهما.
             */
            $table->string('name');
            $table->string('phone', 30);

            // لو كان الطالب أو وليّ الأمر مسجّل في النظام، نربطه
            $table->foreignId('student_id')->nullable()->constrained('students')->nullOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('parents')->nullOnDelete();

            /**
             * `waiting`   = مستني
             * `joined`    = الأدمن دخله
             * `declined`  = رفض أو مش عايز (مش بنمسح السطر — عشان
             *              محدش يسجّل تاني على طول)
             *
             * ⚠️ مافيش حالة «اتعرض عليه» عن قصد: القرار إن
             * **الأدمن يدخل** («ينبّه، والأدمن هو اللي يدخله»).
             * فمافيش خطوة في النص بين الانتظار والدخول.
             */
            $table->enum('status', ['waiting', 'joined', 'declined'])->default('waiting');

            $table->text('notes')->nullable();

            /**
             * ⭐ `entered_at` — لحظة التسجيل، **مش** الترقيم.
             *
             * لو عملنا عمود ترتيب، وأحد اتشال أو اتغيّر، الأرقام
             * هتبوظ. الترتيب بيتحسب من `entered_at` + `id` — دي
             * بتثبت للأبد.
             */
            $table->timestamp('entered_at')->useCurrent();
            $table->timestamp('joined_at')->nullable();

            // مين إدخله من لوحة التحكم (سجل النشاط)
            $table->foreignId('admitted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            /**
             * ⭐ نفس الموبايل ما يسجّلش مرتين في نفس المجموعة.
             *
             * من غير القيد ده: حد بيعبّي اسمعه ٥ مرات وبياخد ٥
             * أسطر، ولما يفيق مقعد هندخله ٥ مرات.
             *
             * ⚠️ بصيغة string: `phone` متخزّن كنص، و SQLite بيقارن
             * النص بنفسه. لو كان رقم لقى «0122...» و«122...» مختلفين
             * — واللي بيحط رقم الموباير بيدوسه إنجليزي، فماشي.
             */
            $table->unique(['group_class_id', 'phone']);

            $table->index(['group_class_id', 'status', 'entered_at']);
        });
    }

    public function down(): void
    {
        // ⭐ الترتيب مهم: الجداول المعتمدة على الـ parent بتتشال الأول
        Schema::dropIfExists('waiting_list_entries');
        Schema::dropIfExists('group_members');
        Schema::dropIfExists('group_classes');
    }
};
