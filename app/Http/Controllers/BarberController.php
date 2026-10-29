<?php

namespace App\Http\Controllers;

use App\Models\Barber;
use Illuminate\Http\Request;

class BarberController extends Controller
{
    public function index()
    {
        return Barber::all();
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:barbers,email',
            'phone' => 'nullable|string|max:20',
        ]);

        return Barber::create($validated);
    }

    public function show(Barber $barber)
    {
        return $barber;
    }

    public function update(Request $request, Barber $barber)
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'email' => 'sometimes|required|email|unique:barbers,email,' . $barber->id,
            'phone' => 'nullable|string|max:20',
        ]);

        $barber->update($validated);

        return $barber;
    }

    public function destroy(Barber $barber)
    {
        $barber->delete();

        return response()->noContent();
    }
}