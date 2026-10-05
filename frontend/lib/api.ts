const API_BASE_URL = process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000/api";

function getToken(): string | null {
  if (typeof window === "undefined") return null;
  return localStorage.getItem("auth_token");
}

export async function apiFetch<T>(path: string, options: RequestInit = {}): Promise<T> {
  const token = getToken();

  const res = await fetch(`${API_BASE_URL}${path}`, {
    ...options,
    headers: {
      "Content-Type": "application/json",
      Accept: "application/json",
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
      ...options.headers,
    },
  });

  if (!res.ok) {
    const body = await res.json().catch(() => ({}));
    throw new Error(body.message ?? `API error: ${res.status}`);
  }

  return res.json() as Promise<T>;
}

/* ============================================================
   المصادقة
   ========================================================== */

export type AuthTeacher = {
  id: number;
  teacher_code: string;
  display_name: string;
  specialization: string | null;
  avatar_url: string | null;
};

export type AuthUser = {
  id: number;
  name: string;
  email: string | null;
  phone: string | null;
  job_title: string | null;
  department: string | null;
  status: "active" | "suspended" | "inactive";
  roles: string[];
  /** الحساب مربوط بمعلم — كل معلم بيتحكم بجدوله بنفسه */
  is_teacher: boolean;
  teacher: AuthTeacher | null;
  /** الحساب ولي أمر — بيشوف أبناؤه بس مش بيانات الأكاديمية */
  is_parent: boolean;
  parent: AuthParent | null;
};

export type AuthParent = {
  id: number;
  name: string;
  phone: string | null;
  email: string | null;
  children_count: number;
};

const AUTH_USER_KEY = "auth_user";

export async function login(email: string, password: string) {
  const data = await apiFetch<{ token: string; user: AuthUser }>("/auth/login", {
    method: "POST",
    body: JSON.stringify({ email, password }),
  });
  if (typeof window !== "undefined") {
    localStorage.setItem("auth_token", data.token);
    localStorage.setItem(AUTH_USER_KEY, JSON.stringify(data.user));
  }
  return data;
}

export async function logout() {
  try {
    await apiFetch("/auth/logout", { method: "POST" });
  } finally {
    if (typeof window !== "undefined") {
      localStorage.removeItem("auth_token");
      localStorage.removeItem(AUTH_USER_KEY);
    }
  }
}

/** المستخدم الحالي من localStorage — بدون request */
export function getStoredUser(): AuthUser | null {
  if (typeof window === "undefined") return null;
  const raw = localStorage.getItem(AUTH_USER_KEY);
  if (!raw) return null;
  try {
    return JSON.parse(raw) as AuthUser;
  } catch {
    return null;
  }
}

/** جلب المستخدم الحالي من السيرفر (المصدر真相) */
export async function fetchMe(): Promise<AuthUser> {
  const user = await apiFetch<AuthUser>("/auth/me");
  if (typeof window !== "undefined") {
    localStorage.setItem(AUTH_USER_KEY, JSON.stringify(user));
  }
  return user;
}

// ---- Types ----

export type Student = {
  id: number;
  student_code: string;
  first_name: string;
  middle_name: string | null;
  last_name: string;
  full_name: string;
  date_of_birth: string | null;
  gender: "male" | "female" | null;
  country_code: string | null;
  timezone: string;
  phone: string | null;
  whatsapp: string | null;
  email: string | null;
  status: "lead" | "active" | "paused" | "inactive" | "graduated" | "archived";
  notes: string | null;
  branch?: { id: number; name: string };
  parents?: { id: number; name: string; phone: string }[];
  next_lesson_date?: string | null;
  lessons_count?: number;
};

export type Parent = {
  id: number;
  name: string;
  phone: string;
  email: string | null;
  country_code: string | null;
  status: "active" | "inactive";
  students?: { id: number; full_name: string }[];
};

export type StudentParentLink = {
  student_id: number;
  parent_id: number;
  relationship: string;
  is_primary: boolean;
};

export type TeacherProgramRef = {
  id: number;
  name: string;
  lessons_count: number;
};

export type Teacher = {
  id: number;
  teacher_code: string;
  display_name: string;
  full_name: string;
  avatar_url: string | null;
  phone: string;
  email: string | null;
  country_code: string | null;
  timezone: string;
  specialization: string | null;
  qualifications: string | null;
  years_of_experience: number | null;
  languages: string | null;
  bio: string | null;
  status: "active" | "inactive";
  joined_at: string | null;
  branch?: { id: number; name: string } | null;
  activeContract?: { id: number; contract_type: string; monthly_salary: string | null; currency: string | null; start_date: string; end_date: string | null } | null;
  currentRate?: {
    id: number;
    rate_type: string;
    amount: string;
    currency: string;
    duration_minutes: number | null;
    effective_from: string;
    effective_to: string | null;
  } | null;
  // إحصائيات محسوبة من الـ backend
  average_rating?: number | null;
  ratings_count?: number;
  students_count?: number;
  lessons_count?: number;
  lessons_this_month?: number;
  programs?: TeacherProgramRef[];
};

export type TeacherOverviewStats = {
  students_count: number;
  lessons_count: number;
  lessons_this_month: number;
  upcoming_lessons: number;
  completed_lessons: number;
  average_rating: number | null;
  ratings_count: number;
};

export type TeacherOverview = {
  basic: Teacher;
  stats: TeacherOverviewStats;
  programs: TeacherProgramRef[];
};

export type TeacherStudentStats = {
  total: number;
  completed: number;
  cancelled: number;
  upcoming: number;
  last_lesson_at: string | null;
};

export type TeacherStudent = Omit<Student, "parents"> & {
  teacher_stats: TeacherStudentStats;
  phones?: StudentPhone[];
};

export type TeacherLessonsSummary = {
  summary: {
    total: number;
    completed: number;
    upcoming: number;
    cancelled: number;
    this_month: number;
    this_month_completed: number;
  };
  recent_lessons: Lesson[];
  upcoming_lessons: Lesson[];
  by_program: { name: string; lessons_count: number }[];
};

export type TeacherFinancialSummary = {
  summary: {
    earned_this_month: number;
    paid_this_month: number;
    earned_total: number;
    paid_total: number;
    pending_total: number;
    outstanding: number;
  };
  contract: Teacher["activeContract"];
  rate: Teacher["currentRate"];
  currency: string;
};

export type TeacherRating = {
  id: number;
  teacher_id: number;
  student_id: number | null;
  parent_id: number | null;
  rating: number;
  comment: string | null;
  created_at: string;
  student?: { id: number; full_name: string };
  parent?: { id: number; name: string };
};

export type ProgramCategory = {
  id: number;
  name: string;
  slug: string;
  icon: string | null;
  sort_order: number;
  description: string | null;
  programs_count?: number;
};

/** تصنيف متاح للفلترة — بيجي مع قائمة البرامج */
export type ProgramCategoryFilter = {
  id: number;
  name: string;
  slug: string;
  icon: string | null;
  programs_count: number;
};

export type Program = {
  id: number;
  name: string;
  slug: string;
  description: string | null;
  status: "active" | "inactive";
  image_url?: string | null;
  color?: string | null;
  levels?: Level[];
  categories?: ProgramCategory[];
  /**
   * الباقات. ملاحظة: Eloquent بيعمل snake_case لمفاتيح العلاقات في JSON،
   * فالاسم هنا `subscription_plans` مش `subscriptionPlans`.
   */
  subscription_plans?: SubscriptionPlan[];
  /** العدادات — من الداتابيز كلها مش الصفحة الحالية */
  levels_count?: number;
  teachers_count?: number;
  students_count?: number;
  plans_count?: number;
  /** تحذير: ربط المعلمين مختلف عن الواقع (حصصهم) */
  mismatches?: ProgramMismatches;
  created_at?: string;
  updated_at?: string;
};

/** مدرس البرنامج — مربوط رسمياً، أو عنده حصص بس مش مسجّل */
export type ProgramTeacherRow = {
  linked: boolean;
  id: number;
  display_name: string;
  specialization: string | null;
  status: "active" | "inactive";
  is_primary: boolean;
  rate_multiplier: number;
  lessons_count: number;
};

