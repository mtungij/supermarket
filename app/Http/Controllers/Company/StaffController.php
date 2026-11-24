<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

class StaffController extends Controller
{
    public function index(): View
    {
        // The list, Edit and Reset password live in the Livewire component.
        return view('company.staff');
    }
}
