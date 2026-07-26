<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Portal\Concerns\InteractsWithPortalUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PortalNotificationController extends Controller
{
    use InteractsWithPortalUser;

    public function index(): JsonResponse
    {
        $portalUser = $this->portalUser();

        $notifications = $portalUser->notifications()->latest()->limit(15)->get()->map(fn ($n) => [
            'id'         => $n->id,
            'title'      => $n->data['title'] ?? '',
            'message'    => $n->data['message'] ?? '',
            'url'        => $n->data['url'] ?? '#',
            'read'       => $n->read_at !== null,
            'created_at' => $n->created_at->diffForHumans(),
        ]);

        return response()->json([
            'unread_count'  => $portalUser->unreadNotifications()->count(),
            'notifications' => $notifications,
        ]);
    }

    public function markRead(Request $request, string $id): JsonResponse
    {
        $notification = $this->portalUser()->notifications()->findOrFail($id);
        $notification->markAsRead();

        return response()->json(['success' => true]);
    }

    public function markAllRead(): JsonResponse
    {
        $this->portalUser()->unreadNotifications->markAsRead();

        return response()->json(['success' => true]);
    }
}