export type ProgramStudentsResponse = {
  students: {
    id: number;
    full_name: string;
    student_code: string;
    status: string;
    subscription_status: "active" | "paused" | "expired" | "cancelled";
    teacher_name: string | null;
    start_date: string;
    end_date: string | null;
    lessons_included: number | null;
  }[];
  total: number;
};

export type ProgramDetail = {
  program: Program;
  stats: {
    students_count: number;
    students: { active: number; paused: number; total: number };
    teachers_count: number;
    levels_count: number;
    plans_count: number;
    lessons_count: number;
    lessons_upcoming: number;
    memorization: {
      records: number;
      ayahs: number;
      pages: number;
      students: number;
      surahs: number;
    };
  };
};

export type ProgramsResponse = Paginated<Program> & {
  counts: { active: number; inactive: number };
  /** كام برنامج فيه عدم تطابق في ربط المعلمين (عبر كل النتائج) */
  mismatch_programs: number;
  filters: { categories: ProgramCategoryFilter[] };
};

/** عدم تطابق ربط المعلمين بالواقع — تحذير للكارت الفلتر */
export type ProgramMismatches = {
  /** معلمين عندهم حصص ومش مسجّلين على البرنامج */
  unlinked: number;
  /** معلمين مسجّلين ومفيش لهم حصص */
  idle: number;
  total: number;
};

/** نتيجة معاينة الـ slug قبل الحفظ */
export type SlugPreview = {
  name: string;
  suggested: string;
  slug: string;
  is_custom: boolean;
  is_fallback: boolean;
};

export type Level = {
  id: number;
  program_id: number;
  name: string;
  code: string;
  sort_order: number;
  description: string | null;
  status: "active" | "inactive";
};

export type Subscription = {
  id: number;
  student_id: number;
  /** اختياري — الاشتراك بيتسجّل بالسعر ونوع الفوترة مباشرة */
  plan_id: number | null;
  program_id: number;
  teacher_id: number | null;
  start_date: string;
  end_date: string | null;
  billing_type: "monthly" | "per_lesson" | "custom";
  price: string;
  currency: string;
  lesson_duration_minutes: number;
  lessons_included: number | null;
  /** الأيام في الأسبوع (٠=الأحد) — اتخزّنوا عشان نرجعهم في الفورم */
  schedule_weekdays: number[] | null;
  /** وقت بداية الحصة HH:MM */
  schedule_start_time: string | null;
  status: "active" | "expired" | "paused" | "cancelled";
  auto_renew: boolean;
  notes: string | null;
  student?: { id: number; full_name: string } | null;
  plan?: { id: number; name: string } | null;
  program?: { id: number; name: string } | null;
  teacher?: { id: number; full_name: string } | null;
};

/** طلب إنشاء/تعديل اشتراك — المواعيد الأسبوعية جزء أساسي منه */
export type SubscriptionPayload = {
  student_id: number;
  program_id: number;
  teacher_id?: number | null;
  plan_id?: number | null;
  start_date: string;
  end_date?: string | null;
  billing_type?: "monthly" | "per_lesson";
  price?: number | string | null;
  currency?: string | null;
  lesson_duration_minutes?: number | null;
  lessons_included?: number | null;
  auto_renew?: boolean | null;
  notes?: string | null;
  /** الأيام في الأسبوع (٠=الأحد) — بتتحوّل لحصص على التقويم */
  weekdays?: number[];
  /** وقت بداية الحصة HH:MM */
  start_time?: string | null;
};

/** نتيجة تشغيل الاشتراك — بتتولّد تلقائياً أول ما يتحفظ */
export type SubscriptionActivation = {
  invoice_id: number | null;
  invoice_total: number;
  credit_account_id: number | null;
  /** عدد الحصص اللي اتجدولت فعلاً */
  lessons_created: number;
  /** المواعيد اللي اتخطّت لأن المعلم كان مشغول فيها */
  lessons_skipped: { date: string; reason: string }[];
};

export type SubscriptionActivationResult = {
  subscription: Subscription;
  activation: SubscriptionActivation;
};

export type SubscriptionPlan = {
  id: number;
  organization_id: number;
  program_id: number;
  name: string;
  billing_type: "monthly" | "per_lesson" | "custom";
  price: string;
  currency: string;
  lessons_count: number | null;
  lesson_duration_minutes: number;
  duration_days: number | null;
  status: "active" | "inactive";
  description: string | null;
};

/* ============================================================
   جدول commitments المعلّم (أكاديمية + شغل خارجي)
   ========================================================== */

/** 0 = الأحد … 6 = السبت (نفس dayOfWeek في Carbon) */
export const WEEKDAY_LABELS: Record<number, string> = {
  0: "الأحد",
  1: "الإثنين",
  2: "الثلاثاء",
  3: "الأربعاء",
  4: "الخميس",
  5: "الجمعة",
  6: "السبت",
};

export const WEEKDAY_SHORT: Record<number, string> = {
  0: "أحد",
  1: "إثنين",
  2: "ثلاثاء",
  3: "أربعاء",
  4: "خميس",
  5: "جمعة",
  6: "سبت",
};

export type ScheduleKind = "academy" | "external" | "leave" | "personal";

export const SCHEDULE_KIND_LABEL: Record<ScheduleKind, string> = {
  academy: "أكاديمية",
  external: "خارجي",
  leave: "إجازة",
  personal: "شخصي",
};

export type TeacherScheduleBlock = {
  id: number;
  teacher_id: number;
  weekday: number;
  weekday_label: string;
  starts_at: string;
  ends_at: string;
  kind: ScheduleKind;
  kind_label: string;
  title: string | null;
  notes: string | null;
  is_recurring: boolean;
  specific_date: string | null;
  created_at: string;
};

export type TeacherAvailability = {
  teacher_id: number;
  week_start: string;
  blocks: TeacherScheduleBlock[];
  total: number;
};

export type AvailabilityCheck = {
  teacher_id: number;
  has_schedule: boolean;
  blocks_count: number;
};

/** معلّم في قائمة التوافر — مع سبب عدم التوفر وسعته */
export type AvailableTeacher = {
  id: number;
  name: string;
  available: boolean;
  reason: string | null;
  /** مالهوش جدول مسجّل — ظاهر بس مع علامة تحذير */
  has_schedule: boolean;
  /** كام حصة لسه فاضية في المواعيد دي (٠ لو مشغول) */
  capacity: number;
  /** كام حصة محجوزة بالفعل */
  booked: number;
  /** السعة لكل يوم مختر (مفتاح = رقم اليوم ٠=الأحد) */
  per_day: Record<string, number>;
};

export function getTeacherAvailability(teacherId: number, week?: string) {
  const s = new URLSearchParams();
  if (week) s.set("week", week);
  const q = s.toString();
  return apiFetch<TeacherAvailability>(
    `/teachers/${teacherId}/availability${q ? `?${q}` : ""}`,
  );
}

export function getAllTeacherAvailability(params?: {
  teacher_id?: number;
  weekday?: number;
}) {
  const s = new URLSearchParams();
  if (params?.teacher_id) s.set("teacher_id", String(params.teacher_id));
  if (params?.weekday !== undefined) s.set("weekday", String(params.weekday));
  const q = s.toString();
  return apiFetch<{ data: TeacherScheduleBlock[] }>(
    `/teacher-availability${q ? `?${q}` : ""}`,
  );
}

export function getAvailabilityCompleteness(teacherId: number) {
  return apiFetch<AvailabilityCheck>(
    `/teachers/${teacherId}/availability/completeness`,
  );
}

export function createScheduleBlock(
  teacherId: number,
  payload: {
    weekday: number;
    starts_at: string;
    ends_at: string;
    kind: ScheduleKind;
    title?: string;
    notes?: string;
    is_recurring?: boolean;
    specific_date?: string;
  },
) {
  return apiFetch<TeacherScheduleBlock>(`/teachers/${teacherId}/availability`, {
    method: "POST",
    body: JSON.stringify(payload),
  });
}

export function updateScheduleBlock(
  teacherId: number,
  blockId: number,
  payload: Partial<{
    weekday: number;
    starts_at: string;
    ends_at: string;
    kind: ScheduleKind;
    title: string | null;
    notes: string | null;
    is_recurring: boolean;
    specific_date: string | null;
  }>,
) {
  return apiFetch<TeacherScheduleBlock>(
    `/teachers/${teacherId}/availability/${blockId}`,
    { method: "PUT", body: JSON.stringify(payload) },
  );
}

