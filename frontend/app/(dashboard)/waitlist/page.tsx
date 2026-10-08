"use client";

import { useCallback, useEffect, useMemo, useState } from "react";
import {
  createWaitlistEntry,
  deleteWaitlistEntry,
  getWaitlist,
  updateWaitlistEntry,
  admitFromWaitlist,
  getGroups,
  getPricing,
  type WaitlistEntry,
  type WaitlistPayload,
  type PricingGroup,
} from "@/lib/api";
import { money, text } from "@/lib/format";
import { useUI } from "@/components/ui";

/**
 * ⭐ صفحة **قائمة الانتظار** — مستقلة عن صفحة المجموعات.
 *
 * ليه مستقلة؟
 *
 * الطلب مش لازم يكون لمجموعة معينة. لما ييجي وقت ونفتح
 * مجموعة جديدة، بنجيب كل المستنيين بنفس المستوى ونحطهم
 * فيها — فلو الطلب مربوط بمجموعة من الأول، ده هيمنعنا.
 *
 * ⭐ الحقول الإضافية (هاتف ولي الأمر / المستوى الحالي / الباقة
 * المطلوبة) بتستخدم **وقت فتح المجموعة الجديدة** — عشان كده
 * مخزّنة هنا مش في طلب الانتظار العادي.
 */

interface GroupOption {
  id: number;
  name: string;
}

/** الباقات مجمّعة حسب النوع، عشان الـ dropdown يبقى مقروء */
interface PackageOption {
  id: number;
  name: string;
  /** ⭐ السعر **رقم** — `PricingPlan.price` */
  price: number;
  currency: string;
  lessons_count: number | null;
  lesson_duration_minutes: number;
  category_label: string;
}

const STATUS_LABELS: Record<WaitlistEntry["status"], string> = {
  waiting: "مستني",
  joined: "دخل",
  declined: "مرفوض",
};

const STATUS_CLASSES: Record<WaitlistEntry["status"], string> = {
  waiting: "bg-amber-100 text-amber-700",
  joined: "bg-emerald-100 text-emerald-700",
  declined: "bg-rose-100 text-rose-700",
};

const EMPTY_FORM: WaitlistPayload = {
  name: "",
  phone: "",
  parent_phone: "",
  current_level: "",
  package_id: null,
  proposed_group_id: null,
  group_class_id: null,
  notes: "",
};

