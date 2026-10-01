"use client";

import { useEffect, useState } from "react";
import { apiFetch } from "@/lib/api";

type Employee = {
  id: number;
  name: string;
  email: string;
  phone: string;
  job_title: string;
  department: string;
  status: "active" | "inactive" | "on_leave";
  branch?: { id: number; name: string };
};

const DEPARTMENTS = ["Operations", "Academic", "Finance", "Marketing", "IT", "HR", "Sales"];
const JOB_TITLES = ["مدير", "مشرف أكاديمي", "خدمة عملاء", "استقبال", "محاسب", "مصمم", "مسؤول تسويق", "دعم فني"];

export default function EmployeesPage() {
  const [employees, setEmployees] = useState<Employee[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [showForm, setShowForm] = useState(false);
  const [form, setForm] = useState({ name: "", email: "", phone: "", job_title: "", department: "", status: "active" as Employee["status"] });

  function loadEmployees() {
    setLoading(true);
    apiFetch<{ data: Employee[] }>("/employees")
      .then((r) => setEmployees(r.data))
      .catch((err) => setError(err instanceof Error ? err.message : "تعذر تحميل البيانات"))
      .finally(() => setLoading(false));
  }

  useEffect(() => { loadEmployees(); }, []);

  async function handleCreate(e: React.FormEvent) {
    e.preventDefault();
    try {
      await apiFetch("/employees", { method: "POST", body: JSON.stringify(form) });
      setShowForm(false);
      setForm({ name: "", email: "", phone: "", job_title: "", department: "", status: "active" });
      loadEmployees();
    } catch (err) { setError(err instanceof Error ? err.message : "فشل الإنشاء"); }
  }

  const activeCount = employees.filter((e) => e.status === "active").length;

  return (
    <div>
      <div className="mb-6 flex items-center justify-between">
        <h1 className="text-lg font-semibold text-slate-800">الموظفين</h1>
        <button onClick={() => setShowForm(!showForm)} className="rounded-lg bg-slate-800 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700">
          {showForm ? "إخفاء" : "+ إضافة موظف"}
        </button>
      </div>

      <div className="mb-4 flex gap-4 text-sm">
        <span className="rounded-lg bg-slate-100 px-3 py-1">{employees.length} موظف</span>
        <span className="rounded-lg bg-green-100 px-3 py-1 text-green-700">{activeCount} نشط</span>
        <span className="rounded-lg bg-amber-100 px-3 py-1 text-amber-700">{employees.length - activeCount} غير نشط</span>
      </div>

      {loading && <p className="text-sm text-slate-500">جارٍ التحميل...</p>}
      {error && <p className="mb-4 text-sm text-red-600">{error}</p>}

      {showForm && (
        <div className="mb-8 rounded-xl border border-slate-200 bg-white p-5">
          <h2 className="mb-4 text-sm font-semibold text-slate-800">إضافة موظف</h2>
          <form onSubmit={handleCreate} className="grid grid-cols-2 gap-3">
            <input placeholder="الاسم" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} className="rounded-lg border border-slate-300 px-3 py-2 text-sm" required />
            <input placeholder="البريد الإلكتروني" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} className="rounded-lg border border-slate-300 px-3 py-2 text-sm" required />
            <input placeholder="الهاتف" value={form.phone} onChange={(e) => setForm({ ...form, phone: e.target.value })} className="rounded-lg border border-slate-300 px-3 py-2 text-sm" />
            <select value={form.job_title} onChange={(e) => setForm({ ...form, job_title: e.target.value })} className="rounded-lg border border-slate-300 px-3 py-2 text-sm" required>
              <option value="">المسمى الوظيفي</option>
              {JOB_TITLES.map((jt) => <option key={jt} value={jt}>{jt}</option>)}
            </select>
            <select value={form.department} onChange={(e) => setForm({ ...form, department: e.target.value })} className="rounded-lg border border-slate-300 px-3 py-2 text-sm" required>
              <option value="">القسم</option>
              {DEPARTMENTS.map((d) => <option key={d} value={d}>{d}</option>)}
            </select>
            <select value={form.status} onChange={(e) => setForm({ ...form, status: e.target.value as Employee["status"] })} className="rounded-lg border border-slate-300 px-3 py-2 text-sm">
              <option value="active">نشط</option>
              <option value="inactive">غير نشط</option>
              <option value="on_leave">إجازة</option>
            </select>
            <button type="submit" className="col-span-2 rounded-lg bg-slate-800 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700">حفظ</button>
          </form>
        </div>
      )}

      {!loading && !error && (
        <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white">
          <table className="w-full text-sm">
            <thead className="border-b border-slate-200 bg-slate-50 text-slate-500">
              <tr>
                <th className="px-4 py-3 text-right">الاسم</th>
                <th className="px-4 py-3 text-right">البريد</th>
                <th className="px-4 py-3 text-right">الهاتف</th>
                <th className="px-4 py-3 text-right">المسمى الوظيفي</th>
                <th className="px-4 py-3 text-right">القسم</th>
                <th className="px-4 py-3 text-right">الحالة</th>
              </tr>
            </thead>
            <tbody>
              {employees.length === 0 && <tr><td colSpan={6} className="px-4 py-6 text-center text-slate-400">لا يوجد موظفين</td></tr>}
              {employees.map((emp) => (
                <tr key={emp.id} className="border-b border-slate-100 last:border-0">
                  <td className="px-4 py-3 font-medium text-slate-800">{emp.name}</td>
                  <td className="px-4 py-3 text-slate-600">{emp.email}</td>
                  <td className="px-4 py-3 text-slate-600" dir="ltr" style={{ textAlign: "right" }}>{emp.phone}</td>
                  <td className="px-4 py-3 text-slate-600">{emp.job_title}</td>
                  <td className="px-4 py-3 text-slate-600">{emp.department}</td>
                  <td className="px-4 py-3">
                    <span className={`rounded-full px-2 py-1 text-xs ${emp.status === "active" ? "bg-green-100 text-green-700" : emp.status === "on_leave" ? "bg-amber-100 text-amber-700" : "bg-red-100 text-red-700"}`}>
                      {emp.status === "active" ? "نشط" : emp.status === "on_leave" ? "إجازة" : "غير نشط"}
                    </span>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}