export function deleteScheduleBlock(teacherId: number, blockId: number) {
  return apiFetch<{ message: string }>(
    `/teachers/${teacherId}/availability/${blockId}`,
    { method: "DELETE" },
  );
}

/** المعلمين المتاحين في مواعيد معيّنة — للـ dropdown */
export function getAvailableTeachers(params: {
  weekdays: number[];
  start_time: string;
  duration: number;
  on_date?: string;
  /** نهاية المدة — عشان نحسب السعة على الفترة كلها */
  period_to?: string;
  exclude_lesson_id?: number;
}) {
  const s = new URLSearchParams();
  params.weekdays.forEach((d) => s.append("weekdays[]", String(d)));
  s.set("start_time", params.start_time);
  s.set("duration", String(params.duration));
  if (params.on_date) s.set("on_date", params.on_date);
  if (params.period_to) s.set("period_to", params.period_to);
  if (params.exclude_lesson_id) {
    s.set("exclude_lesson_id", String(params.exclude_lesson_id));
  }
  return apiFetch<{
    weekdays: number[];
    start_time: string;
    duration: number;
    teachers: AvailableTeacher[];
    available_count: number;
  }>(`/availability/teachers?${s.toString()}`);
}

export type Lesson = {
  id: number;
  student_id: number;
  teacher_id: number;
  subscription_id: number | null;
  program_id: number;
  level_id: number | null;
  parent_lesson_id: number | null;
  lesson_type: "regular" | "trial" | "makeup" | "extra" | "free" | "assessment";
  scheduled_start_at: string;
  scheduled_end_at: string;
  actual_start: string | null;
  actual_end: string | null;
  duration_minutes: number;
  meeting_provider: "zoom" | "google_meet" | "other" | null;
  meeting_url: string | null;
  status: "scheduled" | "confirmed" | "in_progress" | "completed" | "cancelled" | "student_absent" | "teacher_absent" | "technical_issue" | "rescheduled";
  cancellation_reason: string | null;
  notes: string | null;
  student?: { id: number; full_name: string };
  teacher?: { id: number; full_name: string };
  program?: { id: number; name: string };
  level?: { id: number; name: string };
};

export type Invoice = {
  id: number;
  invoice_number: string;
  student_id: number;
  parent_id: number | null;
  subscription_id: number | null;
  issue_date: string;
  due_date: string | null;
  subtotal: string;
  discount: string;
  tax: string;
  total: string;
  paid_amount: string;
  balance_due: string;
  currency: string;
  status: "draft" | "issued" | "partially_paid" | "paid" | "overdue" | "void";
  notes: string | null;
  items?: InvoiceItem[];
  student?: { id: number; full_name: string };
};

export type InvoiceItem = {
  id: number;
  invoice_id: number;
  description: string;
  item_type: string | null;
  quantity: string;
  unit_price: string;
  total: string;
};

export type Payment = {
  id: number;
  student_id: number;
  parent_id: number | null;
  invoice_id: number | null;
  amount: string;
  currency: string;
  payment_method: "cash" | "bank_transfer" | "wallet" | "online_payment" | "card" | "other";
  transaction_reference: string | null;
  status: "pending" | "completed" | "failed" | "voided";
  paid_at: string;
  received_by: number;
  notes: string | null;
  student?: { id: number; full_name: string };
  invoice?: { id: number; invoice_number: string };
};

export type Lead = {
  id: number;
  full_name: string;
  phone: string;
  email: string | null;
  country_code: string | null;
  student_age: number | null;
  interested_program_id: number | null;
  source: "facebook" | "instagram" | "website" | "referral" | "other" | null;
  assigned_staff_id: number | null;
  status: "new" | "contacted" | "qualified" | "trial_booked" | "trial_completed" | "offer_sent" | "converted" | "lost";
  notes: string | null;
  program?: { id: number; name: string };
  assignedStaff?: { id: number; name: string };
};

export type Assessment = {
  id: number;
  lead_id: number | null;
  student_id: number | null;
  teacher_id: number;
  scheduled_at: string;
  reading_score: number | null;
  tajweed_score: number | null;
  memorization_score: number | null;
  recommended_level: string | null;
  notes: string | null;
  result: "ready_to_subscribe" | "needs_follow_up" | "not_suitable" | null;
  lead?: { id: number; full_name: string };
  student?: { id: number; full_name: string };
  teacher?: { id: number; full_name: string };
};

export type Expense = {
  id: number;
  organization_id: number;
  branch_id: number | null;
  category_id: number;
  amount: string;
  currency: string;
  expense_date: string;
  description: string;
  payment_method: string | null;
  reference: string | null;
  created_by: number;
  status: "pending" | "approved" | "rejected";
  created_at: string;
  updated_at: string;
  category?: { id: number; name: string; slug: string } | null;
};

export function getExpenses(params?: {
  status?: string;
  category_id?: number;
  branch_id?: number;
  from?: string;
  to?: string;
  page?: number;
  per_page?: number;
}) {
  const search = new URLSearchParams();
  if (params?.status) search.set("status", params.status);
  if (params?.category_id) search.set("category_id", String(params.category_id));
  if (params?.branch_id) search.set("branch_id", String(params.branch_id));
  if (params?.from) search.set("from", params.from);
  if (params?.to) search.set("to", params.to);
  if (params?.page) search.set("page", String(params.page));
  search.set("per_page", String(params?.per_page ?? 100));
  return apiFetch<Paginated<Expense>>(`/expenses?${search.toString()}`);
}

export type TeacherEarning = {
  id: number;
  teacher_id: number;
  lesson_id: number | null;
  contract_id: number | null;
  rate_id: number | null;
  amount: string;
  currency: string;
  earning_date: string;
  status: "pending" | "approved" | "paid" | "cancelled";
  notes: string | null;
  teacher?: { id: number; full_name: string } | null;
};

export type TeacherPayment = {
  id: number;
  teacher_id: number;
  payroll_period_id: number | null;
  amount: string;
  currency: string;
  payment_method: string | null;
  reference: string | null;
  paid_at: string | null;
  status: "pending" | "completed" | "failed";
  teacher?: { id: number; full_name: string } | null;
  payrollPeriod?: { id: number; name: string; status: string } | null;
};

export function getTeacherEarnings(params?: {
  teacher_id?: number;
  status?: string;
  from?: string;
  to?: string;
  page?: number;
  per_page?: number;
}) {
  const s = new URLSearchParams();
  if (params?.teacher_id) s.set("teacher_id", String(params.teacher_id));
  if (params?.status) s.set("status", params.status);
  if (params?.from) s.set("from", params.from);
  if (params?.to) s.set("to", params.to);
  if (params?.page) s.set("page", String(params.page));
  s.set("per_page", String(params?.per_page ?? 100));
  return apiFetch<Paginated<TeacherEarning>>(`/teacher-earnings?${s.toString()}`);
}

export function getTeacherPayments(params?: {
  teacher_id?: number;
  status?: string;
  payroll_period_id?: number;
  page?: number;
  per_page?: number;
}) {
  const s = new URLSearchParams();
  if (params?.teacher_id) s.set("teacher_id", String(params.teacher_id));
  if (params?.status) s.set("status", params.status);
  if (params?.payroll_period_id) s.set("payroll_period_id", String(params.payroll_period_id));
  if (params?.page) s.set("page", String(params.page));
  s.set("per_page", String(params?.per_page ?? 100));
  return apiFetch<Paginated<TeacherPayment>>(`/teacher-payments?${s.toString()}`);
}

/* ============================================================
   الإجراءات الجماعية على الطلاب
   ========================================================== */

/** تغيير المدرس لمجموعة طلاب (اشتراكات نشطة + حصص مجدولة) */
export function bulkChangeTeacher(payload: {
  student_ids: number[];
  teacher_id: number;
  include_upcoming_lessons?: boolean;
}) {
  return apiFetch<{
    message: string;
    students: number;
    subscriptions_updated: number;
    lessons_updated: number;
    teacher: { id: number; full_name: string };
  }>("/students/bulk/change-teacher", {
    method: "POST",
    body: JSON.stringify(payload),
  });
}

