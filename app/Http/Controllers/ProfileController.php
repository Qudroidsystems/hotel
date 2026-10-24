<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * "My Account" / profile settings: personal details, profile picture, email
 * and password. A user may always edit their own account; editing someone
 * else's requires the "Update user" permission.
 */
class ProfileController extends Controller
{
    public function show(string $id)
    {
        $user = User::with('roles')->findOrFail($id);

        abort_unless($this->canView($user), 403);

        $canEdit   = $this->canEdit($user);
        $pagetitle = $user->name . ' - Profile Settings';

        return view('users.settings', compact('user', 'canEdit', 'pagetitle'));
    }

    public function updateProfile(Request $request)
    {
        $user = User::findOrFail($request->input('id'));
        abort_unless($this->canEdit($user), 403);

        $data = $request->validate([
            'name'          => 'required|string|max:255',
            'username'      => ['nullable', 'string', 'max:60', 'alpha_dash', Rule::unique('users', 'username')->ignore($user->id)],
            'phone_number'  => ['nullable', 'string', 'regex:/^\+?[0-9]{7,15}$/'],
            'gender'        => 'nullable|in:male,female,other',
            'nationality'   => 'nullable|string|max:100',
            'date_of_birth' => 'nullable|date|before:today',
            'address'       => 'nullable|string|max:500',
        ]);

        $user->update($data);

        return back()->with('success', 'Personal information updated successfully!');
    }

    public function updateAvatar(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'id'     => 'required|exists:users,id',
            'avatar' => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:5120',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => $validator->errors()->first()], 422);
        }

        $user = User::findOrFail($request->input('id'));
        if (! $this->canEdit($user)) {
            return response()->json(['success' => false, 'message' => 'You cannot change this profile picture.'], 403);
        }

        try {
            $file     = $request->file('avatar');
            $filename = 'avatar_' . $user->id . '_' . time() . '.' . $file->extension();

            Storage::disk('public')->putFileAs(User::AVATAR_DIR, $file, $filename);

            if ($user->avatar) {
                Storage::disk('public')->delete(User::AVATAR_DIR . '/' . basename($user->avatar));
            }

            $user->update(['avatar' => $filename]);

            return response()->json([
                'success'    => true,
                'message'    => 'Profile picture updated successfully!',
                'avatar_url' => $user->avatar_url,
                'filename'   => $filename,
            ]);
        } catch (\Throwable $e) {
            Log::error('Avatar upload error: ' . $e->getMessage(), ['user_id' => $user->id]);
            return response()->json(['success' => false, 'message' => 'Upload failed. Please try again.'], 500);
        }
    }

    public function updateEmail(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'userid'                    => 'required|exists:users,id',
            'emailaddress'              => 'required|email|unique:users,email,' . $request->input('userid'),
            'emailaddress_confirmation' => 'required|same:emailaddress',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => $validator->errors()->first()], 422);
        }

        $user = User::findOrFail($request->input('userid'));
        if (! $this->canEdit($user)) {
            return response()->json(['success' => false, 'message' => 'You cannot change this email address.'], 403);
        }

        $user->update(['email' => $request->input('emailaddress')]);

        return response()->json(['success' => true, 'message' => 'Email updated successfully!']);
    }

    public function updatePassword(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'userid'   => 'required|exists:users,id',
            'password' => 'required|min:8|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => $validator->errors()->first()], 422);
        }

        $user = User::findOrFail($request->input('userid'));
        if (! $this->canEdit($user)) {
            return response()->json(['success' => false, 'message' => 'You cannot change this password.'], 403);
        }

        $user->update([
            'password'             => Hash::make($request->input('password')),
            'must_change_password' => false,
        ]);

        return response()->json(['success' => true, 'message' => 'Password updated successfully!']);
    }

    private function canView(User $user): bool
    {
        return $user->is(auth()->user()) || auth()->user()->can('View user');
    }

    private function canEdit(User $user): bool
    {
        return $user->is(auth()->user()) || auth()->user()->can('Update user');
    }
}
