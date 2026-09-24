<?php

namespace App\Http\Controllers;

use App\Models\Service;
use Illuminate\Http\Request;

class ServiceController extends Controller
{
    public function index()
    {
        return Service::all();
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'     => 'required|string|max:255',
            'duration' => 'required|integer|min:1', // duração em minutos
            'price'    => 'required|numeric|min:0',
            'status'   => 'required|string|in:active,inactive',
        ]);

        $service = Service::create($validated);

        return response()->json($service, 201);
    }

    public function show(Service $service)
    {
        return $service;
    }

    public function update(Request $request, Service $service)
    {
        $validated = $request->validate([
            'name'     => 'sometimes|required|string|max:255',
            'duration' => 'sometimes|required|integer|min:1',
            'price'    => 'sometimes|required|numeric|min:0',
            'status'   => 'sometimes|required|string|in:active,inactive',
        ]);

        $service->update($validated);

        return response()->json($service);
    }

    public function destroy(Service $service)
    {
        $service->delete();

        return response()->json(null, 204);
    }
}