<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /** Liste des notifications de l'utilisateur connecté */
    public function index(Request $request): JsonResponse
    {
        $user          = $request->user();
        $notifications = $user->notifications()->latest()->take(30)->get();
        $unreadCount   = $user->unreadNotifications()->count();

        return response()->json([
            'success'      => true,
            'data'         => $notifications->map(fn($n) => [
                'id'         => $n->id,
                'type'       => $n->data['type'] ?? 'info',
                'message'    => $n->data['message_text'] ?? $n->data['message'] ?? '',
                'data'       => $n->data,
                'read'       => ! is_null($n->read_at),
                'created_at' => $n->created_at->diffForHumans(),
            ]),
            'unread_count' => $unreadCount,
        ]);
    }

    /** Marquer une notification comme lue */
    public function markRead(Request $request, string $id): JsonResponse
    {
        $notification = $request->user()->notifications()->findOrFail($id);
        $notification->markAsRead();

        return response()->json(['success' => true]);
    }

    /** Marquer toutes comme lues */
    public function markAllRead(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return response()->json(['success' => true]);
    }
}
