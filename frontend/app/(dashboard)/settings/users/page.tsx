"use client";

import { useCallback, useState } from "react";

import {
  archiveUser,
  forceUserPasswordReset,
  getRoles,
  getUsers,
  reactivateUser,
  suspendUser,
  updateUserRole,
  type ManagedUser,
  type RoleOption,
  type UserStatus,
} from "@/lib/api";
import { usePage } from "@/lib/use-page";
import { useCurrentUser } from "@/lib/use-current-user";
import { useUI, IconRefresh, IconSearch } from "@/components/ui";

/**
 * ⭐⭐⭐ `/settings/users` — إدارة الحسابات (PR 1.5).
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐⭐⭐ **مفيش زر «حذف» — وده مقصود ومش ناقص**
 *
 * ⭐ الـbackend مافيش `DELETE /users/{id}` خالص. والحساب بيتقفل
 * بـ**الأرشفة** بس.
 *
 * ⭐ السبب إن `users` هو **رأس الشجرة**: كل فاتورة وكل دفعة وكل
 * سجل حضور بيشير ليه. حذف الصف = كسر كل مرجع، و`audit_logs` بيقول
 * «مين عمل إيه على مين» فبيبقى فيه مرجع لصف مش موجود.
 *
 * ⭐ فالأرشفة بتسيب الصف **والمراجع كلها** سليمة، والصف بيفضل
 * موجود للرجوع للبيانات. الحذف الحقيقي job متعمّد بعد مراجعة —
 * مش زرار في صفحة إدارة.
 *
 * @see backend/app/Http/Controllers/Api/UserController
 */

const STATUS_LABEL: Record<UserStatus, string> = {
  active: "نشط",
  invited: "مدعو — لم يفعّل بعد",
  suspended: "موقوف",
  archived: "مؤرشف",
  inactive: "غير نشط",
};

/**
 * ⭐⭐ لون الحالة — **5 ألوان مش 2**.
 *
 * ⭐ `invited` هو الأول (من PR 1.4) و`archived` من PR 1.5، والاتنين
 * بيمثلوا حالات مختلفة تماماً: واحد مستني، وواحد خلاص. لو
  * ⭐ الاتنين «غير نشط» للمستخدم ما بتوضحش يتصرف إيه.
 */
const STATUS_TONE: Record<UserStatus, string> = {
  active: "bg-emerald-50 text-emerald-700",
  invited: "bg-amber-50 text-amber-700",
  suspended: "bg-orange-50 text-orange-700",
  archived: "bg-slate-100 text-slate-600",
  inactive: "bg-slate-100 text-slate-500",
};