export type BulkInvoiceResult = {
  message: string;
  created_count: number;
  skipped_count: number;
  total_amount: number;
  invoices: { invoice_id: number; invoice_number: string; student_id: number; total: number }[];
  skipped: { student_id: number; reason: string }[];
};

/** إنشاء فاتورة من الاشتراك النشط لكل طالب — المبلغ من سعر الاشتراك أو مبلغ موحّد */
export function bulkCreateInvoices(payload: {
  student_ids: number[];
  issue_date?: string;
  due_date?: string;
  amount?: number;
  description?: string;
}) {
  return apiFetch<BulkInvoiceResult>("/students/bulk/create-invoices", {
    method: "POST",
    body: JSON.stringify(payload),
  });
}

export type BulkNotifyResult = {
  message: string;
  sent_count: number;
  requested_count: number;
  channel: string;
};

/** إرسال إشعار جماعي لمجموعة طلاب */
export function bulkNotify(payload: {
  student_ids: number[];
  title?: string;
  message: string;
  channel?: "in_app" | "whatsapp" | "sms" | "email";
}) {
  return apiFetch<BulkNotifyResult>("/students/bulk/notify", {
    method: "POST",
    body: JSON.stringify(payload),
  });
}

export function createExpense(payload: {
  amount: number;
  expense_date: string;
  description: string;
  payment_method?: string;
  category_id?: number;
  reference?: string;
  notes?: string;
}) {
  return apiFetch<Expense>("/expenses", {
    method: "POST",
    body: JSON.stringify(payload),
  });
}

export type Notification = {
  id: number;
  user_id: number;
  event_type: "lesson_reminder" | "subscription_expiring" | "subscription_expired" | "payment_received" | "makeup_created" | "trial_reminder";
  channel: "in_app" | "email" | "whatsapp" | "sms";
  payload: Record<string, unknown> | null;
  sent_at: string | null;
  read_at: string | null;
  created_at: string;
};

export type AuditLog = {
  id: number;
  user_id: number;
  action: string;
  entity_type: string;
  entity_id: number;
  old_value: Record<string, unknown> | null;
  new_value: Record<string, unknown> | null;
  ip_address: string | null;
  created_at: string;
  user?: { id: number; name: string };
};

export type QuranSurah = {
  id: number;
  number: number;
  name_ar: string;
  name_en: string;
  ayah_count: number;
  revelation_type: "meccan" | "medinan";
};

// ---- API Functions ----

export type Paginated<T> = {
  data: T[];
  current_page: number;
  last_page: number;
  per_page: number;
  from: number | null;
  to: number | null;
  total: number;
  /** إحصائيات محسوبة من الداتابيز على كل النتائج (مش الصفحة الحالية) */
  counts?: Record<string, number | Record<string, number>>;
  /** مجاميع مالية محسوبة على كل النتائج (total / amount / ...) */
  sums?: Record<string, number>;
};

// ============================================================
// الموظفون
// ============================================================

export type EmployeeStatus = "active" | "inactive" | "on_leave";
export type EmploymentType = "full_time" | "part_time" | "contract" | "volunteer";

export type Employee = {
  id: number;
  user_id: number | null;
  name: string;
  full_name: string;
  phone: string | null;
  country_code: string | null;
  email: string | null;
  gender: string | null;
  date_of_birth: string | null;
  nationality: string | null;
  address: string | null;
  photo_url: string | null;
  job_title: string | null;
  department: string | null;
  employment_type: EmploymentType;
  /**
   * سعر الساعة بالج.م — أساس حساب الأجر.
   * null يعني: بيتقفل بشهر ثابت (أو متطوع)، فمفيش أجر بالساعة.
   */
  hourly_rate: number | null;
  manager_id: number | null;
  joined_at: string | null;
  status: EmployeeStatus;
  notes: string | null;
  // خصائص محسوبة من الـ backend
  role: string | null;
  role_label: string;
  has_account: boolean;
  account_status: string | null;
  last_login_at: string | null;
  manager?: Pick<Employee, "id" | "name" | "job_title"> | null;
  user?: { id: number; email: string; status: string } | null;
};

export type EmployeeListResponse = Paginated<Employee> & {
  counts: { active: number; inactive: number; on_leave: number };
  filters: { departments: string[]; job_titles: string[] };
};

export type EmployeePayload = {
  name: string;
  phone?: string | null;
  country_code?: string | null;
  email?: string | null;
  gender?: string | null;
  date_of_birth?: string | null;
  nationality?: string | null;
  address?: string | null;
  job_title?: string | null;
  department?: string | null;
  employment_type?: EmploymentType;
  hourly_rate?: number | null;
  manager_id?: number | null;
  joined_at?: string | null;
  status?: EmployeeStatus;
  notes?: string | null;
  create_account?: boolean;
  role_id?: number | null;
};

export function getEmployees(params?: {
  status?: string;
  department?: string;
  job_title?: string;
  employment_type?: string;
  search?: string;
  page?: number;
  per_page?: number;
}) {
  const s = new URLSearchParams();
  if (params?.status) s.set("status", params.status);
  if (params?.department) s.set("department", params.department);
  if (params?.job_title) s.set("job_title", params.job_title);
  if (params?.employment_type) s.set("employment_type", params.employment_type);
  if (params?.search) s.set("search", params.search);
  if (params?.page) s.set("page", String(params.page));
  s.set("per_page", String(params?.per_page ?? 100));
  return apiFetch<EmployeeListResponse>(`/employees?${s.toString()}`);
}

/** الأدوار المتاحة — لتعيين دور الموظف */
export type RoleOption = {
  id: number;
  name: string;
  slug: string;
  is_system: boolean;
  permissions?: { name: string }[];
};

export function getRoles() {
  return apiFetch<{ data: RoleOption[] }>("/roles");
}

export function getEmployee(id: number) {
  return apiFetch<{
    employee: Employee & { subordinates?: Pick<Employee, "id" | "name" | "job_title">[] };
    role: string | null;
    role_label: string;
    /** الصلاحيات مجمّعة حسب الوحدة مع علامة لكل إجراء */
    granted: Record<string, { name: string; granted: boolean }[]>;
    all_units: Record<string, string[]>;
  }>(`/employees/${id}`);
}

export function createEmployee(payload: EmployeePayload) {
  return apiFetch<Employee & { warning?: string }>("/employees", {
    method: "POST",
    body: JSON.stringify(payload),
  });
}

export function updateEmployee(id: number, payload: Partial<EmployeePayload>) {
  return apiFetch<Employee>(`/employees/${id}`, {
    method: "PUT",
    body: JSON.stringify(payload),
  });
}

export function deleteEmployee(id: number) {
  return apiFetch<{ message: string }>(`/employees/${id}`, { method: "DELETE" });
}

export type EmployeeActivity = {
  id: number;
  action: string;
  entity_type: string;
  entity_id: number;
  old_value: Record<string, unknown> | null;
  new_value: Record<string, unknown> | null;
  created_at: string;
};

/** سجل نشاط موظف — بيتقرا من user_id بتاعه */
export function getEmployeeActivity(id: number, page = 1) {
  return apiFetch<Paginated<EmployeeActivity>>(`/employees/${id}/activity?page=${page}&per_page=20`);
}

// ============================================================
// الحضور والانصراف
// ============================================================

export type AttendanceStatus = "present" | "absent" | "late" | "on_leave" | "half_day";

/**
 * حضور موظف في يوم — بالساعات المرنة.
 *
 * ⭐ `worked_hours` هو اللي الأدمن كتبه ومصدر الأجر. مفيش نسخة
 * ثانية بتحسب الساعات في الـ frontend — السيرفر هو المصدر الوحيد
 * (كانت في `hoursBetween` هنا قبل كده وطلعت رقمين مختلفين).
 */
export type AttendanceDayRow = {
  employee: {
    id: number;
    name: string;
    job_title: string | null;
    department: string | null;
    employment_type: EmploymentType;
    /** سعر الساعة — nullable. الأجر المتوقع = الساعات × السعر */
    hourly_rate: number | null;
  };
  record: {
    id: number;
    status: AttendanceStatus;
    /** أوقات اختيارية — معلومة مساعِدة، مش مصدر الأجر */
    check_in: string | null;
    check_out: string | null;
    worked_hours: number;
    notes: string | null;
  } | null;
};

