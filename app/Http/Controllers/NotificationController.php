<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class NotificationController extends Controller
{
    public function index(): JsonResponse
    {
        $user = Auth::user();

        $notifications = $user->notifications()->latest()->limit(15)->get()->map(fn ($n) => [
            'id'         => $n->id,
            'title'      => $n->data['title'] ?? '',
            'message'    => $n->data['message'] ?? '',
            'url'        => $n->data['url'] ?? '#',
            'read'       => $n->read_at !== null,
            'created_at' => $n->created_at->diffForHumans(),
            // Which alert sound it plays (Settings → Sounds).
            'sound'      => \App\Services\SoundSettings::eventForNotification($n->type),
        ]);

        return response()->json([
            'unread_count' => $user->unreadNotifications()->count(),
            'notifications' => $notifications,
        ]);
    }

    public function markRead(Request $request, string $id): JsonResponse
    {
        $notification = Auth::user()->notifications()->findOrFail($id);
        $notification->markAsRead();

        return response()->json(['success' => true]);
    }

    public function markAllRead(): JsonResponse
    {
        Auth::user()->unreadNotifications->markAsRead();

        return response()->json(['success' => true]);
    }
}
