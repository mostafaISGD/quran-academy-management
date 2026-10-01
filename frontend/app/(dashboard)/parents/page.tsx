"use client";

import { useEffect, useState } from "react";
import { apiFetch } from "@/lib/api";

type Parent = {
  id: number;
  name: string;
  phone: string;
  email: string | null;
  country_code: string | null;
  status: "active" | "inactive";
  students?: { id: number; full_name: string }[];
};

export default function ParentsPage() {
  const [parents, setParents] = useState<Parent[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  function loadParents() {
    setLoading(true);
    apiFetch<{ data: Parent[] }>("/parents")
      .then((r) => setParents(r.data))
      .catch((err) => setError(err instanceof Error ? err.message : "تعذر تحميل البيانات"))
      .finally(() => setLoading(false));
  }

  useEffect(() => { loadParents(); }, []);

  return (
    <div>
      <h1 className="mb-6 text-lg font-semibold text-slate-800">أولياء الأمور</h1>
      {loading && <p className="text-sm text-slate-500">جارٍ التحميل...</p>}
      {error && <p className="mb-4 text-sm text-red-600">{error}</p>}
      {!loading && !error && (
        <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
          {parents.length === 0 && <p className="text-slate-400">لا يوجد أولياء أمور</p>}
          {parents.map((p) => (
            <div key={p.id} className="rounded-xl border border-slate-200 bg-white p-4">
              <div className="mb-2 flex items-center justify-between">
                <h3 className="font-medium text-slate-800">{p.name}</h3>
                <span className={`rounded-full px-2 py-1 text-xs ${p.status === "active" ? "bg-green-100 text-green-700" : "bg-red-100 text-red-700"}`}>
                  {p.status === "active" ? "نشط" : "غير نشط"}
                </span>
              </div>
              <p className="text-sm text-slate-600" dir="ltr" style={{ textAlign: "right" }}>{p.phone}</p>
              <p className="text-sm text-slate-600">{p.email ?? "—"}</p>
              {p.students && p.students.length > 0 && (
                <div className="mt-2 border-t border-slate-100 pt-2">
                  <p className="text-xs text-slate-500">الأبناء:</p>
                  {p.students.map((s) => <p key={s.id} className="text-xs text-slate-600">{s.full_name}</p>)}
                </div>
              )}
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