export type AttendanceDay = {
  date: string;
  weekday: string;
  is_friday: boolean;
  summary: {
    total: number;
    marked: number;
    unmarked: number;
    by_status: Record<string, number>;
  };
  rows: AttendanceDayRow[];
};

export function getAttendanceDay(date?: string) {
  const s = new URLSearchParams();
  if (date) s.set("date", date);
  return apiFetch<AttendanceDay>(`/attendance/day?${s.toString()}`);
}

export function saveAttendance(
  date: string,
  records: {
    employee_id: number;
    status: AttendanceStatus;
    /** الساعات — المصدر الأساسي. الـ backend بيرفض أكتر من ٢٤ */
    worked_hours?: number | null;
    check_in?: string | null;
    check_out?: string | null;
    notes?: string | null;
  }[],
) {
  return apiFetch<{
    message: string;
    date: string;
    saved: number;
    total_hours: number;
  }>("/attendance", {
    method: "POST",
    body: JSON.stringify({ date, records }),
  });
}

export type AttendanceMonth = {
  employee: { id: number; name: string; job_title: string | null };
  month: string;
  days: {
    date: string;
    day: number;
    weekday: number;
    is_friday: boolean;
    future: boolean;
    record: {
      status: AttendanceStatus;
      check_in: string | null;
      check_out: string | null;
      worked_hours: number;
    } | null;
  }[];
  stats: {
    by_status: Record<string, number>;
    present: number;
    absent: number;
    on_leave: number;
    worked_hours: number;
    attendance_rate: number | null;
  };
};

export function getEmployeeAttendanceMonth(id: number, month?: string) {
  const s = new URLSearchParams();
  if (month) s.set("month", month);
  return apiFetch<AttendanceMonth>(`/employees/${id}/attendance-month?${s.toString()}`);
}

// ============================================================
// واجهة ولي الأمر
// ============================================================

/** صورة مختصرة عن ابن — اللي ولي الأمر مسموح يشوفه */
export type ParentChildView = {
  student: {
    id: number;
    full_name: string;
    student_code: string;
    status: string;
    photo_url: string | null;
  };
  relationship: string | null;
  subscription: {
    id: number;
    program_name: string;
    teacher_name: string | null;
    status: string;
    billing_type: string;
    start_date: string;
    end_date: string | null;
    days_left: number | null;
    /** ٠..١ — كام من الحصص اتConsumers */
    progress: number;
    weekday_names: string;
    start_time: string | null;
  } | null;
  lesson_credit: {
    balance: number;
    included: number;
    status: string;
    expires_at: string | null;
  } | null;
  upcoming_lessons: {
    id: number;
    scheduled_start_at: string;
    teacher_name: string | null;
    status: string;
  }[];
  outstanding: {
    invoices: number;
    total: number;
    currency: string;
  };
};

/** كل بيانات ولي الأمر في نداء واحد — كل ابن مع اشتراكه وحصصه وفواتيره */
export function getMyChildren() {
  return apiFetch<{
    parent: { id: number; name: string; phone: string | null; email: string | null };
    children: ParentChildView[];
  }>("/parent/children");
}

export function getStudents(params?: {
  status?: string;
  search?: string;
  branch_id?: string;
  page?: number;
  per_page?: number;
}) {
  const search = new URLSearchParams();
  if (params?.status) search.set("status", params.status);
  if (params?.search) search.set("search", params.search);
  if (params?.branch_id) search.set("branch_id", params.branch_id);
  if (params?.page) search.set("page", String(params.page));
  search.set("per_page", String(params?.per_page ?? 100));
  return apiFetch<Paginated<Student>>(`/students?${search.toString()}`);
}

export function getStudent(id: number) {
  return apiFetch<Student>(`/students/${id}`);
}

export function createStudent(payload: {
  first_name: string; last_name: string; middle_name?: string; date_of_birth?: string;
  gender?: string; country_code?: string; phone?: string; email?: string; branch_id?: string; status?: string; notes?: string;
}) {
  return apiFetch<Student>("/students", { method: "POST", body: JSON.stringify(payload) });
}

export function updateStudent(id: number, payload: Partial<Student>) {
  return apiFetch<Student>(`/students/${id}`, { method: "PUT", body: JSON.stringify(payload) });
}

export function deleteStudent(id: number) {
  return apiFetch<{ message: string }>(`/students/${id}`, { method: "DELETE" });
}

// ---- Student Phones ----

export type StudentPhone = {
  id: number;
  student_id: number;
  phone_number: string;
  is_personal: boolean;
  is_parent: boolean;
  is_whatsapp: boolean;
  is_call: boolean;
  is_primary: boolean;
  parent_name: string | null;
  parent_relationship: string | null;
};

export function getStudentPhones(studentId: number) {
  return apiFetch<{ data: StudentPhone[] }>(`/students/${studentId}/phones`);
}

export function addStudentPhone(studentId: number, payload: {
  phone_number: string;
  country_code?: string;
  is_personal?: boolean;
  is_parent?: boolean;
  is_whatsapp?: boolean;
  is_call?: boolean;
  is_primary?: boolean;
  parent_name?: string;
  parent_relationship?: string;
}) {
  return apiFetch<StudentPhone>(`/students/${studentId}/phones`, { method: "POST", body: JSON.stringify(payload) });
}

export function updateStudentPhone(studentId: number, phoneId: number, payload: Partial<StudentPhone>) {
  return apiFetch<StudentPhone>(`/students/${studentId}/phones/${phoneId}`, { method: "PUT", body: JSON.stringify(payload) });
}

export function deleteStudentPhone(studentId: number, phoneId: number) {
  return apiFetch<{ message: string }>(`/students/${studentId}/phones/${phoneId}`, { method: "DELETE" });
}

export function setPrimaryPhone(studentId: number, phoneId: number) {
  return apiFetch<{ message: string }>(`/students/${studentId}/phones/${phoneId}/set-primary`, { method: "POST" });
}

export type StudentParent = {
  id: number;
  name: string;
  phone: string;
  email: string | null;
  status: string;
  relationship: string | null;
  is_primary: boolean;
  students_count: number;
};

/** صلات القرابة المتاحة — "أخرى" بتسمح بكتابة أي قيمة */
export const PARENT_RELATIONSHIPS = [
  { value: "father", label: "أب" },
  { value: "mother", label: "أم" },
  { value: "grandfather", label: "جد" },
  { value: "grandmother", label: "جدة" },
  { value: "uncle", label: "خال" },
  { value: "aunt", label: "خالة" },
  { value: "brother", label: "أخ" },
  { value: "sister", label: "أخت" },
  { value: "guardian", label: "وصي" },
  { value: "tutor", label: "مدرّب" },
  { value: "stepfather", label: "زوج الأم" },
  { value: "stepmother", label: "زوجة الأب" },
  { value: "son", label: "ابن" },
  { value: "daughter", label: "بنت" },
  { value: "friend", label: "صديق" },
  { value: "employer", label: "جهة العمل" },
  { value: "sponsor", label: "كافل" },
  { value: "other", label: "أخرى" },
];

export function parentRelationshipLabel(value: string | null): string {
  if (!value) return "—";
  return PARENT_RELATIONSHIPS.find((r) => r.value === value)?.label ?? value;
}

/** أولياء أمر طالب مع العلاقات */
export function getStudentParents(studentId: number) {
  return apiFetch<{ data: StudentParent[] }>(`/students/${studentId}/parents`);
}

/** تغيير ولي الأمر الأساسي */
export function setPrimaryParent(studentId: number, parentId: number) {
  return apiFetch<{ data: StudentParent[]; message: string }>(
    `/students/${studentId}/parents/${parentId}/set-primary`,
    { method: "POST" },
  );
}

/** الإكمال التلقائي: بحث في أولياء الأمر بالرقم أو الاسم */
export function searchParents(query: string) {
  const s = new URLSearchParams();
  if (query) s.set("q", query);
  return apiFetch<{ data: (Parent & { students_count: number })[] }>(`/parents/search?${s.toString()}`);
}

export function getParents() {
  return apiFetch<{ data: Parent[] }>("/parents");
}