export default function WaitlistPage() {
  const { toast, confirm } = useUI();

  const [entries, setEntries] = useState<WaitlistEntry[]>([]);
  const [total, setTotal] = useState(0);
  const [loading, setLoading] = useState(true);
  const [busyId, setBusyId] = useState<number | null>(null);

  // ===== الفلاتر =====
  const [statusFilter, setStatusFilter] = useState<WaitlistEntry["status"] | "">("");
  const [groupFilter, setGroupFilter] = useState<number | "">("");
  const [packageFilter, setPackageFilter] = useState<number | "">("");
  const [search, setSearch] = useState("");

  // ===== بيانات الفلاتر =====
  const [groups, setGroups] = useState<GroupOption[]>([]);
  const [packages, setPackages] = useState<PackageOption[]>([]);

  // ===== المودال =====
  const [showModal, setShowModal] = useState(false);
  const [editing, setEditing] = useState<WaitlistEntry | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const [form, setForm] = useState<WaitlistPayload>(EMPTY_FORM);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const res = await getWaitlist({
        ...(statusFilter ? { status: statusFilter } : {}),
        ...(groupFilter ? { group_id: groupFilter } : {}),
        ...(packageFilter ? { package_id: packageFilter } : {}),
        ...(search.trim() ? { search: search.trim() } : {}),
        per_page: 200,
      });
      setEntries(res.data);
      setTotal(res.meta.total);
    } catch (e) {
      toast.error("تعذر تحميل الطلبات", e instanceof Error ? e.message : undefined);
    } finally {
      setLoading(false);
    }
    // ⭐ `toast` هو الـ provider نفسه — مش سبب تاني نعيد التحميل
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [statusFilter, groupFilter, packageFilter, search]);

  /**
   * ⭐ تحميل واحد بيشغّل التحميل الأول كمان.
   *
   * كان في `useEffect` تاني بيطلب على المونت — فكان في **طلبين**
   * أول ما الصفحة تفتح. الـ timeout بتأخير شوية هو اللي بيتكفل
   * بالتحميل الأول، ومكانش محتاجين اتنين.
   *
   * ⭐ البحث بيتأخر ٣٥٠ مللي — من غيرها كل حرف كانت هتعمل طلب
   * للسيرفر، وكمان الصقل بيقطع الكلمة في النص.
   */
  useEffect(() => {
    const timer = setTimeout(load, 350);
    return () => clearTimeout(timer);
  }, [load]);

  useEffect(() => {
    let alive = true;

    (async () => {
      try {
        const [groupsRes, pricingRes] = await Promise.all([getGroups(), getPricing()]);

        if (!alive) return;

        setGroups(groupsRes.data.map((g) => ({ id: g.id, name: g.name })));

        // ⭐ الباقات من **جدول الأسعار** مش من الاشتراكات —
        // الاشتراكات اشتراكات الناس، والباقات اللي بنعرضها
        // للاختيار هي الكاتالوج نفسه.
        const flat: PackageOption[] = [];
        for (const group of pricingRes.groups as PricingGroup[]) {
          for (const p of group.plans) {
            flat.push({
              id: p.id,
              name: p.name,
              price: p.price,
              currency: p.currency,
              lessons_count: p.lessons_count,
              lesson_duration_minutes: p.lesson_duration_minutes,
              category_label: group.label,
            });
          }
        }
        setPackages(flat);
      } catch (e) {
        if (alive) console.error("فشل تحميل المجموعات والباقات:", e);
      }
    })();

    return () => {
      alive = false;
    };
  }, []);

  const packageById = useMemo(
    () => new Map(packages.map((p) => [p.id, p])),
    [packages],
  );

  // ⭐ الباقات مجمّعة بالتصنيف — ٣١ باقة في dropdown واحد
  // كانت هتبقى مستحيل يقرأها.
  const packagesByCategory = useMemo(() => {
    const groups_ = new Map<string, PackageOption[]>();
    for (const p of packages) {
      const list = groups_.get(p.category_label) ?? [];
      list.push(p);
      groups_.set(p.category_label, list);
    }
    return Array.from(groups_.entries());
  }, [packages]);

  function closeModal() {
    setShowModal(false);
    setEditing(null);
    setForm(EMPTY_FORM);
  }

  function openCreate() {
    setEditing(null);
    setForm(EMPTY_FORM);
    setShowModal(true);
  }

  function openEdit(entry: WaitlistEntry) {
    setEditing(entry);
    setForm({
      name: entry.name,
      phone: entry.phone,
      parent_phone: entry.parent_phone ?? "",
      current_level: entry.current_level ?? "",
      package_id: entry.package?.id ?? null,
      proposed_group_id: entry.proposed_group?.id ?? null,
      // ⭐ السطر اللي عليه `joined` مربوط بالمجموعة اللي دخلها —
      // مش بالمقترحة. لو غيّرنا ده علىEdit هنفصله عن reality.
      group_class_id: entry.group?.id ?? null,
      notes: entry.notes ?? "",
    });
    setShowModal(true);
  }

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setSubmitting(true);
    try {
      if (editing) {
        await updateWaitlistEntry(editing.id, form);
        toast.success("تم تعديل الطلب");
      } else {
        await createWaitlistEntry(form);
        toast.success("تم إضافة الطلب");
      }
      closeModal();
      load();
    } catch (e) {
      toast.error(editing ? "فشل التعديل" : "فشل الإضافة", e instanceof Error ? e.message : undefined);
    } finally {
      setSubmitting(false);
    }
  }

  async function handleAdmit(entry: WaitlistEntry) {
    const group = entry.group ?? entry.proposed_group;

    if (!group) {
      // ⭐ مش هنتركه يدخل بلا مجموعة — الأول نحدّد المجموعة
      toast.error("مفيش مجموعة محددة", "افتح «تعديل» واختار مجموعة أو مجموعة مقترحة الأول");
      openEdit(entry);
      return;
    }

    const ok = await confirm({
      title: "تأكيد الدخول",
      message:
        "هيتضاف «" + entry.name + "» لمجموعة «" + group.name + "».\n" +
        "هيتعمله: حساب طالب + عضوية + اشتراك شهري.",
      confirmLabel: "أدخله",
      cancelLabel: "إلغاء",
    });
    if (!ok) return;

    setBusyId(entry.id);
    try {
      await admitFromWaitlist(entry.id);
      toast.success("اتضاف الطالب للمجموعة واتعمل اشتراكه");
      load();
    } catch (e) {
      toast.error("فشل الدخول", e instanceof Error ? e.message : undefined);
    } finally {
      setBusyId(null);
    }
  }

  async function handleDelete(entry: WaitlistEntry) {
    const ok = await confirm({
      title: "حذف الطلب",
      message: "هيتحذف «" + entry.name + "» نهائيًا.\nمفيش تراجع — السطر مش هيفضل في السجلات.",
      confirmLabel: "احذف",
      cancelLabel: "إلغاء",
      tone: "danger",
    });
    if (!ok) return;

    setBusyId(entry.id);
    try {
      await deleteWaitlistEntry(entry.id);
      toast.success("تم الحذف");
      load();
    } catch (e) {
      toast.error("فشل الحذف", e instanceof Error ? e.message : undefined);
    } finally {
      setBusyId(null);
    }
  }

  const hasFilters = Boolean(statusFilter || groupFilter || packageFilter || search.trim());

  return (
    <div className="space-y-5">
      {/* ===== الترويسة ===== */}
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-lg font-semibold text-slate-800">قائمة الانتظار</h1>
          <p className="text-xs text-slate-500">
            {loading ? "جاري التحميل..." : "إجمالي " + total + " طلب"}
          </p>
        </div>
        <button
          onClick={openCreate}
          className="rounded-lg bg-slate-800 px-3 py-1.5 text-xs font-medium text-white transition hover:bg-slate-700"
        >
          + طلب جديد
        </button>
      </div>

      {/* ===== الفلاتر ===== */}
      <div className="flex flex-wrap items-center gap-2">
        <select
          value={statusFilter}
          onChange={(e) => setStatusFilter(e.target.value as WaitlistEntry["status"] | "")}
          className="rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs text-slate-700"
        >
          <option value="">كل الحالات</option>
          <option value="waiting">مستني</option>
          <option value="joined">دخل</option>
          <option value="declined">مرفوض</option>
        </select>

        <select
          value={groupFilter === "" ? "" : String(groupFilter)}
          onChange={(e) => setGroupFilter(e.target.value ? Number(e.target.value) : "")}
          className="rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs text-slate-700"
        >
          <option value="">كل المجموعات</option>
          {groups.map((g) => (
            <option key={g.id} value={String(g.id)}>
              {g.name}
            </option>
          ))}
        </select>

        <select
          value={packageFilter === "" ? "" : String(packageFilter)}
          onChange={(e) => setPackageFilter(e.target.value ? Number(e.target.value) : "")}
          className="rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs text-slate-700"
        >
          <option value="">كل الباقات</option>
          {packagesByCategory.map(([label, list]) => (
            <optgroup key={label} label={label}>
              {list.map((p) => (
                <option key={p.id} value={String(p.id)}>
                  {p.name}
                </option>
              ))}
            </optgroup>
          ))}
        </select>

        <input
          type="search"
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          placeholder="بحث بالاسم / الموبايل / ولي الأمر / المستوى..."
          className="min-w-[240px] flex-1 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs text-slate-700"
        />

        {hasFilters && (
          <button
            onClick={() => {
              setStatusFilter("");
              setGroupFilter("");
              setPackageFilter("");
              setSearch("");
            }}
            className="rounded-lg px-2 py-1.5 text-[11px] text-slate-500 underline hover:text-slate-800"
          >
            مسح الفلاتر
          </button>
        )}
      </div>

      {/* ===== الجدول ===== */}
      <div className="overflow-hidden rounded-xl border border-slate-200 bg-white">
        {loading ? (
          <div className="space-y-2 p-4">
            {[0, 1, 2, 3].map((i) => (
              <div key={i} className="h-11 animate-pulse rounded-lg bg-slate-100" />
            ))}
          </div>
        ) : entries.length === 0 ? (
          <p className="px-4 py-12 text-center text-sm text-slate-400">
            {hasFilters ? "مفيش طلبات مطابقة للفلاتر" : "مفيش طلبات في الانتظار"}
          </p>
        ) : (
          <div className="overflow-x-auto">
            {/* ⭐ `min-w` — من غيرها الجدول بيتزنق وعمود «الباقة»
                كان بيتكسر على ٤ أسطر («30 دقيقة - 4 حصص (تقليدي)»)
                والصف كله بيعلو. دلوقتي بيتزحلق أفقي بدل ما يتلخبط. */}
            <table className="w-full min-w-[980px] text-sm">
              <thead className="border-b border-slate-200 bg-slate-50 text-xs text-slate-500">
                <tr>
                  <th className="px-3 py-2.5 text-start">الترتيب</th>
                  <th className="px-3 py-2.5 text-start">الاسم</th>
                  <th className="px-3 py-2.5 text-start">الموبايل</th>
                  <th className="px-3 py-2.5 text-start">ولي الأمر</th>
                  <th className="px-3 py-2.5 text-start">المستوى الحالي</th>
                  <th className="px-3 py-2.5 text-start">الباقة</th>
                  <th className="px-3 py-2.5 text-start">المجموعة</th>
                  <th className="px-3 py-2.5 text-start">الحالة</th>
                  <th className="px-3 py-2.5 text-end">إجراءات</th>
                </tr>
              </thead>
              <tbody>
                {entries.map((entry) => {
                  const pkg = entry.package ? packageById.get(entry.package.id) : null;

                  return (
                    <tr key={entry.id} className="border-b border-slate-100 last:border-0 hover:bg-slate-50">
                      {/* ⭐ الرقم بيتحسب — لو مش مستني يبقى «—» مش 1 */}
                      <td className="px-3 py-2.5 text-start text-slate-500">
                        {entry.status === "waiting" && entry.position > 0 ? entry.position : "—"}
                      </td>

                      <td className="px-3 py-2.5 font-medium text-slate-800">
                        {entry.name}
                        {entry.notes && (
                          <span className="block max-w-[220px] truncate text-[11px] font-normal text-slate-400">
                            {entry.notes}
                          </span>
                        )}
                      </td>

                      {/* ⭐ `text-start` مش `text-left` — الأرقام
                          بتتراص لليمين عربي، لكن `dir=ltr` بيخلي
                          الرقم نفسه يقرأ صح */}
                      <td className="px-3 py-2.5 text-start whitespace-nowrap text-slate-600" dir="ltr">
                        {entry.phone}
                      </td>
                      <td className="px-3 py-2.5 text-start whitespace-nowrap text-slate-600" dir="ltr">
                        {text(entry.parent_phone)}
                      </td>
                      <td className="max-w-[200px] px-3 py-2.5 text-start text-slate-600">
                        <span className="line-clamp-2">{text(entry.current_level)}</span>
                      </td>

                      {/* ⭐ **اسم الباقة قصير** مش الكامل.
                          «30 دقيقة - 4 حصص (تقليدي)» بتبوظ العمود كله،
         * واللي الأدمن محتاج يشوفه في الجدول هو «كام حصة × كام دقيقة
                          وبكام» — الاسم الكامل بيبقى في الـ dropdown. */}
                      <td className="px-3 py-2.5 text-start whitespace-nowrap text-slate-600">
                        {pkg ? (
                          <>
                            <span className="block">
                              {pkg.category_label} —{" "}
                              {pkg.lessons_count === null ? "حصة مرنة" : pkg.lessons_count + " حصص"}
                            </span>
                            {/* ⭐ `money()` بتزوّد مسافة NBSP — من غيرها
                                الرقم ممكن يكسر سطر ويبقى تحته «ج.م» لوحده */}
                            <span className="block text-[11px] text-slate-400">
                              {pkg.lesson_duration_minutes} د · {money(pkg.price, pkg.currency)}
                            </span>
                          </>
                        ) : entry.package ? (
                          <span className="text-slate-400">{entry.package.name}</span>
                        ) : (
                          "—"
                        )}
                      </td>

                      <td className="px-3 py-2.5 text-start text-slate-600">
                        {text(entry.group?.name ?? entry.proposed_group?.name)}
                        {entry.group && entry.proposed_group && entry.group.id !== entry.proposed_group.id && (
                          <span className="block text-[11px] text-slate-400">
                            مقترحة: {entry.proposed_group.name}
                          </span>
                        )}
                      </td>

                      <td className="px-3 py-2.5">
                        <span className={`rounded-full px-2 py-0.5 text-[11px] font-medium ${STATUS_CLASSES[entry.status]}`}>
                          {STATUS_LABELS[entry.status]}
                        </span>
                      </td>

                      <td className="px-3 py-2.5">
                        <div className="flex items-center justify-end gap-1.5">
                          <button
                            onClick={() => openEdit(entry)}
                            disabled={busyId === entry.id}
                            className="rounded-lg border border-slate-200 px-2 py-1 text-[11px] text-slate-600 transition hover:bg-slate-50 disabled:opacity-50"
                          >
                            تعديل
                          </button>

                          {entry.status === "waiting" && (
                            <button
                              onClick={() => handleAdmit(entry)}
                              disabled={busyId === entry.id}
                              className="rounded-lg bg-emerald-600 px-2 py-1 text-[11px] font-medium text-white transition hover:bg-emerald-700 disabled:opacity-50"
                            >
                              أدخل
                            </button>
                          )}

                          <button
                            onClick={() => handleDelete(entry)}
                            disabled={busyId === entry.id}
                            className="rounded-lg border border-rose-200 px-2 py-1 text-[11px] text-rose-600 transition hover:bg-rose-50 disabled:opacity-50"
                          >
                            حذف
                          </button>
                        </div>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}
      </div>

      {/* ===== المودال ===== */}
      {showModal && (
        <div
          className="fixed inset-0 z-[100] flex items-center justify-center bg-slate-900/50 p-4"
          onClick={closeModal}
        >
          <div
            onClick={(e) => e.stopPropagation()}
            className="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-2xl bg-white shadow-2xl"
          >
            <div className="sticky top-0 z-10 flex items-center justify-between border-b border-slate-200 bg-white px-5 py-3.5">
              <h2 className="text-base font-semibold text-slate-800">
                {editing ? "تعديل طلب انتظار" : "طلب انتظار جديد"}
              </h2>
              <button
                onClick={closeModal}
                aria-label="اقفل"
                className="rounded-lg px-2 py-1 text-slate-400 transition hover:bg-slate-100"
              >
                ✕
              </button>
            </div>

            <form onSubmit={handleSubmit} className="space-y-4 p-5">
              <div>
                <label className="mb-1 block text-xs font-medium text-slate-600">
                  الاسم <span className="text-rose-500">*</span>
                </label>
                <input
                  value={form.name}
                  onChange={(e) => setForm({ ...form, name: e.target.value })}
                  required
                  placeholder="مثال: أحمد محمد"
                  className="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-800"
                />
              </div>

              <div className="grid gap-4 sm:grid-cols-2">
                <div>
                  <label className="mb-1 block text-xs font-medium text-slate-600">
                    الموبايل <span className="text-rose-500">*</span>
                  </label>
                  <input
                    value={form.phone}
                    onChange={(e) => setForm({ ...form, phone: e.target.value })}
                    required
                    inputMode="tel"
                    dir="ltr"
                    placeholder="01001234567"
                    className="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-800"
                  />
                </div>
                <div>
                  <label className="mb-1 block text-xs font-medium text-slate-600">
                    هاتف ولي الأمر
                  </label>
                  <input
                    value={form.parent_phone ?? ""}
                    onChange={(e) => setForm({ ...form, parent_phone: e.target.value })}
                    inputMode="tel"
                    dir="ltr"
                    placeholder="01112223333"
                    className="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-800"
                  />
                </div>
              </div>

              <div>
                <label className="mb-1 block text-xs font-medium text-slate-600">
                  المستوى الحالي
                  <span className="block text-[11px] font-normal text-slate-400">
                    بنستخدمه لما نفتح مجموعة جديدة ونجيب اللي على نفس المستوى
                  </span>
                </label>
                <input
                  value={form.current_level ?? ""}
                  onChange={(e) => setForm({ ...form, current_level: e.target.value })}
                  placeholder="مثال: متقن الجزء الخامس، يحفظ البقرة"
                  className="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-800"
                />
              </div>

              <div className="grid gap-4 sm:grid-cols-2">
                <div>
                  <label className="mb-1 block text-xs font-medium text-slate-600">
                    الباقة المطلوبة
                  </label>
                  <select
                    value={form.package_id ? String(form.package_id) : ""}
                    onChange={(e) =>
                      setForm({ ...form, package_id: e.target.value ? Number(e.target.value) : null })
                    }
                    className="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-800"
                  >
                    <option value="">— اختار باقة —</option>
                    {packagesByCategory.map(([label, list]) => (
                      <optgroup key={label} label={label}>
                        {list.map((p) => (
                          <option key={p.id} value={String(p.id)}>
                            {p.name} — {money(p.price, p.currency)}
                          </option>
                        ))}
                      </optgroup>
                    ))}
                  </select>
                </div>

                <div>
                  <label className="mb-1 block text-xs font-medium text-slate-600">
                    المجموعة المقترحة
                  </label>
                  <select
                    value={form.proposed_group_id ? String(form.proposed_group_id) : ""}
                    onChange={(e) =>
                      setForm({ ...form, proposed_group_id: e.target.value ? Number(e.target.value) : null })
                    }
                    className="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-800"
                  >
                    <option value="">— لسه محددش —</option>
                    {groups.map((g) => (
                      <option key={g.id} value={String(g.id)}>
                        {g.name}
                      </option>
                    ))}
                  </select>
                </div>
              </div>

              {editing && (
                <div>
                  <label className="mb-1 block text-xs font-medium text-slate-600">
                    المجموعة المرتبطة
                  </label>
                  <select
                    value={form.group_class_id ? String(form.group_class_id) : ""}
                    onChange={(e) =>
                      setForm({ ...form, group_class_id: e.target.value ? Number(e.target.value) : null })
                    }
                    className="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-800"
                  >
                    <option value="">— مفيش —</option>
                    {groups.map((g) => (
                      <option key={g.id} value={String(g.id)}>
                        {g.name}
                      </option>
                    ))}
                  </select>
                  <p className="mt-1 text-[11px] text-slate-400">
                    المجموعة اللي الطالب داخلها فعلاً. لو مش محددة، «أدخل» هيطلب مجموعة الأول.
                  </p>
                </div>
              )}

              <div>
                <label className="mb-1 block text-xs font-medium text-slate-600">ملاحظات</label>
                <textarea
                  value={form.notes ?? ""}
                  onChange={(e) => setForm({ ...form, notes: e.target.value })}
                  rows={3}
                  placeholder="أي حاجة تفيدنا نعرفها عنه"
                  className="w-full resize-none rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-800"
                />
              </div>

              <div className="flex justify-end gap-2 border-t border-slate-100 pt-4">
                <button
                  type="button"
                  onClick={closeModal}
                  className="rounded-lg border border-slate-200 px-4 py-2 text-sm text-slate-600 transition hover:bg-slate-50"
                >
                  إلغاء
                </button>
                <button
                  type="submit"
                  disabled={submitting}
                  className="rounded-lg bg-slate-800 px-4 py-2 text-sm font-medium text-white transition hover:bg-slate-700 disabled:opacity-50"
                >
                  {submitting ? "جاري الحفظ..." : editing ? "حفظ التعديلات" : "إضافة الطلب"}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
}