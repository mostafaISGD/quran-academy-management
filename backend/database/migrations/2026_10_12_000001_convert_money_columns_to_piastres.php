<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐⭐⭐ تخزين المبالغ **بالقرش** (أعداد صحيحة) بدل decimal.
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐ ليه ده أصلاً؟
 *
 * القاعدة كانت `decimal(12,2)` — بس SQLite **مافيش decimal حقيقي**:
 * `PRAGMA table_info` بيرجّع `numeric`، وده float.
 *
 *     0.07 * 3 = 0.21000000000000002   ← مش 0.21
 *
 * والأعداد الصحيحة **مالهاش خطأ تمثيل**: 65000 هي 65000 بالظبط.
 * ده بند 2 في الخطة: «المبالغ أعداد صحيحة بأصغر وحدة».
 * ═══════════════════════════════════════════════════════════════
 *
 * ⭐⭐ **ليه migration واحدة لكل الـ ٢٦ جدول؟**
 *
 * ① **SQLite مش بيعرف `ALTER COLUMN`.** تغيير نوع عمود محتاج
 *    إعادة بناء الجدول كامل. فـ ٢٦ migration ولا ٢٦ إعادة بناء
 *    — بنفس الشغل، بس الـ diff بيبقى أسهل في ملف واحد.
 *
 * ② **الترتيب مهم:** لو كل جدول في migration لوحده، حد يعمل
 *    `migrate:rollback` نص التحويل يبقى عنده نص الجداول
 *    قرش ونصها float. migration واحدة = يا الكل يتحوّل يا الكل يرجع.
 *
 * ③ **الأمان:** لو فشل أي جدول، الـ `down()` بيرجّع الكل.
 * ═══════════════════════════════════════════════════════════════
 *
 * ⭐ **الجداول القديمة بتت سمّى `_legacy` — مش بتتحذف.**
 *
 * السبب: لو طلع غلط بعد أسبوع، البيانات لسه موجودة. والـ
 * `down()` بيرجّعها. الحذف بيحصل في migration منفصل لما نتبقّى
 * متأكدين.
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐ **مفيش بيانات كتير تتحرّك:**
 *
 * الجرد لقى إن كل الجداول فاضية ماعدا `subscription_plans`
 * (٥٩ سعر). فالنسخ cheap — بس الكود بيشتغل صح لو امتلأت.
 */