export function getTeachers(params?: {
  status?: string;
  search?: string;
  specialization?: string;
  sort?: string;
  dir?: "asc" | "desc";
  page?: number;
  per_page?: number;
}) {
  const search = new URLSearchParams();
  if (params?.status) search.set("status", params.status);
  if (params?.search) search.set("search", params.search);
  if (params?.specialization) search.set("specialization", params.specialization);
  if (params?.sort) search.set("sort", params.sort);
  if (params?.dir) search.set("dir", params.dir);
  if (params?.page) search.set("page", String(params.page));
  search.set("per_page", String(params?.per_page ?? 100));
  return apiFetch<Paginated<Teacher>>(`/teachers?${search.toString()}`);
}

export function getTeacher(id: number) {
  return apiFetch<Teacher>(`/teachers/${id}`);
}

/** حصص المعلم — لعرض «الحصص القادمة» في صفحة الجدول */
export function getTeacherLessons(id: number, params?: { per_page?: number }) {
  const s = new URLSearchParams();
  s.set("per_page", String(params?.per_page ?? 50));
  return apiFetch<Paginated<Lesson>>(`/teachers/${id}/schedule?${s.toString()}`);
}

/** نظرة عامة على المعلم: البيانات الأساسية + إحصائيات + البرامج */
export function getTeacherOverview(id: number) {
  return apiFetch<TeacherOverview>(`/teachers/${id}/overview`);
}

/** الطلاب المرتبطون بالمعلم مع إحصائيات كل طالب */
export function getTeacherStudents(id: number, params?: { search?: string }) {
  const search = new URLSearchParams();
  if (params?.search) search.set("search", params.search);
  return apiFetch<{ students: TeacherStudent[]; total: number }>(
    `/teachers/${id}/students?${search.toString()}`,
  );
}

/** ملخص الحصص: إحصائيات + القادمة + الأخيرة + التوزيع حسب البرنامج */
export function getTeacherLessonsSummary(id: number) {
  return apiFetch<TeacherLessonsSummary>(`/teachers/${id}/lessons-summary`);
}

/** الملخص المالي (قراءة فقط) */
export function getTeacherFinancialSummary(id: number) {
  return apiFetch<TeacherFinancialSummary>(`/teachers/${id}/financial-summary`);
}

export function createTeacher(payload: {
  display_name: string;
  phone: string;
  email?: string;
  country_code?: string;
  specialization?: string;
  qualifications?: string;
  years_of_experience?: number;
  languages?: string;
  bio?: string;
  branch_id?: string;
  status?: string;
}) {
  return apiFetch<Teacher>("/teachers", { method: "POST", body: JSON.stringify(payload) });
}

export function updateTeacher(id: number, payload: Partial<Teacher>) {
  return apiFetch<Teacher>(`/teachers/${id}`, { method: "PUT", body: JSON.stringify(payload) });
}

export function deleteTeacher(id: number) {
  return apiFetch<{ message: string }>(`/teachers/${id}`, { method: "DELETE" });
}

export function getTeacherRatings(teacherId: number) {
  return apiFetch<{
    ratings: TeacherRating[];
    average_rating: number;
    ratings_count: number;
  }>(`/teachers/${teacherId}/ratings`);
}

export function rateTeacher(teacherId: number, payload: {
  student_id?: number;
  parent_id?: number;
  rating: number;
  comment?: string;
}) {
  return apiFetch<{
    rating: TeacherRating;
    average_rating: number;
    ratings_count: number;
  }>(`/teachers/${teacherId}/ratings`, { method: "POST", body: JSON.stringify(payload) });
}

// ============================================================
// البرامج
// ============================================================

export type ProgramPayload = {
  name?: string;
  slug?: string;
  description?: string | null;
  image_url?: string | null;
  color?: string | null;
  status?: "active" | "inactive";
  category_ids?: number[];
};

export function getPrograms(params?: {
  status?: string;
  category_id?: number;
  search?: string;
  /** فلتر «فيه عدم تطابق في ربط المعلمين» */
  has_mismatches?: boolean;
  page?: number;
  per_page?: number;
}) {
  const s = new URLSearchParams();
  if (params?.status) s.set("status", params.status);
  if (params?.category_id) s.set("category_id", String(params.category_id));
  if (params?.search) s.set("search", params.search);
  if (params?.has_mismatches) s.set("has_mismatches", "1");
  if (params?.page) s.set("page", String(params.page));
  s.set("per_page", String(params?.per_page ?? 100));
  return apiFetch<ProgramsResponse>(`/programs?${s.toString()}`);
}

export function getProgram(id: number) {
  return apiFetch<ProgramDetail>(`/programs/${id}`);
}

export function createProgram(payload: ProgramPayload) {
  return apiFetch<Program>("/programs", { method: "POST", body: JSON.stringify(payload) });
}

export function updateProgram(id: number, payload: ProgramPayload) {
  return apiFetch<Program>(`/programs/${id}`, { method: "PUT", body: JSON.stringify(payload) });
}

export function deleteProgram(id: number) {
  return apiFetch<{ message: string }>(`/programs/${id}`, { method: "DELETE" });
}

// ---- المستويات / المراحل ----

export function getProgramLevels(programId: number) {
  return apiFetch<Level[]>(`/programs/${programId}/levels`);
}

export function createProgramLevel(
  programId: number,
  payload: { name: string; code?: string | null; sort_order?: number; description?: string | null; status?: string },
) {
  return apiFetch<Level>(`/programs/${programId}/levels`, {
    method: "POST",
    body: JSON.stringify(payload),
  });
}

export function updateProgramLevel(
  programId: number,
  levelId: number,
  payload: { name?: string; description?: string | null; status?: string; sort_order?: number },
) {
  return apiFetch<Level>(`/programs/${programId}/levels/${levelId}`, {
    method: "PUT",
    body: JSON.stringify(payload),
  });
}

export function deleteProgramLevel(programId: number, levelId: number) {
  return apiFetch<{ message: string; orphaned_lessons: number; affected_students: number }>(
    `/programs/${programId}/levels/${levelId}`,
    { method: "DELETE" },
  );
}

export function reorderProgramLevels(programId: number, order: { id: number; sort_order: number }[]) {
  return apiFetch<Level[]>(`/programs/${programId}/levels/reorder`, {
    method: "POST",
    body: JSON.stringify({ order }),
  });
}

// ---- مدرسون البرنامج ----

/** فحص تطابق معلمي البرنامج + إصلاح جماعي */
export type ProgramTeachersHealth = { unlinked: number; idle: number; total: number };

export type ProgramTeachersResponse = {
  /** مسجّل وفيه حصص — الوضع الطبيعي */
  linked: ProgramTeacherRow[];
  /** مسجّل ومفيش حصص → لازم الأدمن يقرر */
  idle: ProgramTeacherRow[];
  /** عنده حصص ومش مسجّل → لازم يتبعت للبرنامج */
  suggested: ProgramTeacherRow[];
  health: ProgramTeachersHealth;
};

/** نتيجة معاينة الـ slug — السيرفر هو اللي بيحوّل العربي لـ latin */
export function previewProgramSlug(params: {
  name?: string;
  slug?: string | null;
  ignoreId?: number;
}) {
  const s = new URLSearchParams();
  if (params.name) s.set("name", params.name);
  if (params.slug !== undefined && params.slug !== null) s.set("slug", params.slug);
  if (params.ignoreId) s.set("ignore_id", String(params.ignoreId));
  return apiFetch<SlugPreview>(`/programs/slug-preview?${s.toString()}`);
}

export function getProgramTeachers(programId: number) {
  return apiFetch<ProgramTeachersResponse>(`/programs/${programId}/teachers`);
}

/** يربط كل معلم عنده حصص في البرنامج ومش مسجّل */
export function linkAllMissingTeachers(programId: number) {
  return apiFetch<{ linked: number[]; message: string }>(
    `/programs/${programId}/teachers/link-missing`,
    { method: "POST" },
  );
}

/** يفكّ كل معلم مسجّل ومفيش له حصص في البرنامج */
export function unlinkIdleTeachers(programId: number) {
  return apiFetch<{ unlinked: number[]; message: string }>(
    `/programs/${programId}/teachers/unlink-idle`,
    { method: "POST" },
  );
}

export function linkProgramTeacher(
  programId: number,
  payload: { teacher_id: number; is_primary?: boolean; rate_multiplier?: number; notes?: string | null },
) {
  return apiFetch<{ id: number }[]>(`/programs/${programId}/teachers`, {
    method: "POST",
    body: JSON.stringify(payload),
  });
}

