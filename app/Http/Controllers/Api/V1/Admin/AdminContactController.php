<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContactMessage;
use Illuminate\Http\JsonResponse;

class AdminContactController extends Controller
{
    /** Liste tous les messages de contact */
    public function index(): JsonResponse
    {
        $messages = ContactMessage::latest()->get();

        return response()->json([
            'success' => true,
            'data'    => $messages,
            'unread'  => $messages->where('read', false)->count(),
        ]);
    }

    /** Marquer un message comme lu */
    public function markRead(ContactMessage $contactMessage): JsonResponse
    {
        $contactMessage->update(['read' => true]);

        return response()->json(['success' => true]);
    }

    /** Supprimer un message */
    public function destroy(ContactMessage $contactMessage): JsonResponse
    {
        $contactMessage->delete();

        return response()->json(['success' => true]);
    }
}