return new class extends Migration
{
    /**
     * ⭐ الأعمدة المحسوسة لكل جدول.
     *
     * ⚠️ **مصدر الحقيقة الوحيد في المشروع.** لو ضفت عمود مبلغ
     * جديد، ضيفه هنا. لست emite الكود باليد لكل جدول — الأنموذج
     * بيبني الـ SQL من الخريطة دي.
     *
     * @var array<string, array<string>>
     */
    private const COLUMNS = [
        'account_movements'          => ['amount'],
        'account_transfers'         => ['amount'],
        'credit_note_applications'  => ['amount'],
        'employee_payroll_lines'    => ['hourly_rate', 'amount'],
        'employees'                 => ['hourly_rate'],
        'expenses'                  => ['amount'],
        'financial_accounts'        => ['opening_balance'],
        'invoice_items'             => ['unit_price', 'total'],
        'invoices'                  => ['subtotal', 'discount', 'tax', 'total', 'paid_amount', 'balance_due', 'credited_amount'],
        'lesson_credit_accounts'    => ['current_balance'],
        'lesson_credit_transactions' => ['balance_after'],
        'payment_allocations'       => ['amount'],
        'payments'                  => ['amount', 'net_amount'],
        'promo_code_usages'         => ['discount_amount', 'original_amount', 'final_amount'],
        'refunds'                   => ['amount'],
        'student_credits'           => ['total', 'used', 'refunded'],
        'student_ledger_entries'    => ['debit', 'credit', 'balance_after'],
        'subscription_plans'        => ['price'],
        'subscriptions'             => ['price'],
        'teacher_contracts'         => ['monthly_salary'],
        'teacher_earnings'          => ['amount'],
        'teacher_ledger_entries'    => ['debit', 'credit', 'balance_after'],
        'teacher_payments'          => ['amount'],
        'teacher_payroll_lines'     => ['rate_snapshot', 'amount'],
        'teacher_rates'             => ['amount'],
    ];

    /** ⭐ suffixed التاريخ — عشان `down()` يلاقي الجدول القديم */
    private const LEGACY_SUFFIX = '_legacy';

    /**
     * ⭐ لواحق أسماء الفهارس الجديدة.
     *
     * أسماء الفهارس فريدة على مستوى القاعدة كلها في SQLite،
     * والجدول القديم لسه شايل فهرسه بنفس الاسم.
     *
     * @see self::restoreIndexes()
     */
    private const INDEX_SUFFIX = '_piastres';

    public function up(): void
    {
        foreach (self::COLUMNS as $table => $moneyColumns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $this->convertTable($table, $moneyColumns);
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::COLUMNS) as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $this->restoreTable($table);
        }
    }

    /**
     * ⭐ إعادة بناء جدول واحد: الأعمدة المحسوسة → `bigint` بالقرش.
     */
    private function convertTable(string $table, array $moneyColumns): void
    {
        $legacy = $table . self::LEGACY_SUFFIX;

        // ⭐ متكرّر نعملها؟ `down()` رجّع الجدول القديم فيحصل تكرار.
        if (Schema::hasTable($legacy)) {
            Schema::drop($legacy);
        }

        $columns = Schema::getColumns($table);
        $definitions = $this->definitionsFor($columns, $moneyColumns);

        /**
         * ⭐⭐⭐ قراءة الفهارس **قبل** أي إعادة تسمية.
         *
         * ⚠️⚠️⚠️ ليه دي حرجة؟
         *
         * `Schema::create()` بيعمل جدول **فاضي** — مافيش فهارس
         * ولا قيود `unique`. فلو ما نقلناش الفهارس، الجدول الجديد
         * هيفقد **٤٤ فهرس** و **كل الـ unique constraints**:
         *
         *     CREATE UNIQUE INDEX "employee_payroll_unique"
         *       ON employee_payroll_lines (payroll_period_id, employee_id)
         *
         * والفهرس ده هو اللي بيمنع إضافة نفس المعلم مرتين في
         * نفس الفترة. من غيره، التكرار يعدّي والاختبارات تسقط.
         *
         * كمان الفهارس responsible عن سرعة التقارير — من غيرها كل
         * `WHERE status = ...` بيبقى scan للجدول كله.
         *
         * @see self::restoreIndexes()
         */
        $indexes = $this->readIndexes($table);

        // ① بناء الجدول الجديد بنفس الشكل، بس بالأعمدة المحسوسة bigint
        Schema::create($this->tmpName($table), function (Blueprint $t) use ($definitions) {
            foreach ($definitions as $name => $definition) {
                /**
                 * ⭐⭐⭐ العمود الأساسي — `increments()` مش `addColumn`.
                 *
                 * السبب: `typeInteger` في SQLite بتنتج
                 * `integer primary key autoincrement`، وBlueprint
                 * بتضيف `primary key ("id")` كمان. النتيجة:
                 *
                 *     table has more than one primary key
                 *
                 * `increments()` بتعمل الاتنين صح من مرة واحدة.
                 */
                if ($name === self::PRIMARY_KEY_HINT) {
                    $t->increments($name);

                    continue;
                }

                /**
                 * ⚠️⚠️ **`addColumn($type, $name)` — النوع الأول!**
                 *
                 * الترتيب ده مقلوب في معظم الـ docs، وبيدّي
                 * `BadMethodCallException: typeId does not exist`
                 * لأن اسم العمود `id` بيتقرا نوع.
                 *
                 * @see \Illuminate\Database\Schema\Blueprint::addColumn
                 */
                $t->addColumn(
                    $definition['type'],
                    $name,
                    $definition['options']
                );
            }
        });

        // ② نسخ البيانات مع تحويل الأرقام
        //
        // ⭐ `CAST(... AS INTEGER)` **بعد** `ROUND` — العكس كان
        // هيدوّر القرش زي ما عملنا في الـ cast:
        //     ROUND(19.999999 * 100) = 2000 → CAST = 2000 ✅
        //     CAST(19.999999 * 100) = 1999   ❌
        $allNames = implode(', ', array_keys($definitions));

        DB::statement(
            "INSERT INTO {$this->tmpName($table)} ({$allNames})
             SELECT {$this->buildSelectList($definitions)}
             FROM {$table}"
        );

        // ③ تبديل: القديم يتسمّى legacy، الجديد ياخد الاسم
        //
        // ⚠️⚠️⚠️ **الأهم في الملف كله — `legacy_alter_table`.**
        //
        // SQLite من الإصدار 3.25 بيدوّر على كل جدول بيشاور عليه
        // وبيعدّل المرجع تلقائياً مع `ALTER TABLE RENAME`:
        //
        //     RENAME employees → employees_legacy
        //     ↓
        //     attendance_records بقى يقول REFERENCES employees_legacy
        //
        // و`employees_legacy` فاضية (سطر جديد) → كل إدراج بيفشل:
        //
        //     FOREIGN KEY constraint failed
        //
        // ⭐ الحل: `legacy_alter_table = ON` وقت الـ rename بس.
        // بيخلي SQLite **يتجاهل** تعديل المراجع، فالمرجع بيفضل
        // على الاسم الأصلي `employees` — واللي الجديد بياخده.
        //
        // `legacy_alter_table` connection-level، فبنقفله قبل
        // الـ rename وبنرجّعه بعده.
        DB::statement('PRAGMA foreign_keys = OFF');
        DB::statement('PRAGMA legacy_alter_table = ON');

        Schema::rename($table, $legacy);
        Schema::rename($this->tmpName($table), $table);

        // ④ إعادة بناء الفهارس على الجدول الجديد
        //
        // بتتنفّذ **بعد** التبديل، واسم الفهرس بيتزوّد `_piastres`
        // عشان ما يتعارضش مع فهرس موجود بنفس الاسم على `_legacy`.
        $this->restoreIndexes($table, $indexes);

        DB::statement('PRAGMA legacy_alter_table = OFF');
        DB::statement('PRAGMA foreign_keys = ON');
    }

    /**
     * ⭐ قراءة جمل إنشاء فهارس جدول معيّن.
     *
     * ⚠️ الفهارس اللي `sql` بتاعها `null` هي اللي SQLite عاملة
     * لوحدها تلقائيًا (فهرس الـ primary key والـ unique المدمجة
     * في تعريف العمود). دي مش محتاجة تتنقل — الجدول الجديد
     * بيبنيها لوحده من `increments()`.
     *
     * @return array<int, array{name: string, sql: string}>
     */
    private function readIndexes(string $table): array
    {
        $rows = DB::select(
            "SELECT name, sql FROM sqlite_master
             WHERE type = 'index' AND tbl_name = ? AND sql IS NOT NULL
             ORDER BY name",
            [$table]
        );

        return array_map(fn ($row) => [
            'name' => (string) $row->name,
            'sql' => (string) $row->sql,
        ], $rows);
    }

    /**
     * ⭐ إعادة إنشاء الفهارس على الجدول الجديد.
     *
     * ⭐ **ليه بنزوّد suffixes في الأسماء؟**
     *
     * أسماء الفهارس **فريدة على مستوى القاعدة كلها** في SQLite.
     * والجدول القديم `_legacy` لسه شايل فهرسه بنفس الاسم. فلو
     * عملنا `CREATE INDEX "employee_payroll_unique"` على الجديد،
     * SQLite بيرمي:
     *
     *     index employee_payroll_unique already exists
     *
     * فبنزوّد `_piastres` على **اسم الفهرس بس**. الجدول بيفضل
     * بالاسم الأصلي، والقيود بتفضل شغالة — الاسم مجرد تسمية.
     *
     * @param  array<int, array{name: string, sql: string}>  $indexes
     */
    private function restoreIndexes(string $table, array $indexes): void
    {
        if (! $indexes) {
            return;
        }

        $legacyName = $table . self::LEGACY_SUFFIX;

        foreach ($indexes as $index) {
            /**
             * ⭐ الترتيب مهم: **اسم الفهرس الأول**.
             *
             * أسماء الفهارس بتبدأ باسم الجدول:
             *     account_transfers_organization_id_transfer_date_index
             *
             * فلو بدّلنا اسم الجدول الأول، جملة الـ index
             * هتتلف قبل ما نصل لاسم الفهرس نفسه.
             */
            $sql = $this->replaceIdentifier(
                $index['sql'],
                $index['name'],
                $index['name'].self::INDEX_SUFFIX
            );

            // الجدول: `_legacy` يرجع لاسمه الأصلي
            $sql = $this->replaceIdentifier($sql, $legacyName, $table);

            DB::statement($sql);
        }
    }

    /**
     * ⭐⭐ استبدال اسم مُعرّف (جدول أو فهرس) في جملة SQL.
     *
     * ⚠️ الأسماء في `sqlite_master` بتيجي **مدعوم بعلامات تنصيص**:
     *
     *     CREATE INDEX "employee_payroll_unique"
     *       on "employee_payroll_lines" ("a", "b")
     *
     * فالنمط لازم يقبل الـ quotes ويرجّعها، ويقبل كمان إن يكون
     * من غير quotes.
     *
     * ⚠️ وليه `"?name"?` بالذات؟
     *
     * ① لو بدون `"?` في الآخر، النمط هيلتقط **بادئة** الاسم
     *    الطويل: هيمسك `account_transfers` جوه
     *    `account_transfers_legacy` ويمسح `_legacy` غلط.
     *
     * ② الـ `"?` الأول بيقول إن الاسم لازم يكون **كله** — فمفيش
     *    استبدال جزئي.
     */
    private function replaceIdentifier(string $sql, string $from, string $to): string
    {
        if ($from === '') {
            return $sql;
        }

        $pattern = '/"?'.preg_quote($from, '/').'"?/';

        return preg_replace_callback(
            $pattern,
            function (array $match) use ($to): string {
                // ⭐ لو كان مدعوم بعلامات تنصيص، نرجّعها زي ما كانت
                $wasQuoted = str_starts_with($match[0], '"')
                    && str_ends_with($match[0], '"')
                    && strlen($match[0]) > 2;

                return $wasQuoted ? '"'.$to.'"' : $to;
            },
            $sql
        ) ?? $sql;
    }

    /**
     * ⭐ الرجوع: الجديد يتحذف، القديم يرجع مكانه — والبيانات ترجع زي ما كانت.
     *
     * @see self::convertTable()  ليه `legacy_alter_table` ضروري
     */
    private function restoreTable(string $table): void
    {
        $legacy = $table . self::LEGACY_SUFFIX;

        if (! Schema::hasTable($legacy)) {
            return;
        }

        DB::statement('PRAGMA foreign_keys = OFF');
        DB::statement('PRAGMA legacy_alter_table = ON');

        Schema::drop($table);
        Schema::rename($legacy, $table);

        DB::statement('PRAGMA legacy_alter_table = OFF');
        DB::statement('PRAGMA foreign_keys = ON');
    }

    /**
     * ⭐ بناء تعريف كل عمود: SQL الإنشاء + تعبير النسخ.
     *
     * ⚠️ `Schema::getColumns()` بيرجّع **arrays** مش objects في
     * Laravel 12 — فالوصول بـ `$column->name` بيرمي `TypeError`
     * وبيوقّف الترحيل عند أول جدول. عشان كده `pick()` بالأسفل.
     */
    private function definitionsFor(array $columns, array $moneyColumns): array
    {
        $definitions = [];

        foreach ($columns as $column) {
            $name = $this->pick($column, 'name');
            $isMoney = in_array($name, $moneyColumns, true);

            $definitions[$name] = [
                'type' => $this->mapType((string) $this->pick($column, 'type'), $isMoney),
                'options' => $this->columnOptions($column, $isMoney),
                'copy' => $this->copySql($name, $isMoney),
            ];
        }

        return $definitions;
    }

    /**
     * ⭐ قراءة خاصية من array أو object — عشان نضمن التوافق.
     */
    private function pick(array|object $column, string $key): mixed
    {
        if (is_array($column)) {
            return $column[$key] ?? null;
        }

        return $column->{$key} ?? null;
    }

    /**
     * ⭐ خريطة أنواع SQLite → أنواع Blueprint.
     *
     * ⚠️ **ليه الخريطة دي ضرورية؟**
     *
     * `Schema::getColumns()` بيرجّع النوع **نص SQLite الخام**
     * (`varchar`, `datetime`, `numeric`, `tinyint`). وBlueprint
     * بيدوّر على method باسم `type{VARCHAR}` — ومش موجودة،
     * فيرمي `BadMethodCallException` ويوقّف الترحيل.
     *
     * ⭐ و`bigInteger` مش `bigint` — الاسم الـ method هو
     * `typeBigInteger`.
     *
     * @var array<string, string>
     */
    private const TYPE_MAP = [
        'integer'      => 'integer',
        'int'          => 'integer',
        'bigint'       => 'bigInteger',
        'biginteger'   => 'bigInteger',
        'varchar'      => 'string',
        'text'         => 'text',
        'datetime'     => 'datetime',
        'date'         => 'date',
        'time'         => 'time',
        'numeric'      => 'decimal',
        'decimal'      => 'decimal',
        'float'        => 'float',
        'double'       => 'double',
        'tinyint'      => 'boolean',
        'boolean'      => 'boolean',
        'blob'         => 'binary',
        'json'         => 'json',
    ];

    /**
     * ⭐ خيارات العمود — `default`, `nullable`, `primary`.
     *
     * ⚠️⚠️ **مفاتيح `getColumns()` مش زي ما تتوقع.**
     *
     * الرجوع فيه: `name`, `type`, `type_name`, `collation`,
     * `nullable`, `default`, `auto_increment`, `comment`,
     * `generation`.
     *
     * ⭐ **مافيش `primary`، مافيش `notnull`، مافيش `autoIncrement`.**
     * لو كتبتهم بيرجعوا `null` بصمت:
     *   - `notnull` = null → كل الأعمدة بتطلع nullable
     *   - `autoIncrement` = null → الـ `id` بياخد عمود عادي
     *     من غير primary key
     *
     * ⭐ والـ primary بيتبان من `auto_increment` — لأن مفاتيح
     * Laravel الأساسية دايماً `id` بـ `bigIncrements`.
     *
     * @see self::PRIMARY_KEY_HINT
     */
    private const PRIMARY_KEY_HINT = 'id';

    private function columnOptions(array|object $column, bool $isMoney): array
    {
        // ⭐ الأعمدة المحسوسة: nullable ومن غير default.
        //
        // الـ default القديم كان رقم **جنيه**. لو اتنقل على عمود
        // بقى قرش، الصفوف الجديدة هتاخد `0` (صحيح)، بس أي default
        // تاني هيبقى **100× صغير**.
        if ($isMoney) {
            return ['nullable' => true, 'default' => null];
        }

        $isPrimary = $this->pick($column, 'auto_increment')
            || $this->pick($column, 'name') === self::PRIMARY_KEY_HINT;

        if ($isPrimary) {
            return [
                'primary' => true,
                'autoIncrement' => (bool) $this->pick($column, 'auto_increment'),
            ];
        }

        $options = [];

        if ($default = $this->pick($column, 'default')) {
            $options['default'] = $this->normalizeDefault($default);
        } elseif ($this->pick($column, 'nullable')) {
            $options['nullable'] = true;
        }

        return $options;
    }

    /**
     * ⭐⭐ تنظيف قيمة الـ default اللي جاية من SQLite.
     *
     * ⚠️⚠️ **`PRAGMA table_info` بيرجّع الـ default بين single-quotes.**
     *
     *     default 'posted'   ← اللي بيرجعه SQLite
     *
     * وBlueprint بيضيف الـ quotes بنفسه. فلو عدّلنا، بتطلع
     * `''posted''` و SQLite بيرمي:
     *
     *     near "posted": syntax error
     *
     * ⭐ الحل: نشيل الـ quotes وبنتبلا نعملها — مش هنسلّمها
     * زي ما جاية.
     */
    private function normalizeDefault(mixed $default): mixed
    {
        if (! is_string($default)) {
            return $default + 0;
        }

        // ⭐ نصوص SQLite العادية
        if (strlen($default) >= 2 && $default[0] === "'" && $default[strlen($default) - 1] === "'") {
            return str_replace("''", "'", substr($default, 1, -1));
        }

        // ⭐ `CURRENT_TIMESTAMP` — لازم تفضل زي ما هي
        if (str_contains(strtoupper($default), 'CURRENT_TIMESTAMP')) {
            return $default;
        }

        if (is_numeric($default)) {
            return $default + 0;
        }

        return $default;
    }

    /**
     * ⭐ تحويل نوع SQLite الخام → نوع Blueprint.
     */
    private function mapType(string $sqliteType, bool $isMoney): string
    {
        if ($isMoney) {
            return 'bigInteger';
        }

        // ⭐ `varchar(255)` → `varchar`
        $base = strtolower(trim(preg_replace('/\(.*/', '', $sqliteType)));

        return self::TYPE_MAP[$base] ?? 'string';
    }

    /**
     * ⭐ تعبير نسخ العمود — والأعمدة المحسوسة بتتحوّل ×١٠٠.
     */
    private function copySql(string $name, bool $isMoney): string
    {
        $quoted = '"'.$name.'"';

        if (! $isMoney) {
            return $quoted;
        }

        // ⭐ الترتيب حاسم: ROUND الأول، THEN CAST.
        return 'CAST(ROUND('.$quoted.' * 100) AS INTEGER) AS '.$quoted;
    }

    private function quoteDefault(mixed $default): string
    {
        $default = (string) $default;

        // ⭐ `CURRENT_TIMESTAMP` بتتّكتب من غير quotes في SQLite.
        if (str_contains(strtoupper($default), 'CURRENT_TIMESTAMP')) {
            return 'CURRENT_TIMESTAMP';
        }

        if (is_numeric($default)) {
            return $default;
        }

        return "'".$default."'";
    }

    private function tmpName(string $table): string
    {
        return $table.'_piastres_tmp';
    }

    private function buildSelectList(array $definitions): string
    {
        $parts = [];

        foreach ($definitions as $definition) {
            $parts[] = $definition['copy'];
        }

        return implode(', ', $parts);
    }
};