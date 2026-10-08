"use client";

import { useEffect, useMemo, useState } from "react";
import {
  createWaitlistEntry,
  deleteWaitlistEntry,
  getWaitlist,
  updateWaitlistEntry,
  admitFromWaitlist,
  getGroups,
  getSubscriptions,
  type WaitlistEntry,
  type WaitlistPayload,
} from "@/lib/api";
import { useUI } from "@/components/ui";

const WEEKDAY_LABELS = ["الأحد", "الإثنين", "الثلاثاء", "الأربعاء", "الخميس", "الجمعة", "السبت"];

interface PackageOption {
  id: number;
  name: string;
  price: string;
  lessons_count: number | null;
  lesson_duration_minutes: number;
}

interface GroupOption {
  id: number;
  name: string;
}

export default function WaitlistPage() {
  const { toast, confirm, prompt } = useUI();

  const [entries, setEntries] = useState<WaitlistEntry[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  // Filters
  const [statusFilter, setStatusFilter] = useState<"waiting" | "joined" | "declined" | "">("");
  const [groupFilter, setGroupFilter] = useState<number | "">("");
  const [packageFilter, setPackageFilter] = useState<number | "">("");
  const [search, setSearch] = useState("");

  // Filter data
  const [groups, setGroups] = useState<GroupOption[]>([]);
  const [packages, setPackages] = useState<PackageOption[]>([]);

  // Modal
  const [showModal, setShowModal] = useState(false);
  const [editingEntry, setEditingEntry] = useState<WaitlistEntry | null>(null);
  const [submitting, setSubmitting] = useState(false);

  const [formData, setFormData] = useState<WaitlistPayload>({
    name: "",
    phone: "",
    parent_phone: "",
    current_level: "",
    package_id: null,
    proposed_group_id: null,
    group_class_id: null,
    notes: "",
  });

  // Load data
  useEffect(() => {
    loadData();
  }, []);

  useEffect(() => {
    loadFilters();
  }, []);

  async function loadData() {
    setLoading(true);
    setError(null);
    try {
      const params: Record<string, string | number> = { per_page: 100 };
      if (statusFilter) params.status = statusFilter;
      if (groupFilter) params.group_id = groupFilter;
      if (packageFilter) params.package_id = packageFilter;
      if (search) params.search = search;
      if (search) delete params.group_id; // general search

      const res = await getWaitlist(params);
      setEntries(res.data);
    } catch (e) {
      setError(e instanceof Error ? e.message : "Failed to load data");
      toast.error("Failed to load", e instanceof Error ? e.message : undefined);
    } finally {
      setLoading(false);
    }
  }

  async function loadFilters() {
    try {
      const [groupsRes, subsRes] = await Promise.all([
        getGroups(),
        getSubscriptions({ status: "active", per_page: 200 }),
      ]);
      setGroups(groupsRes.data.map((g) => ({ id: g.id, name: g.name })));
      setPackages(
        subsRes.data
          .filter((p) => p.status === "active")
          .map((p) => ({
            id: p.plan_id ?? p.id,
            name: p.plan?.name ?? "Package #" + p.id,
            price: p.price,
            lessons_count: p.lessons_included,
            lesson_duration_minutes: p.lesson_duration_minutes,
          }))
      );
    } catch (e) {
      console.error("Failed to load filters:", e);
    }
  }

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setSubmitting(true);
    try {
      if (editingEntry) {
        await updateWaitlistEntry(editingEntry.id, formData);
        toast.success("Waitlist entry updated");
      } else {
        await createWaitlistEntry(formData);
        toast.success("Waitlist entry added");
      }
      setShowModal(false);
      setEditingEntry(null);
      resetForm();
      loadData();
    } catch (e) {
      toast.error(editingEntry ? "Update failed" : "Create failed", e instanceof Error ? e.message : undefined);
    } finally {
      setSubmitting(false);
    }
  }

  function resetForm() {
    setFormData({
      name: "",
      phone: "",
      parent_phone: "",
      current_level: "",
      package_id: null,
      proposed_group_id: null,
      group_class_id: null,
      notes: "",
    });
  }

  function openCreateModal() {
    setEditingEntry(null);
    resetForm();
    setShowModal(true);
  }

  function openEditModal(entry: WaitlistEntry) {
    setEditingEntry(entry);
    setFormData({
      name: entry.name,
      phone: entry.phone,
      parent_phone: entry.parent_phone || "",
      current_level: entry.current_level || "",
      package_id: entry.package?.id ?? null,
      proposed_group_id: entry.proposed_group?.id ?? null,
      group_class_id: entry.group?.id ?? null,
      notes: entry.notes || "",
    });
    setShowModal(true);
  }

  async function handleAdmit(entry: WaitlistEntry) {
    const ok = await confirm({
      title: "Confirm Admit",
      message: "Confirm admitting " + entry.name + " to the group?\nThis will create student + membership + monthly subscription.",
      confirmLabel: "Admit",
      cancelLabel: "Cancel",
    });
    if (!ok) return;

    try {
      await admitFromWaitlist(entry.id);
      toast.success("Student admitted and subscription created");
      loadData();
    } catch (e) {
      toast.error("Admission failed", e instanceof Error ? e.message : undefined);
    }
  }

  async function handleDelete(entry: WaitlistEntry) {
    const ok = await confirm({
      title: "Delete Waitlist Entry",
      message: "Delete waitlist request for " + entry.name + "?\nThis will permanently delete it from the database.",
      confirmLabel: "Delete",
      cancelLabel: "Cancel",
      tone: "danger",
    });
    if (!ok) return;

    try {
      await deleteWaitlistEntry(entry.id);
      toast.success("Deleted");
      loadData();
    } catch (e) {
      toast.error("Delete failed", e instanceof Error ? e.message : undefined);
    }
  }

  const statusLabels: Record<string, string> = {
    waiting: "Waiting",
    joined: "Joined",
    declined: "Declined",
  };

  const statusColors: Record<string, string> = {
    waiting: "bg-amber-100 text-amber-700",
    joined: "bg-emerald-100 text-emerald-700",
    declined: "bg-rose-100 text-rose-700",
  };

  if (loading) {
    return (
      <div className="space-y-2">
        {[0, 1, 2].map((i) => (
          <div key={i} className="h-20 animate-pulse rounded-xl bg-slate-100" />
        ))}
      </div>
    );
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-lg font-semibold text-slate-800">Waitlist Management</h1>
          <p className="text-xs text-slate-500">
            Manage waitlist requests independent of groups
          </p>
        </div>
        <button
          onClick={openCreateModal}
          className="rounded-lg bg-slate-800 px-3 py-1.5 text-xs font-medium text-white transition hover:bg-slate-700"
        >
          + New Waitlist
        </button>
      </div>

      {/* Filters */}
      <div className="flex flex-wrap gap-2">
        <select
          value={statusFilter}
          onChange={(e) => setStatusFilter(e.target.value as "waiting" | "joined" | "declined" | "")}
          className="rounded-lg border border-slate-200 px-3 py-2 text-sm"
        >
          <option value="">All Statuses</option>
          <option value="waiting">Waiting</option>
          <option value="joined">Joined</option>
          <option value="declined">Declined</option>
        </select>

        <select
          value={groupFilter}
          onChange={(e) => setGroupFilter(e.target.value ? Number(e.target.value) : "")}
          className="rounded-lg border border-slate-200 px-3 py-2 text-sm"
        >
          <option value="">All Groups</option>
          {groups.map((g) => (
            <option key={g.id} value={String(g.id)}>
              {g.name}
            </option>
          ))}
        </select>

        <select
          value={packageFilter}
          onChange={(e) => setPackageFilter(e.target.value ? Number(e.target.value) : "")}
          className="rounded-lg border border-slate-200 px-3 py-2 text-sm"
        >
          <option value="">All Packages</option>
          {packages.map((p) => (
            <option key={p.id} value={String(p.id)}>
              {p.name}
            </option>
          ))}
        </select>

        <div className="flex-1 min-w-[200px]">
          <input
            type="text"
            placeholder="Search by name / phone / level..."
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            className="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:border-slate-400 focus:outline-none"
          />
        </div>
      </div>

      {/* Table */}
      <div className="rounded-xl border border-slate-200 bg-white overflow-hidden">
        {entries.length === 0 ? (
          <p className="px-4 py-8 text-center text-slate-400">No waitlist entries</p>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="border-b border-slate-200 bg-slate-50 text-slate-500">
                <tr>
                  <th className="px-4 py-3 text-start">#</th>
                  <th className="px-4 py-3 text-start">Name</th>
                  <th className="px-4 py-3 text-start">Phone</th>
                  <th className="px-4 py-3 text-start">Guardian Phone</th>
                  <th className="px-4 py-3 text-start">Current Level</th>
                  <th className="px-4 py-3 text-start">Package</th>
                  <th className="px-4 py-3 text-start">Proposed Group</th>
                  <th className="px-4 py-3 text-start">Status</th>
                  <th className="px-4 py-3 text-end">Actions</th>
                </tr>
              </thead>
              <tbody>
                {entries.map((entry) => (
                  <tr key={entry.id} className="border-b border-slate-100 last:border-0">
                    <td className="px-4 py-3 text-slate-500">{entry.position || "—"}</td>
                    <td className="px-4 py-3 font-medium text-slate-800">{entry.name}</td>
                    <td className="px-4 py-3 text-slate-600" dir="ltr">{entry.phone}</td>
                    <td className="px-4 py-3 text-slate-600" dir="ltr">{entry.parent_phone || "—"}</td>
                    <td className="px-4 py-3 text-slate-600">{entry.current_level || "—"}</td>
                    <td className="px-4 py-3 text-slate-600">{entry.package?.name || "—"}</td>
                    <td className="px-4 py-3 text-slate-600">{entry.proposed_group?.name || "—"}</td>
                    <td className="px-4 py-3">
                      <span className={`rounded-full px-2 py-0.5 text-[11px] font-medium ${statusColors[entry.status]}`}>
                        {statusLabels[entry.status]}
                      </span>
                    </td>
                    <td className="px-4 py-3 text-end">
                      <div className="flex items-center justify-end gap-1.5">
                        <button
                          onClick={() => openEditModal(entry)}
                          className="rounded-lg border border-slate-200 px-2.5 py-1 text-[11px] text-slate-600 transition hover:bg-slate-50"
                        >
                          Edit
                        </button>
                        {entry.status === "waiting" && (
                          <button
                            onClick={() => handleAdmit(entry)}
                            className="rounded-lg bg-emerald-600 px-2.5 py-1 text-[11px] font-medium text-white transition hover:bg-emerald-700"
                          >
                            Admit
                          </button>
                        )}
                        <button
                          onClick={() => handleDelete(entry)}
                          className="rounded-lg border border-rose-200 px-2.5 py-1 text-[11px] text-rose-600 transition hover:bg-rose-50"
                        >
                          Delete
                        </button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      {/* Add/Edit Modal */}
      {showModal && (
        <div className="fixed inset-0 z-[100] flex items-center justify-center p-4 bg-slate-900/50">
          <div className="w-full max-w-2xl rounded-2xl bg-white shadow-2xl max-h-[90vh] overflow-y-auto">
            <div className="flex items-center justify-between border-b border-slate-200 px-5 py-4">
              <h2 className="text-base font-semibold text-slate-800">
                {editingEntry ? "Edit Waitlist Entry" : "Add Waitlist Entry"}
              </h2>
              <button
                onClick={() => { setShowModal(false); setEditingEntry(null); resetForm(); }}
                className="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 transition"
              >
                \u2715
              </button>
            </div>

            <form onSubmit={handleSubmit} className="p-5 space-y-4">
              <div>
                <label className="mb-1 block text-[11px] font-medium text-slate-600">Name *</label>
                <input
                  value={formData.name}
                  onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                  placeholder="e.g. Ahmed Mohamed"
                  className="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:border-slate-400 focus:outline-none"
                  required
                />
              </div>

              <div className="grid gap-4 sm:grid-cols-2">
                <div>
                  <label className="mb-1 block text-[11px] font-medium text-slate-600">Phone *</label>
                  <input
                    value={formData.phone}
                    onChange={(e) => setFormData({ ...formData, phone: e.target.value })}
                    placeholder="e.g. 01001234567"
                    dir="ltr"
                    inputMode="tel"
                    className="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:border-slate-400 focus:outline-none"
                    required
                  />
                </div>
                <div>
                  <label className="mb-1 block text-[11px] font-medium text-slate-600">Guardian Phone</label>
                  <input
                    value={formData.parent_phone ?? ""}
                    onChange={(e) => setFormData({ ...formData, parent_phone: e.target.value })}
                    placeholder="e.g. 01112223333"
                    dir="ltr"
                    inputMode="tel"
                    className="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:border-slate-400 focus:outline-none"
                  />
                </div>
              </div>

              <div>
                <label className="mb-1 block text-[11px] font-medium text-slate-600">Current Level</label>
                <input
                  value={formData.current_level ?? ""}
                  onChange={(e) => setFormData({ ...formData, current_level: e.target.value })}
                  placeholder="e.g. Memorized Juz 5, Beginner, memorizes Al-Baqarah..."
                  className="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:border-slate-400 focus:outline-none"
                />
              </div>

              <div className="grid gap-4 sm:grid-cols-2">
                <div>
                  <label className="mb-1 block text-[11px] font-medium text-slate-600">Required Package</label>
                  <select
                    value={String(formData.package_id ?? "")}
                    onChange={(e) => setFormData({ ...formData, package_id: e.target.value ? Number(e.target.value) : null })}
                    className="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:border-slate-400 focus:outline-none"
                  >
                    <option value="">— Select Package —</option>
                    {packages.map((p) => (
                      <option key={p.id} value={String(p.id)}>
                        {p.name} - {p.price} EGP ({p.lessons_count ?? "flexible"} lessons x {p.lesson_duration_minutes}min)
                      </option>
                    ))}
                  </select>
                </div>
                <div>
                  <label className="mb-1 block text-[11px] font-medium text-slate-600">Proposed Group</label>
                  <select
                    value={String(formData.proposed_group_id ?? "")}
                    onChange={(e) => setFormData({ ...formData, proposed_group_id: e.target.value ? Number(e.target.value) : null })}
                    className="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:border-slate-400 focus:outline-none"
                  >
                    <option value="">— Select Group —</option>
                    {groups.map((g) => (
                      <option key={g.id} value={String(g.id)}>
                        {g.name}
                      </option>
                    ))}
                  </select>
                </div>
              </div>

              <div>
                <label className="mb-1 block text-[11px] font-medium text-slate-600">Notes</label>
                <textarea
                  value={formData.notes ?? ""}
                  onChange={(e) => setFormData({ ...formData, notes: e.target.value })}
                  placeholder="Any additional notes..."
                  rows={3}
                  className="w-full resize-none rounded-lg border border-slate-200 px-3 py-2 text-sm focus:border-slate-400 focus:outline-none"
                />
              </div>

              <div className="flex justify-end gap-2 pt-2 border-t border-slate-100">
                <button
                  type="button"
                  onClick={() => { setShowModal(false); setEditingEntry(null); resetForm(); }}
                  className="rounded-lg border border-slate-200 px-4 py-2 text-sm text-slate-600 transition hover:bg-slate-50"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={submitting}
                  className="rounded-lg bg-slate-800 px-4 py-2 text-sm font-medium text-white transition hover:bg-slate-700 disabled:opacity-50"
                >
                  {submitting ? "Saving..." : editingEntry ? "Save Changes" : "Add Waitlist"}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
}

const statusLabels: Record<string, string> = {
  waiting: "Waiting",
  joined: "Joined",
  declined: "Declined",
};

const statusColors: Record<string, string> = {
  waiting: "bg-amber-100 text-amber-700",
  joined: "bg-emerald-100 text-emerald-700",
  declined: "bg-rose-100 text-rose-700",
};