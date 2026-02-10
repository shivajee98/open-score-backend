<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SupportCategory;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SupportAgentController extends Controller
{
    // --- Support Category Management ---

    public function indexCategories()
    {
        return response()->json(SupportCategory::withCount('agents')->get());
    }

    public function storeCategory(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'permissions' => 'nullable|array',
            'permissions.*' => 'string'
        ]);

        $category = SupportCategory::create([
            'name' => $validated['name'],
            'slug' => Str::slug($validated['name']),
            'permissions' => $validated['permissions'] ?? []
        ]);

        return response()->json($category, 201);
    }

    public function updateCategory(Request $request, $id)
    {
        $category = SupportCategory::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'permissions' => 'nullable|array',
            'permissions.*' => 'string'
        ]);

        if (isset($validated['name'])) {
            $category->name = $validated['name'];
            $category->slug = Str::slug($validated['name']);
        }

        if (isset($validated['permissions'])) {
            $category->permissions = $validated['permissions'];
        }

        $category->save();

        return response()->json($category);
    }

    public function destroyCategory($id)
    {
        $category = SupportCategory::findOrFail($id);
        
        // Optional: Check if agents are assigned?
        // For now, foreign key is set to null on delete, so it's safe.
        
        $category->delete();

        return response()->json(['message' => 'Category deleted']);
    }

    // --- Support Agent Management ---

    public function indexAgents()
    {
        $agents = User::where('role', 'SUPPORT_AGENT')
            ->with('supportCategory')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($agents);
    }

    public function storeAgent(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'mobile_number' => 'required|string|unique:users,mobile_number',
            'password' => 'required|string|min:6',
            'support_category_id' => 'required|exists:support_categories,id'
        ]);

        $agent = User::create([
            'name' => $validated['name'],
            'mobile_number' => $validated['mobile_number'],
            'password' => bcrypt($validated['password']),
            'role' => 'SUPPORT_AGENT',
            'support_category_id' => $validated['support_category_id'],
            'status' => 'ACTIVE',
            'is_onboarded' => true
        ]);

        return response()->json($agent->load('supportCategory'), 201);
    }

    public function updateAgent(Request $request, $id)
    {
        $agent = User::where('role', 'SUPPORT_AGENT')->findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'mobile_number' => ['sometimes', Rule::unique('users')->ignore($agent->id)],
            'password' => 'nullable|string|min:6',
            'support_category_id' => 'sometimes|exists:support_categories,id',
            'status' => 'sometimes|in:ACTIVE,INACTIVE'
        ]);

        $agent->fill($validated);

        if (!empty($validated['password'])) {
            $agent->password = bcrypt($validated['password']);
        }

        $agent->save();

        return response()->json($agent->load('supportCategory'));
    }

    public function destroyAgent($id)
    {
        $agent = User::where('role', 'SUPPORT_AGENT')->findOrFail($id);
        $agent->delete();

        return response()->json(['message' => 'Agent deleted']);
    }

    public function getCategoriesPublic()
    {
        // Return only id and label for frontend
        return response()->json(
            SupportCategory::select('id', 'name', 'slug')->get()
                ->map(function ($cat) {
                    return [
                        'id' => $cat->id, // Or slug if you prefer using slug as ID in frontend
                        'label' => $cat->name,
                        'slug' => $cat->slug
                    ];
                })
        );
    }

    public function checkAgentByMobile(Request $request) {
        $request->validate([
            'mobile_number' => 'required|string'
        ]);
        
        $user = User::where('mobile_number', $request->mobile_number)
            ->where('role', 'SUPPORT_AGENT')
            ->with('supportCategory')
            ->first();
            
        if (!$user) {
            return response()->json(['exists' => false], 404);
        }
        
        return response()->json([
            'exists' => true,
            'name' => $user->name,
            'category_name' => $user->supportCategory?->name
        ]);
    }
}
