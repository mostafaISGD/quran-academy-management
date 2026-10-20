<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * إدارة فئات المصروفات — CRUD مقتصد:
 *
 * ① الفئة مش بتتحذف لو ليها مصروفات (سلامة معمل البيانات —
 *    فلوس مايمسحش تاريخها).
 * ② الـslug بيتولد من الاسم، ولو الاسم عربي (بلا ترجمة لاتينية)
 *    بيرجع فاضي — فبناخد لاحقة عشوائية قصيرة، والتفرد global
 *    على منشأة واحدة بس.
 */
class ExpenseCategoryController extends Controller
{
    public function index(Request $request)
    {
        return response()->json(ExpenseCategory::query()
            ->where('organization_id', $request->user()->organization_id)
            ->withCount('expenses')
            ->orderBy('name')
            ->get());
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
        ]);

        $category = ExpenseCategory::create([
            'organization_id' => $request->user()->organization_id,
            'name' => $data['name'],
            'slug' => $this->uniqueSlug($request->user()->organization_id, $data['name']),
            'description' => $data['description'] ?? null,
            'status' => 'active',
        ]);

        return response()->json($category, 201);
    }

    public function update(Request $request, ExpenseCategory $category)
    {
        if ($category->organization_id !== $request->user()->organization_id) {
            abort(404);
        }

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'status' => 'nullable|in:active,inactive',
        ]);

        $category->update([
            'name' => $data['name'],
            // الاسم وعاء الـslug — لو اتغيّر الجديد يتولد بس لو نازل مختلف
            'slug' => $this->uniqueSlug(
                $category->organization_id,
                $data['name'],
                $category->name === $data['name'] ? $category->slug : null,
            ),
            'description' => $data['description'] ?? $category->description,
            'status' => $data['status'] ?? $category->status,
        ]);

        return response()->json($category->fresh());
    }

    public function destroy(Request $request, ExpenseCategory $category)
    {
        if ($category->organization_id !== $request->user()->organization_id) {
            abort(404);
        }

        $used = Expense::query()->where('category_id', $category->id)->exists();

        if ($used) {
            return response()->json([
                'error' => 'لا يمكن حذف الفئة — مرتبطة بمصروفات مسجّلة',
            ], 409);
        }

        $category->delete();

        return response()->json(['deleted' => true]);
    }

    /**
     * slug تفريدي داخل المنشأة — الاسم العربي بيرجع فاضي
     * من `Str::slug` فبنستعمل بديل قصير عشوائي.
     */
    private function uniqueSlug(int $organizationId, string $name, ?string $keep = null): string
    {
        if ($keep !== null) {
            return $keep;
        }

        $base = Str::slug($name);
        if ($base === '') {
            $base = 'cat-'.Str::lower(Str::random(6));
        }

        $slug = $base;
        $i = 1;
        while (ExpenseCategory::query()
            ->where('organization_id', $organizationId)
            ->where('slug', $slug)
            ->exists()) {
            $slug = $base.'-'.(++$i);
        }

        return $slug;
    }
}