export function unlinkProgramTeacher(programId: number, teacherId: number) {
  return apiFetch<{ id: number }[]>(`/programs/${programId}/teachers/${teacherId}`, {
    method: "DELETE",
  });
}

// ---- طلاب البرنامج (ملخص) ----

export function getProgramStudents(programId: number, params?: { search?: string }) {
  const s = new URLSearchParams();
  if (params?.search) s.set("search", params.search);
  const qs = s.toString();
  return apiFetch<ProgramStudentsResponse>(`/programs/${programId}/students${qs ? `?${qs}` : ""}`);
}

// ---- التصنيفات ----

export function getProgramCategories() {
  return apiFetch<ProgramCategory[]>("/program-categories");
}

export function createProgramCategory(payload: {
  name: string;
  slug?: string;
  icon?: string | null;
  sort_order?: number;
  description?: string | null;
}) {
  return apiFetch<ProgramCategory>("/program-categories", {
    method: "POST",
    body: JSON.stringify(payload),
  });
}

export function updateProgramCategory(
  id: number,
  payload: { name?: string; icon?: string | null; sort_order?: number; description?: string | null },
) {
  return apiFetch<ProgramCategory>(`/program-categories/${id}`, {
    method: "PUT",
    body: JSON.stringify(payload),
  });
}

export function deleteProgramCategory(id: number) {
  return apiFetch<{ message: string; detached_from_programs: number }>(`/program-categories/${id}`, {
    method: "DELETE",
  });
}

export function getSubscriptions(params?: {
  student_id?: number;
  status?: string;
  program_id?: number;
  teacher_id?: number;
  page?: number;
  per_page?: number;
}) {
  const search = new URLSearchParams();
  if (params?.student_id) search.set("student_id", String(params.student_id));
  if (params?.status) search.set("status", params.status);
  if (params?.program_id) search.set("program_id", String(params.program_id));
  if (params?.teacher_id) search.set("teacher_id", String(params.teacher_id));
  if (params?.page) search.set("page", String(params.page));
  search.set("per_page", String(params?.per_page ?? 100));
  return apiFetch<Paginated<Subscription>>(`/subscriptions?${search.toString()}`);
}

/**
 * إنشاء اشتراك — بيتشغّل تلقائياً: فاتورة + رصيد حصص + جدولة الحصص.
 * بيرجّع نتيجة التشغيل عشان الـ UI يعرض عدد اللي اتجدول واللي اتخطّى.
 */
export function createSubscription(payload: SubscriptionPayload) {
  return apiFetch<SubscriptionActivationResult>("/subscriptions", {
    method: "POST",
    body: JSON.stringify(payload),
  });
}

/** تعديل اشتراك — بيرجّع نفس نتيجة التشغيل (بيتجدول الناقص بس) */
export function updateSubscription(id: number, payload: SubscriptionPayload) {
  return apiFetch<SubscriptionActivationResult>(`/subscriptions/${id}`, {
    method: "PUT",
    body: JSON.stringify(payload),
  });
}

/** نتيجة معالجة يوم — نفس اللي بيحصل بالأمر التلقائي كل ٠١:١٥ */
export type ProcessDayResult = {
  date: string;
  renewed: {
    subscription_id: number;
    student: string | null;
    teacher: string | null;
    old_end_date: string;
    new_end_date: string;
    invoice_id: number | null;
    lessons_created: number;
    lessons_skipped: number;
  }[];
  expiring_notified: {
    subscription_id: number;
    student: string | null;
    end_date: string;
    days_left: number;
    recipients: number;
  }[];
  expired: {
    subscription_id: number;
    student: string | null;
    end_date: string;
    lessons_cancelled: number;
    recipients: number;
  }[];
  invoices_created: number;
  lessons_created: number;
  lessons_skipped: number;
  skipped_reasons: Record<string, number>;
  notifications_created: number;
};

/** تشغيل المعالجة اليومية يدوياً — آمنة للتكرار */
export function processSubscriptionDay(date?: string) {
  return apiFetch<ProcessDayResult>("/subscriptions/process-day", {
    method: "POST",
    body: JSON.stringify(date ? { date } : {}),
  });
}

export function deleteSubscription(id: number) {
  return apiFetch<{ message: string }>(`/subscriptions/${id}`, { method: "DELETE" });
}

export function getSchedule(params: { view?: "day" | "week" | "month"; date?: string; teacher_id?: number; student_id?: number }) {
  const search = new URLSearchParams();
  if (params.view) search.set("view", params.view);
  if (params.date) search.set("date", params.date);
  if (params.teacher_id) search.set("teacher_id", String(params.teacher_id));
  if (params.student_id) search.set("student_id", String(params.student_id));
  return apiFetch<Lesson[]>(`/schedule?${search.toString()}`);
}

export function createLesson(payload: {
  student_id: number; teacher_id: number; program_id: number; level_id?: string; subscription_id?: string;
  lesson_type?: string; scheduled_start: string; duration_minutes: number; meeting_provider?: string; meeting_url?: string; notes?: string;
}) {
  return apiFetch<Lesson>("/lessons", { method: "POST", body: JSON.stringify(payload) });
}

export function cancelLesson(lessonId: number, reason: string) {
  return apiFetch<Lesson>(`/lessons/${lessonId}/cancel`, { method: "POST", body: JSON.stringify({ cancellation_reason: reason }) });
}

export function completeLesson(lessonId: number) {
  return apiFetch<Lesson>(`/lessons/${lessonId}/complete`, { method: "POST" });
}

export function markAttendance(lessonId: number, status: string, lateMinutes?: number) {
  return apiFetch(`/lessons/${lessonId}/attendance`, { method: "POST", body: JSON.stringify({ status, late_minutes: lateMinutes }) });
}

export function recordMemorization(lessonId: number, payload: { surah_id: number; from_ayah: number; to_ayah: number; quality?: number; notes?: string }) {
  return apiFetch(`/lessons/${lessonId}/memorization`, { method: "POST", body: JSON.stringify(payload) });
}

export function getInvoices(params?: {
  student_id?: number;
  status?: string;
  page?: number;
  per_page?: number;
}) {
  const search = new URLSearchParams();
  if (params?.student_id) search.set("student_id", String(params.student_id));
  if (params?.status) search.set("status", params.status);
  if (params?.page) search.set("page", String(params.page));
  search.set("per_page", String(params?.per_page ?? 100));
  return apiFetch<Paginated<Invoice>>(`/invoices?${search.toString()}`);
}

export function createInvoice(payload: {
  student_id: number; parent_id?: string; subscription_id?: string; issue_date: string;
  due_date?: string; items: { description: string; quantity: number; unit_price: number }[]; discount?: number; tax?: number; notes?: string;
}) {
  return apiFetch<Invoice>("/invoices", { method: "POST", body: JSON.stringify(payload) });
}

export function deleteInvoice(id: number) {
  return apiFetch<{ message: string }>(`/invoices/${id}`, { method: "DELETE" });
}

export function getPayments(params?: {
  student_id?: number;
  status?: string;
  payment_method?: string;
  page?: number;
  per_page?: number;
}) {
  const search = new URLSearchParams();
  if (params?.student_id) search.set("student_id", String(params.student_id));
  if (params?.status) search.set("status", params.status);
  if (params?.payment_method) search.set("payment_method", params.payment_method);
  if (params?.page) search.set("page", String(params.page));
  search.set("per_page", String(params?.per_page ?? 100));
  return apiFetch<Paginated<Payment>>(`/payments?${search.toString()}`);
}

export function createPayment(payload: {
  student_id: number; invoice_id?: string; parent_id?: string; amount: number;
  currency: string; payment_method: string; transaction_reference?: string; paid_at: string; notes?: string;
}) {
  return apiFetch<Payment>("/payments", { method: "POST", body: JSON.stringify(payload) });
}

export function refundPayment(paymentId: number, amount: number, reason: string) {
  return apiFetch(`/payments/${paymentId}/refund`, { method: "POST", body: JSON.stringify({ amount, reason }) });
}

