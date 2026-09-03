<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class LandingController extends Controller
{
    /**
     * The public, brand-forward front door. An authenticated user has no
     * reason to sit here — send them straight to the dashboard.
     */
    public function index(Request $request)
    {
        if ($request->user() !== null) {
            return redirect()->route('supply.dashboard');
        }

        return view('home');
    }
}
