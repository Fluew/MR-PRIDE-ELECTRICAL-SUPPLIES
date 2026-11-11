<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The signed-in user's in-app notifications (procurement workflow activity). */
class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'unread' => $user->unreadNotifications()->count(),
            'items' => $user->notifications()->latest()->limit(30)->get()->map(fn ($n) => [
                'id' => $n->id,
                'title' => $n->data['title'] ?? '',
                'message' => $n->data['message'] ?? '',
                'url' => $n->data['url'] ?? null,
                'read' => $n->read_at !== null,
                'at' => $n->created_at->toIso8601String(),
            ]),
        ]);
    }

    public function markRead(Request $request): JsonResponse
    {
        $request->validate(['ids' => ['nullable', 'array'], 'ids.*' => ['string']]);
        $query = $request->user()->unreadNotifications();
        if ($request->filled('ids')) {
            $query->whereIn('id', $request->input('ids'));
        }
        $query->update(['read_at' => now()]);

        return response()->json(['ok' => true]);
    }
}
