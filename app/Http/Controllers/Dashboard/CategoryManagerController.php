<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\MarketplaceCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CategoryManagerController extends Controller
{
    public function index(Request $request): View
    {
        $type = (string) $request->query('type', 'all');
        abort_unless($type === 'all' || in_array($type, MarketplaceCategory::TYPES, true), 404);

        $categories = MarketplaceCategory::query()
            ->with(['parent:id,name'])
            ->withCount(['listings', 'jobs', 'children'])
            ->when($type !== 'all', fn ($query) => $query->where('type', $type))
            ->orderBy('type')->orderBy('sort_order')->orderBy('name')
            ->paginate(10)->withQueryString();

        $productParents = MarketplaceCategory::query()
            ->where('type', 'product')->whereNull('parent_id')
            ->where('is_active', true)->orderBy('sort_order')->orderBy('name')
            ->get(['id', 'name']);

        return view('dashboard.categories', compact('categories', 'type', 'productParents'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        if (MarketplaceCategory::query()->where('type', $data['type'])
            ->where('slug', Str::slug($data['name']))->exists()) {
            return back()->withErrors(['name' => 'This category already exists for this marketplace type.']);
        }

        MarketplaceCategory::query()->create([...$data, 'created_by' => $request->user()->id]);
        AuditEvent::record('category.created', $request->user(), $request);
        return back()->with('status', 'Marketplace category created.');
    }

    public function update(Request $request, MarketplaceCategory $category): RedirectResponse
    {
        $data = $this->validated($request, $category);

        if ($data['type'] !== $category->type &&
            ($category->listings()->exists() || $category->jobs()->exists() ||
                $category->children()->exists())) {
            abort(409, 'Referenced categories cannot switch marketplace type.');
        }

        if ($data['parent_id'] !== $category->parent_id &&
            ($category->children()->exists() || $category->listings()->exists())) {
            abort(409, 'Categories with assigned listings or children cannot change parents.');
        }
        if ($category->type === 'job' && $data['name'] !== $category->name &&
            $category->jobs()->exists()) {
            abort(409, 'Job categories with existing jobs cannot be renamed.');
        }

        if (MarketplaceCategory::query()->where('type', $data['type'])
            ->where('slug', Str::slug($data['name']))
            ->where('id', '!=', $category->id)->exists()) {
            return back()->withErrors(['name' => 'Category name already exists in this type.']);
        }

        $category->update($data);
        AuditEvent::record('category.updated', $request->user(), $request);
        return back()->with('status', 'Category settings updated.');
    }

    private function validated(Request $request, ?MarketplaceCategory $existing = null): array
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(MarketplaceCategory::TYPES)],
            'name' => ['required', 'string', 'min:3', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['required', 'boolean'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:100000'],
            'parent_id' => ['nullable', 'integer', Rule::exists('marketplace_categories', 'id')],
        ]);

        $parentId = isset($data['parent_id']) ? (int) $data['parent_id'] : null;
        $data['parent_id'] = $parentId;

        if ($parentId !== null) {
            $parent = MarketplaceCategory::query()->find($parentId);
            if ($data['type'] !== 'product' || ! $parent ||
                $parent->type !== 'product' || ! $parent->is_active ||
                $parent->parent_id !== null ||
                ($existing && $existing->id === $parentId)) {
                throw ValidationException::withMessages([
                    'parent_id' => 'Choose an active top-level Product category.',
                ]);
            }
        }
        return $data;
    }
}
