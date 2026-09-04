<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Admin-only management of the account roster: who can sign in, and who
 * holds feed-administrator privileges.
 *
 * Every action here is behind the `auth` + `admin` middleware (see
 * routes/web.php), so `$request->user()` is always a signed-in admin.
 */
class UserController extends Controller
{
    public function index()
    {
        return view('admin.users.index', [
            'users' => User::orderByDesc('is_admin')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'is_admin' => ['sometimes', 'boolean'],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => strtolower($data['email']),
            // The User model casts `password` as `hashed`, so a plain
            // value is hashed on save.
            'password' => $data['password'],
            'is_admin' => $request->boolean('is_admin'),
        ]);

        return redirect()
            ->route('admin.users.index')
            ->with('success', "Created {$user->email}".($user->is_admin ? ' as an administrator.' : '.'));
    }

    public function update(Request $request, User $user)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['nullable', 'string', 'min:8'],
            'is_admin' => ['sometimes', 'boolean'],
        ]);

        $wantsAdmin = $request->boolean('is_admin');

        // Guard against an admin accidentally locking themselves out of
        // the console by demoting their own account.
        if ($user->is($request->user()) && ! $wantsAdmin) {
            return back()->with('error', 'You cannot remove your own administrator access.');
        }

        $user->fill([
            'name' => $data['name'],
            'email' => strtolower($data['email']),
            'is_admin' => $wantsAdmin,
        ]);

        if (! empty($data['password'])) {
            $user->password = $data['password'];
        }

        $user->save();

        return redirect()
            ->route('admin.users.index')
            ->with('success', "Updated {$user->email}.");
    }

    public function destroy(Request $request, User $user)
    {
        if ($user->is($request->user())) {
            return back()->with('error', 'You cannot delete your own account.');
        }

        $email = $user->email;
        $user->delete();

        return redirect()
            ->route('admin.users.index')
            ->with('success', "Deleted {$email}.");
    }
}
