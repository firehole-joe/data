<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class AdminAuthController extends Controller
{
    public function showUnlockForm(Request $request)
    {
        // A signed-in admin never needs the passphrase.
        if ($request->user()?->isAdmin() || $request->session()->get('feed_admin_authenticated') === true) {
            return redirect()->route('supply.distributors');
        }

        return view('admin.unlock');
    }

    public function unlock(Request $request)
    {
        $passphrase = (string) $request->input('passphrase', '');
        $expected = (string) config('feed.admin_passphrase');

        if ($passphrase === '' || ! hash_equals($expected, $passphrase)) {
            // No withInput(): the passphrase must never be flashed back.
            return back()->withErrors([
                'passphrase' => 'That passphrase is not correct.',
            ]);
        }

        $request->session()->put('feed_admin_authenticated', true);

        $target = $request->session()->pull('feed_admin_return_url') ?: route('supply.distributors');

        return redirect()->to($target)->with('success', 'Feed admin unlocked.');
    }

    public function lock(Request $request)
    {
        $request->session()->forget(['feed_admin_authenticated', 'feed_admin_return_url']);

        return redirect()->route('supply.index')->with('status', 'Feed admin session locked.');
    }
}