export default function UsersPage() {
  const { toast, confirm, prompt } = useUI();

  const [search, setSearch] = useState("");
  const [status, setStatus] = useState<UserStatus | "">("");
  const [role, setRole] = useState("");
  const [busyId, setBusyId] = useState<number | null>(null);

  // ⭐ الجلب عن طريق `usePage` مش `useEffect` — نفس الـ`settings`
  const fetchUsers = useCallback(
    () =>
      getUsers({
        search: search || undefined,
        status: status || undefined,
        role: role || undefined,
      }),
    [search, status, role],
  );

  const { data, loading, error, reload } = usePage("users", fetchUsers, {
    initial: { data: [], meta: { current_page: 1, last_page: 1, per_page: 25, total: 0 } },
  });

  const fetchRoles = useCallback(() => getRoles(), []);

  const { data: rolesData } = usePage("user-roles", fetchRoles, {
    initial: { data: [] as RoleOption[] },
  });

  const users = data?.data ?? [];

  // ⭐⭐ **الأزرار بتظهر حسب صلاحية المستخدم الحالي** — مش
  // حسب حالة الصف.
  //
  // ⭐ دي راحة مش أمان: الـbackend هو اللي بيرفض بـ403. الغرض إن
  // المستخدم **ما يشوفش** زرار هيصدم بخطأ — نفس منطق
  // `nav-filter.ts`.
  //
  // ⭐⚠️ مافيش حاجة في الصفحة بتحتاج `me` غير قراءة `permissions`.
  // `fetchMe` — فبنستخدم `useCurrentUser`. لو بقى `null` (لسه
  // بنحمّل)، **مبنحطّش** أي زرار. أأمن من إظهار زرار لحد مالوش حق.
  const { user: me } = useCurrentUser();

  const can = (permission: string) => Boolean(me?.permissions?.includes(permission));

  async function run(id: number, action: () => Promise<unknown>, ok: string) {
    setBusyId(id);
    try {
      await action();
      toast.success(ok);
      reload();
    } catch (err) {
      // ⭐ `ApiError.message` بييجي بالعربي من الـbackend — فور
      // 422 «ده آخر مدير نشط» بتوصل للمستخدم زي ما هي.
      toast.error("العملية مرفوضة", err instanceof Error ? err.message : undefined);
    } finally {
      setBusyId(null);
    }
  }

  async function onSuspend(u: ManagedUser) {
    // ⭐⭐ السبب **إجباري** (`min:5`). من غيره الإيقاف عشوائي،
    // وبعد شهور محدش يفتحم remembers ليه وقف فلان.
    const reason = await prompt({
      title: "إيقاف الحساب",
      message: `ليه بنوقف «${u.name}»؟ السبب هيتسجل في سجل العمليات ومينفعش يتغيّر بعدين.`,
      label: "السبب",
      confirmLabel: "أوقف",
      tone: "danger",
    });

    if (!reason) return;

    await run(u.id, () => suspendUser(u.id, reason), "الحساب اتوقف");
  }

  async function onArchive(u: ManagedUser) {
    const reason = await prompt({
      title: "أرشفة الحساب",
      message: `⚠️ أرشفة «${u.name}» **نهائية** — مافيش رجوع للواجهة.\n\nالبيانات كلها هتفضل محفوظة، بس الحساب مش هيفتح تاني.`,
      label: "السبب",
      confirmLabel: "أرشف",
      tone: "danger",
    });

    if (!reason) return;

    await run(u.id, () => archiveUser(u.id, reason), "الحساب اتأرشف");
  }

  async function onReactivate(u: ManagedUser) {
    const ok = await confirm({
      title: "إعادة التفعيل",
      message: `«${u.name}» هيرجع نشط.\n\nالجلسات والتوكينات القديمة هتفضل ميتة — لازم يسجّل دخول من جديد.`,
      confirmLabel: "فعّل",
    });

    if (!ok) return;

    await run(u.id, () => reactivateUser(u.id), "الحساب رجع نشط");
  }

  async function onForceReset(u: ManagedUser) {
    const ok = await confirm({
      title: "إعادة تعيين كلمة المرور",
      message: `هتقفل كل جلسات «${u.name}» وتوكيناته، وهو هيتطلب تغيير كلمة المرور أول ما يدخل.\n\nمحدش بيعرف كلمة مروره — هو اللي يختارها.`,
      confirmLabel: "اطلب التغيير",
    });

    if (!ok) return;

    await run(u.id, () => forceUserPasswordReset(u.id), "اتطلب تغيير كلمة المرور");
  }

  async function onRole(u: ManagedUser, roleId: string) {
    const id = Number(roleId);
    if (!id || id === 0) return;

    const roleName = rolesData?.data.find((r) => r.id === id)?.name ?? "؟";

    const ok = await confirm({
      title: "تغيير الدور",
      message: `«${u.name}» دوره هيبقى «${roleName}».\n\nالدور القديم هيتشال — مش هيتضاف.`,
      confirmLabel: "غيّر",
    });

    if (!ok) return;

    await run(u.id, () => updateUserRole(u.id, id), "الدور اتغيّر");
  }

  return (
    <div>
      <div className="mb-6 flex items-center justify-between">
        <h1 className="text-lg font-semibold text-slate-800">الحسابات</h1>
        <button
          onClick={reload}
          className="inline-flex items-center gap-1 rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-medium text-slate-600 transition hover:bg-slate-50"
        >
          <IconRefresh size={14} /> تحديث
        </button>
      </div>

      {/* ⭐ البحث والفلترة */}
      <div className="mb-4 flex flex-wrap gap-3">
        <div className="relative flex-1 min-w-52">
          <IconSearch size={14} className="pointer-events-none absolute start-3 top-1/2 -translate-y-1/2 text-slate-400" />
          <input
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="ابحث بالاسم أو الإيميل"
            className="w-full rounded-lg border border-slate-300 ps-9 pe-3 py-2 text-sm"
          />
        </div>

        <select
          value={status}
          onChange={(e) => setStatus(e.target.value as UserStatus | "")}
          className="rounded-lg border border-slate-300 px-3 py-2 text-sm"
        >
          <option value="">كل الحالات</option>
          {(Object.keys(STATUS_LABEL) as UserStatus[]).map((s) => (
            <option key={s} value={s}>{STATUS_LABEL[s]}</option>
          ))}
        </select>

        <select
          value={role}
          onChange={(e) => setRole(e.target.value)}
          className="rounded-lg border border-slate-300 px-3 py-2 text-sm"
        >
          <option value="">كل الأدوار</option>
          {(rolesData?.data ?? []).map((r) => (
            <option key={r.id} value={r.name}>{r.name}</option>
          ))}
        </select>
      </div>

      {loading && <p className="text-sm text-slate-500">جارٍ التحميل...</p>}
      {error && <p className="mb-4 text-sm text-red-600">{error}</p>}

      {!loading && !error && users.length === 0 && (
        <p className="text-sm text-slate-400">لا توجد حسابات مطابقة</p>
      )}

      {!loading && users.length > 0 && (
        <div className="overflow-hidden rounded-xl border border-slate-200 bg-white">
          <table className="w-full text-sm">
            <thead className="bg-slate-50 text-xs text-slate-500">
              <tr>
                <th className="px-4 py-3 text-start font-medium">الاسم</th>
                <th className="px-4 py-3 text-start font-medium">الإيميل</th>
                <th className="px-4 py-3 text-start font-medium">الأدوار</th>
                <th className="px-4 py-3 text-start font-medium">الحالة</th>
                <th className="px-4 py-3 text-start font-medium">إجراءات</th>
              </tr>
            </thead>
            <tbody>
              {users.map((u) => (
                <tr key={u.id} className="border-t border-slate-100">
                  <td className="px-4 py-3 text-slate-800">
                    {u.name}
                    {/* ⭐⭐ تنبيه إن الحساب كلّفه تغيير كلمة المرور */}
                    {u.must_change_password && (
                      <span className="ms-2 text-xs text-amber-600">مطلوب تغيير كلمة المرور</span>
                    )}
                  </td>
                  <td className="px-4 py-3 text-slate-600">{u.email ?? "—"}</td>
                  <td className="px-4 py-3 text-slate-600">
                    {u.roles.length ? u.roles.join(" · ") : "—"}
                  </td>
                  <td className="px-4 py-3">
                    <span className={`rounded-full px-2 py-1 text-xs font-medium ${STATUS_TONE[u.status]}`}>
                      {STATUS_LABEL[u.status]}
                    </span>
                  </td>
                  <td className="px-4 py-3">
                    <RowActions
                      u={u}
                      busy={busyId === u.id}
                      roles={rolesData?.data ?? []}
                      can={can}
                      onSuspend={onSuspend}
                      onReactivate={onReactivate}
                      onArchive={onArchive}
                      onForceReset={onForceReset}
                      onRole={onRole}
                    />
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {!loading && data && data.meta.total > 0 && (
        <p className="mt-3 text-xs text-slate-500">
          {data.meta.total} حساب
        </p>
      )}
    </div>
  );
}

/**
 * ⭐⭐ أزرار الصف — **مفصولة** عن الصفحة الرئيسية.
 *
 * ⭐ السبب إن فيها `can(...)` × 4 و`u.status` × 4 = ١٦ حالة. لو
 * كانت جوّه الـJSX، قراءة «إيه الظاهر؟» بقت محتاجة.scroll
 * في ملف ٤٠٠ سطر.
 */
function RowActions({
  u,
  busy,
  roles,
  can,
  onSuspend,
  onReactivate,
  onArchive,
  onForceReset,
  onRole,
}: {
  u: ManagedUser;
  busy: boolean;
  roles: RoleOption[];
  can: (permission: string) => boolean;
  onSuspend: (u: ManagedUser) => void;
  onReactivate: (u: ManagedUser) => void;
  onArchive: (u: ManagedUser) => void;
  onForceReset: (u: ManagedUser) => void;
  onRole: (u: ManagedUser, roleId: string) => void;
}) {
  const cls = "rounded-lg px-2.5 py-1.5 text-xs font-medium transition disabled:opacity-50";

  return (
    <div className="flex flex-wrap items-center gap-2">
      {/* ⭐ الدور: `<select>` مش modal — عملية تكرار، ومشDialog كل مرة */}
      {can("users.roles.assign") && (
        <select
          value=""
          onChange={(e) => e.target.value && onRole(u, e.target.value)}
          disabled={busy}
          className="rounded-lg border border-slate-300 px-2 py-1.5 text-xs text-slate-600"
        >
          <option value="">تغيير الدور…</option>
          {roles.map((r) => (
            <option key={r.id} value={r.id}>{r.name}</option>
          ))}
        </select>
      )}

      {/* ⭐ الإيقاف متاح لكل حالة **غير** `archived` — الأرشفة نهائية */}
      {can("users.suspend") && u.status !== "archived" && u.status !== "active" && (
        <button
          disabled={busy}
          onClick={() => onReactivate(u)}
          className={`${cls} bg-emerald-50 text-emerald-700 hover:bg-emerald-100`}
        >
          تفعيل
        </button>
      )}

      {can("users.suspend") && u.status === "active" && (
        <button
          disabled={busy}
          onClick={() => onSuspend(u)}
          className={`${cls} bg-orange-50 text-orange-700 hover:bg-orange-100`}
        >
          إيقاف
        </button>
      )}

      {can("users.suspend") && (
        <button
          disabled={busy}
          onClick={() => onForceReset(u)}
          className={`${cls} bg-blue-50 text-blue-700 hover:bg-blue-100`}
        >
          تغيير كلمة المرور
        </button>
      )}

      {/* ⭐ الأرشفة بتظهر للحالات التلاتة القابلة للأرشفة بس */}
      {can("users.archive") && u.status !== "archived" && (
        <button
          disabled={busy}
          onClick={() => onArchive(u)}
          className={`${cls} bg-slate-100 text-slate-600 hover:bg-slate-200`}
        >
          أرشفة
        </button>
      )}
    </div>
  );
}
