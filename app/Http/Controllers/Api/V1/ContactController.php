<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ContactMessage;
use App\Models\User;
use App\Notifications\NewContactMessageNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;

class ContactController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name'    => 'required|string|max:255',
            'email'   => 'required|email|max:255',
            'subject' => 'required|string|in:demo,pricing,support,partnership,other',
            'message' => 'required|string|max:5000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors'  => $validator->errors(),
            ], 422);
        }

        $contact = ContactMessage::create($validator->validated());

        // Notifier tous les super admins (mail + DB)
        try {
            $superAdmins = User::role('super_admin')->get();
            Notification::send($superAdmins, new NewContactMessageNotification($contact));
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('Contact notification failed: ' . $e->getMessage());
        }

        return response()->json([
            'success' => true,
            'message' => 'Message envoyé avec succès. Nous vous répondrons sous 24h.',
        ], 201);
    }
}
