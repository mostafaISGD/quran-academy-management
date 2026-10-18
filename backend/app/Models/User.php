<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes, HasRoles;

    protected $fillable = [
        'organization_id', 'name', 'email', 'phone', 'password',
        'timezone', 'locale', 'status', 'email_verified_at', 'last_login_at',
        // ⭐⭐ سياسة كلمة المرور (PR 0.4)
        //
        // ⭐ لازم في `$fillable`: أي `User::create()` أو `update()`
        // من غيرهم **بيتجاهل الحقول بصمت** — مافيش error، بس
        // كلمة مرور الحساب ما بتتغيّرش. ده أخطر نوع باج:
        // الكود بيكتب الصحيح والمكتبة بترميه في الأرض.
        'must_change_password', 'password_changed_at',
    ];

    /**
     * ⭐⭐⭐ `is_parent` بقى **مشتقّ** مش عمود بيانات.
     *
     * ⚠️⚠️⚠️ ليه العمود ده كان خطر
     *
     * ① **اسمه نفس `student_phones.is_parent` وعكس المعنى تماماً.**
     *    العمودين boolean والاثنين بيتكتبوا في نفس الوقت:
     *
     *      users.is_parent          = الحساب **هو** ولي أمر
     *      student_phones.is_parent = الرقم **لـ** ولي أمر
     *
     * ② **مافيش حاجة كانت بتتأكد إنهم متوافقين.** أي حد عنده
     *    `students.edit` يقدر يعمل `User::update(['is_parent' =>
     *    true])` فيخلي أي حساب ولي أمر **من غير `parents` record**.
     *    وبعدها كل استعلام `where('user_id')` على `parents` بيرجّع
     *    **فارغ** — والواجهة بتقول «مينفعش تفتح البوابة» لحساب
     *    «ولي أمر».
     *
     * ③ **كان في اختبارين بيعتمدوا عليه**، فإصلاحه كان
     *    محتاج تعديل الاستعمالات **قبل** تعديل الـcolumn — وده
     *    بالظبط ترتيب الـroadmap.
     *
     * ⭐⭐ **الجواب الوحيد**: ولي الأمر = اللي **عنده** ملف
     * `parents`. مش اللي حد كتب `true` في عمود.
     *
     * ⭐ الـ`Attribute` accessor بيشتغل من غير `select` — يعني
     * `$user->is_parent` بيرجّع `false` لو الـrelation لسه
     * متحمّلةش. `AuthController::profile` بيستخدم `parentProfile`
     * مباشرة، والحارس بيستخدم الـaccessor — والاتنين متسقين لأن
     * أي request بيعمل `load`.
     *
     * ⚠️ العمود في الداتابيز لسه موجود — الشيل في migration
     * بعدين، بعد ما كل الاستعمالات تبقى على المشتقّ.
     *
     * ⭐ **مافيش setter** — `User::update(['is_parent' => true])`
     * هيتجاهل بصمت. وده مقصود: أخطر باج في PHP هو الحقل اللي
     * بيتقبل ويتجاهل في نفس الوقت.
     *
     * @see \Tests\Feature\IdentitySchemaTest
     */
    public function getIsParentAttribute(): bool
    {
        return $this->parentProfile !== null;
    }

    /**
     * ⭐⭐ `is_parent` مش قابل للكتابة من غير قصد.
     *
     * ⭐ متأكدين إن `update()` مابيقبلهوش في صمت **مباشرة**
     * (بقى في الـaccessor)، بس `create()` و`forceFill` بيقبلوا
     * أي عمود موجود. فبنمنعها صريحاً.
     *
     * @param  array<string, mixed>  $value
     */
    public function setIsParentAttribute($value): void
    {
        // ⭐⭐ متعمدين: بنتجاهل بصمت.
        //
        // ⭐ ليه مش استثناء؟ لأن فيه **كود موجود** بيكتب
        // `is_parent` (seeders + اختبار). لو رمينا، الـseeders
        // هيقعوا. و«يتجاهل» أخطر من «يرمي» هنا: الاستثناء بيقول
        // «الكود غلط» وال تجاهل بيقول «الكلام ده مالوش معنى».
        //
        // ⭐ والعمود لسه موجود في الداتابيز، فاللي عايز يشوف
        // القيمة الخام يقدر يقراها بـ
        // `DB::table('users')->value('is_parent')`.
    }

    protected $hidden = [
        'password', 'remember_token', 'student', 'teacher', 'parentProfile',
    ];

    /**
     * Prevent circular references during debugging/backtrace generation.
     */
    public function __debugInfo(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
        ];
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            // ⭐ ملاحظة `is_parent`: مافيش cast ليه عمداً — بقى
            // accessor مشتقّ من `parentProfile`، مش عمود (PR 1.3).
        ];
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function student()
    {
        // return $this->hasOne(Student::class)->without('user');
        return $this->hasOne(Student::class);
    }

    public function teacher()
    {
        // return $this->hasOne(Teacher::class)->without('user');
        return $this->hasOne(Teacher::class);
    }

    public function parentProfile()
    {
        // return $this->hasOne(ParentModel::class)->without('user');
        return $this->hasOne(ParentModel::class);
    }

    /**
     * ⭐⭐ `employees.user_id` موجودة من زمان بس مافيش علاقة.
     *
     * ⭐ من غير العلاقة دي مافيش طريقة سهلة تسأل «حساب ده موظف؟»
     * غير استعلام يدوي — وده اللي بيخلّي حد يكتب الاستعلام **غلط**
     * مرة واحدة، فتتكرر المشكلة.
     *
     * ⭐ `accountant` و`receptionist` و`supervisor` كلهم في
     * `employees`، فلو Relation ناقصة يبقى نص الـأدوار مالهمش ملف
     * شخصي في الـAPI.
     *
     * @see \Tests\Feature\IdentitySchemaTest
     */
    public function employee()
    {
        return $this->hasOne(Employee::class);
    }

    public function notifications()
    {
        return $this->hasMany(Notification::class);
    }

    public function auditLogs()
    {
        return $this->hasMany(AuditLog::class);
    }
}
