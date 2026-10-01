"use client";

import { useCallback, useEffect, useState } from "react";
import {
  apiFetch,
  parentRelationshipLabel,
  PARENT_RELATIONSHIPS,
  type Paginated,
} from "@/lib/api";
import Pagination from "@/components/Pagination";

type StudentLite = {
  id: number;
  first_name: string;
  middle_name: string | null;
  last_name: string;
  student_code: string;
  full_name?: string;
};

type Parent = {
  id: number;
  name: string;
  phone: string;
  email: string | null;
  country_code: string | null;
  status: "active" | "inactive";
  students_count: number;
  students?: StudentLite[];
  relationships?: string[];
};

const PAGE_SIZE = 60;

export default function ParentsPage() {
  const [parents, setParents] = useState<Parent[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const [page, setPage] = useState(1);
  const [meta, setMeta] = useState({ total: 0, last_page: 1 });
  const [statusCounts, setStatusCounts] = useState<Record<string, number>>({});

  const [search, setSearch] = useState("");
  const [statusFilter, setStatusFilter] = useState("");
  const [relationshipFilter, setRelationshipFilter] = useState("");
  const [showForm, setShowForm] = useState(false);
  const [expandedId, setExpandedId] = useState<number | null>(null);

  const [form, setForm] = useState({ name: "", phone: "", email: "", relationship: "mother" });
  const [saving, setSaving] = useState(false);

  const load = useCallback(
    async (targetPage = 1) => {
      setLoading(true);
      setError(null);
      try {
        const params = new URLSearchParams();
        params.set("per_page", String(PAGE_SIZE));
        params.set("page", String(targetPage));
        if (statusFilter) params.set("status", statusFilter);
        if (search.trim()) params.set("search", search.trim());

        const r = await apiFetch<Paginated<Parent>>(`/parents?${params.toString()}`);
        setParents(r.data);
        setMeta({ total: r.total, last_page: r.last_page });
        const c = r.counts as Record<string, unknown> | undefined;
        const st = c?.status;
        setStatusCounts((st && typeof st === "object" ? st : {}) as Record<string, number>);
      } catch (err) {
        setError(err instanceof Error ? err.message : "تعذر تحميل البيانات");
      } finally {
        setLoading(false);
      }
    },
    [search, statusFilter],
  );

  useEffect(() => {
    const t = setTimeout(() => {
      setPage(1);
      load(1);
    }, 300);
    return () => clearTimeout(t);
  }, [load]);

  function goToPage(target: number) {
    if (target < 1 || target > meta.last_page || target === page) return;
    setPage(target);
    load(target);
    window.scrollTo({ top: 0, behavior: "smooth" });
  }

  async function handleCreate(e: React.FormEvent) {
    e.preventDefault();
    setSaving(true);
    setError(null);
    try {
      await apiFetch("/parents", {
        method: "POST",
        body: JSON.stringify({
          name: form.name.trim(),
          phone: form.phone.trim(),
          email: form.email.trim() || undefined,
          country_code: "+20",
          relationship: form.relationship,
        }),
      });
      setShowForm(false);
      setForm({ name: "", phone: "", email: "", relationship: "mother" });
      load(1);
    } catch (err) {
      setError(err instanceof Error ? err.message : "فشل الإضافة");
    } finally {
      setSaving(false);
    }
  }

  // فلترة محلية بالعلاقة (العلاقة مش مدعومة في الفلاتر على الـ API)
  const visible = relationshipFilter
    ? parents.filter((p) => p.relationships?.includes(relationshipFilter))
    : parents;

  const activeCount = statusCounts.active ?? 0;
  const inactiveCount = statusCounts.inactive ?? 0;

  return (
    <div>
      <div className="mb-5 flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-lg font-semibold text-slate-800">أولياء الأمور</h1>
          <p className="text-sm text-slate-500">
            بيتسجلوا تلقائياً من أرقام الهاتف المرتبطة بالطلاب
          </p>
        </div>
        <button
          onClick={() => setShowForm((v) => !v)}
          className="rounded-lg bg-slate-800 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700"
        >
          {showForm ? "إخفاء" : "+ إضافة يدوياً"}
        </button>
      </div>

      {/* إحصائيات */}
      <div className="mb-5 grid grid-cols-2 gap-3 md:grid-cols-4">
        <div className="rounded-xl bg-slate-800 p-4 text-center text-white">
          <p className="text-xs text-slate-300">إجمالي أولياء الأمور</p>
          <p className="mt-1 text-xl font-bold">{meta.total}</p>
        </div>
        <div className="rounded-xl bg-green-50 p-4 text-center">
          <p className="text-xs text-green-600">نشط</p>
          <p className="mt-1 text-xl font-bold text-green-800">{activeCount}</p>
        </div>
        <div className="rounded-xl bg-red-50 p-4 text-center">
          <p className="text-xs text-red-600">غير نشط</p>
          <p className="mt-1 text-xl font-bold text-red-800">{inactiveCount}</p>
        </div>
        <div className="rounded-xl bg-blue-50 p-4 text-center">
          <p className="text-xs text-blue-600">في الصفحة دي</p>
          <p className="mt-1 text-xl font-bold text-blue-800">
            {parents.filter((p) => p.students_count > 1).length}
            <span className="text-xs font-normal"> من {parents.length}</span>
          </p>
        </div>
      </div>

      {/* البحث والفلاتر */}
      <div className="mb-4 flex flex-wrap gap-2">
        <input
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          placeholder="🔎 ابحث بالاسم أو الهاتف أو البريد..."
          className="min-w-[220px] flex-1 rounded-lg border border-slate-300 px-3 py-2 text-sm outline-none focus:border-slate-500"
        />
        <select
          value={statusFilter}
          onChange={(e) => setStatusFilter(e.target.value)}
          className="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-700"
        >
          <option value="">كل الحالات</option>
          <option value="active">نشط</option>
          <option value="inactive">غير نشط</option>
        </select>
        <select
          value={relationshipFilter}
          onChange={(e) => setRelationshipFilter(e.target.value)}
          className="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-700"
        >
          <option value="">كل صلات القرابة</option>
          {PARENT_RELATIONSHIPS.map((r) => (
            <option key={r.value} value={r.value}>{r.label}</option>
          ))}
        </select>
      </div>

      {error && (
        <div className="mb-4 flex items-center justify-between rounded-lg bg-red-50 px-4 py-2.5">
          <p className="text-sm text-red-700">{error}</p>
          <button onClick={() => load(page)} className="text-sm font-medium text-red-700 underline">
            إعادة المحاولة
          </button>
        </div>
      )}

      {/* نموذج الإضافة اليدوية */}
      {showForm && (
        <form
          onSubmit={handleCreate}
          className="mb-5 rounded-xl border border-slate-200 bg-white p-5"
        >
          <h2 className="mb-3 text-sm font-semibold text-slate-800">
            إضافة ولي أمر يدوياً
          </h2>
          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <input
              required
              placeholder="الاسم *"
              value={form.name}
              onChange={(e) => setForm({ ...form, name: e.target.value })}
              className="rounded-lg border border-slate-300 px-3 py-2 text-sm"
            />
            <input
              required
              placeholder="رقم الهاتف *"
              value={form.phone}
              onChange={(e) => setForm({ ...form, phone: e.target.value })}
              className="rounded-lg border border-slate-300 px-3 py-2 text-sm"
              dir="ltr"
            />
            <input
              type="email"
              placeholder="البريد الإلكتروني"
              value={form.email}
              onChange={(e) => setForm({ ...form, email: e.target.value })}
              className="rounded-lg border border-slate-300 px-3 py-2 text-sm"
            />
            <select
              value={form.relationship}
              onChange={(e) => setForm({ ...form, relationship: e.target.value })}
              className="rounded-lg border border-slate-300 px-3 py-2 text-sm"
            >
              {PARENT_RELATIONSHIPS.map((r) => (
                <option key={r.value} value={r.value}>{r.label}</option>
              ))}
            </select>
          </div>
          <div className="mt-3 flex gap-2">
            <button
              type="submit"
              disabled={saving}
              className="rounded-lg bg-slate-800 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700 disabled:opacity-60"
            >
              {saving ? "جارٍ الحفظ..." : "حفظ"}
            </button>
            <button
              type="button"
              onClick={() => setShowForm(false)}
              className="rounded-lg bg-slate-100 px-4 py-2 text-sm text-slate-600 hover:bg-slate-200"
            >
              إلغاء
            </button>
          </div>
          <p className="mt-2 text-xs text-slate-400">
            ملاحظة: الأولاد بيتربطوا من صفحة الطالب (أرقام الهاتف) — عشان كده relations
            بتظهر في نفس السجل.
          </p>
        </form>
      )}

      {loading ? (
        <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
          {[0, 1, 2, 3, 4, 5].map((i) => (
            <div key={i} className="h-28 animate-pulse rounded-xl bg-slate-100" />
          ))}
        </div>
      ) : visible.length === 0 ? (
        <div className="rounded-xl border border-dashed border-slate-300 py-16 text-center">
          <p className="text-4xl">👨‍👩‍👧</p>
          <p className="mt-3 text-sm font-medium text-slate-600">
            {search || statusFilter || relationshipFilter
              ? "مفيش نتائج مطابقة"
              : "لسه مفيش أولياء أمر"}
          </p>
          <p className="mt-1 text-xs text-slate-400">
            أضف رقم ولي أمر من صفحة الطالب هيتسجل هنا تلقائياً
          </p>
        </div>
      ) : (
        <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
          {visible.map((p) => {
            const isOpen = expandedId === p.id;
            return (
              <div
                key={p.id}
                className="rounded-xl border border-slate-200 bg-white p-4 transition hover:shadow-sm"
              >
                <div className="mb-2 flex items-start justify-between gap-2">
                  <div className="min-w-0">
                    <h3 className="truncate font-medium text-slate-800">{p.name}</h3>
                    <p className="text-sm text-slate-500" dir="ltr" style={{ textAlign: "right" }}>
                      {p.phone}
                    </p>
                  </div>
                  <span
                    className={`shrink-0 rounded-full px-2 py-0.5 text-[11px] ${
                      p.status === "active"
                        ? "bg-green-100 text-green-700"
                        : "bg-red-100 text-red-700"
                    }`}
                  >
                    {p.status === "active" ? "نشط" : "غير نشط"}
                  </span>
                </div>

                {p.email && <p className="truncate text-xs text-slate-400">{p.email}</p>}

                {/* صلات القرابة */}
                {p.relationships && p.relationships.length > 0 && (
                  <div className="mt-2 flex flex-wrap gap-1">
                    {p.relationships.map((r) => (
                      <span
                        key={r}
                        className="rounded-full bg-purple-50 px-2 py-0.5 text-[11px] text-purple-700"
                      >
                        {parentRelationshipLabel(r)}
                      </span>
                    ))}
                  </div>
                )}

                {/* عدد الطلاب */}
                <div className="mt-2 border-t border-slate-100 pt-2">
                  <button
                    onClick={() => setExpandedId(isOpen ? null : p.id)}
                    className="flex w-full items-center justify-between text-xs text-slate-500 hover:text-slate-700"
                  >
                    <span>
                      {p.students_count} طالب
                      {p.students_count > 1 && (
                        <span className="ms-1 text-blue-600">(أكثر من طالب)</span>
                      )}
                    </span>
                    <span className="text-slate-400">{isOpen ? "▲" : "▼"}</span>
                  </button>

                  {isOpen && (
                    <ul className="mt-2 space-y-1">
                      {(p.students ?? []).map((s) => (
                        <li
                          key={s.id}
                          className="flex items-center justify-between rounded bg-slate-50 px-2 py-1 text-xs"
                        >
                          <span className="text-slate-700">
                            {s.full_name ??
                              `${s.first_name} ${s.middle_name ?? ""} ${s.last_name}`.trim()}
                          </span>
                          <span className="text-slate-400">{s.student_code}</span>
                        </li>
                      ))}
                    </ul>
                  )}
                </div>
              </div>
            );
          })}
        </div>
      )}

      <Pagination
        page={page}
        lastPage={meta.last_page}
        total={meta.total}
        perPage={PAGE_SIZE}
        onChange={goToPage}
        loading={loading}
        itemLabel="ولي أمر"
      />
    </div>
  );
}