export function getLeads(params?: {
  status?: string;
  source?: string;
  search?: string;
  page?: number;
  per_page?: number;
}) {
  const search = new URLSearchParams();
  if (params?.status) search.set("status", params.status);
  if (params?.source) search.set("source", params.source);
  if (params?.search) search.set("search", params.search);
  if (params?.page) search.set("page", String(params.page));
  search.set("per_page", String(params?.per_page ?? 100));
  return apiFetch<Paginated<Lead>>(`/leads?${search.toString()}`);
}

export function createLead(payload: {
  full_name: string; phone: string; email?: string; country_code?: string;
  student_age?: number; interested_program_id?: string; source?: string; notes?: string;
}) {
  return apiFetch<Lead>("/leads", { method: "POST", body: JSON.stringify(payload) });
}

export function updateLeadStatus(leadId: number, status: Lead["status"]) {
  return apiFetch<Lead>(`/leads/${leadId}/status`, { method: "PUT", body: JSON.stringify({ status }) });
}

export function deleteLead(id: number) {
  return apiFetch<{ message: string }>(`/leads/${id}`, { method: "DELETE" });
}

export function getAssessments(params?: {
  teacher_id?: number;
  result?: string;
  page?: number;
  per_page?: number;
}) {
  const s = new URLSearchParams();
  if (params?.teacher_id) s.set("teacher_id", String(params.teacher_id));
  if (params?.result) s.set("result", params.result);
  if (params?.page) s.set("page", String(params.page));
  s.set("per_page", String(params?.per_page ?? 100));
  return apiFetch<Paginated<Assessment>>(`/assessments?${s.toString()}`);
}

export function createAssessment(payload: { lead_id: number; teacher_id: number; scheduled_at: string }) {
  return apiFetch<Assessment>("/assessments", { method: "POST", body: JSON.stringify(payload) });
}

export function updateAssessmentResult(assessmentId: number, payload: {
  reading_score?: number; tajweed_score?: number; memorization_score?: number;
  recommended_level?: string; notes?: string; result: Assessment["result"];
}) {
  return apiFetch<Assessment>(`/assessments/${assessmentId}/result`, { method: "PUT", body: JSON.stringify(payload) });
}

export function getNotifications(params?: { unread?: boolean; page?: number; per_page?: number }) {
  const s = new URLSearchParams();
  if (params?.unread) s.set("unread", "1");
  if (params?.page) s.set("page", String(params.page));
  s.set("per_page", String(params?.per_page ?? 100));
  return apiFetch<Paginated<Notification>>(`/notifications?${s.toString()}`);
}

export function markNotificationRead(notificationId: number) {
  return apiFetch(`/notifications/${notificationId}/read`, { method: "POST" });
}

export function markAllNotificationsRead() {
  return apiFetch("/notifications/read-all", { method: "POST" });
}

export function getAuditLogs(params?: {
  entity_type?: string;
  entity_id?: number;
  user_id?: number;
  action?: string;
  page?: number;
  per_page?: number;
}) {
  const search = new URLSearchParams();
  if (params?.entity_type) search.set("entity_type", params.entity_type);
  if (params?.entity_id) search.set("entity_id", String(params.entity_id));
  if (params?.user_id) search.set("user_id", String(params.user_id));
  if (params?.action) search.set("action", params.action);
  if (params?.page) search.set("page", String(params.page));
  search.set("per_page", String(params?.per_page ?? 100));
  return apiFetch<Paginated<AuditLog>>(`/audit-logs?${search.toString()}`);
}

export function getDashboardSummary() {
  return apiFetch<{
    today: { lessons_total: number; lessons_completed: number; lessons_upcoming: number };
    totals: { active_students: number; active_teachers: number; monthly_revenue: string };
    alerts: { subscriptions_expiring_soon: number; pending_teacher_payments: string; unscheduled_leads: number };
  }>("/dashboard/summary");
}

export function getFinancialReport(params?: { from?: string; to?: string }) {
  const search = new URLSearchParams();
  if (params?.from) search.set("from", params.from);
  if (params?.to) search.set("to", params.to);
  return apiFetch<{
    period: { from: string; to: string };
    revenue: { by_currency: Record<string, { total: string; count: number }>; by_method: Record<string, { total: string; count: number }>; total_refunded: string };
    teacher_costs: { gross_earnings: string; payments_made: string };
  }>(`/reports/financial?${search.toString()}`);
}

export function getAcademicReport(params?: { from?: string; to?: string }) {
  const search = new URLSearchParams();
  if (params?.from) search.set("from", params.from);
  if (params?.to) search.set("to", params.to);
  return apiFetch<{
    period: { from: string; to: string };
    lessons: { total: number; completed: number; cancelled: number; student_absent: number; teacher_absent: number };
    attendance: { present: number; absent: number; late: number };
    memorization: { total: number; avg_quality: number };
    subscriptions: { active: number; expired: number };
  }>(`/reports/academic?${search.toString()}`);
}

export function getSalesReport(params?: { from?: string; to?: string }) {
  const search = new URLSearchParams();
  if (params?.from) search.set("from", params.from);
  if (params?.to) search.set("to", params.to);
  return apiFetch<{
    period: { from: string; to: string };
    leads: { total: number; converted: number; conversion_rate: string };
    trials: { total: number; ready_to_subscribe: number; needs_follow_up: number; not_suitable: number };
  }>(`/reports/sales?${search.toString()}`);
}

export function getAttendanceReport(params?: { from?: string; to?: string; student_id?: number; teacher_id?: number; program_id?: number }) {
  const search = new URLSearchParams();
  if (params?.from) search.set("from", params.from);
  if (params?.to) search.set("to", params.to);
  if (params?.student_id) search.set("student_id", String(params.student_id));
  if (params?.teacher_id) search.set("teacher_id", String(params.teacher_id));
  if (params?.program_id) search.set("program_id", String(params.program_id));
  return apiFetch<{
    period: { from: string; to: string };
    summary: {
      total_lessons_scheduled: number;
      completed_lessons: number;
      cancelled_lessons: number;
      student_absent_lessons: number;
      teacher_absent_lessons: number;
      total_attendance_records: number;
      present: number;
      absent: number;
      late: number;
      attendance_rate: string;
    };
    by_student: Array<{
      student_id: number;
      student_name: string;
      student_code: string;
      total_sessions: number;
      present: number;
      absent: number;
      late: number;
      attendance_rate: number;
    }>;
    by_date: Array<{
      date: string;
      total: number;
      present: number;
      absent: number;
      late: number;
      rate: number;
    }>;
  }>(`/reports/attendance?${search.toString()}`);
}

export function getSubscriptionReport(params?: { from?: string; to?: string; status?: string; program_id?: number; teacher_id?: number }) {
  const search = new URLSearchParams();
  if (params?.from) search.set("from", params.from);
  if (params?.to) search.set("to", params.to);
  if (params?.status) search.set("status", params.status);
  if (params?.program_id) search.set("program_id", String(params.program_id));
  if (params?.teacher_id) search.set("teacher_id", String(params.teacher_id));
  return apiFetch<{
    period: { from: string; to: string };
    summary: {
      total: number;
      active: number;
      expired: number;
      paused: number;
      cancelled: number;
      expiring_soon: number;
    };
    revenue_by_currency: Record<string, { total: number; count: number }>;
    by_program: Array<{
      program_id: number;
      program_name: string;
      total: number;
      active: number;
      expired: number;
      revenue: number;
    }>;
    by_teacher: Array<{
      teacher_id: number;
      teacher_name: string;
      total_students: number;
      active: number;
      expired: number;
    }>;
    details: Array<{
      id: number;
      student: { id: number; name: string; code: string; status: string };
      program: string;
      teacher: string;
      start_date: string;
      end_date: string | null;
      days_left: number | null;
      is_expiring_soon: boolean;
      status: string;
      billing_type: string;
      price: number;
      currency: string;
      lessons_included: number | null;
      lesson_duration_minutes: number;
    }>;
  }>(`/reports/subscriptions?${search.toString()}`);
}

export function getSettings() {
  return apiFetch<Record<string, unknown>>("/settings");
}

export function saveSetting(key: string, value: unknown) {
  return apiFetch<{ message: string; key: string }>("/settings", {
    method: "POST",
    body: JSON.stringify({ key, value }),
  });
}

export function deleteSetting(key: string) {
  return apiFetch<{ message: string }>(`/settings/${encodeURIComponent(key)}`, { method: "DELETE" });
}
