<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PushSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PushSubscriptionController extends Controller
{
    /** Return the VAPID public key for the browser to use when subscribing. */
    public function vapidKey(): JsonResponse
    {
        return response()->json([
            'public_key' => config('app.vapid_public_key'),
        ]);
    }

    /** Store or update a push subscription for the current user. */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'endpoint'         => 'required|string|url',
            'keys.p256dh'      => 'required|string',
            'keys.auth'        => 'required|string',
            'content_encoding' => 'nullable|string',
        ]);

        PushSubscription::updateOrCreate(
            ['endpoint' => $request->endpoint],
            [
                'user_id'          => $request->user()->id,
                'public_key'       => $request->input('keys.p256dh'),
                'auth_token'       => $request->input('keys.auth'),
                'content_encoding' => $request->input('content_encoding', 'aesgcm'),
            ]
        );

        return response()->json(['success' => true]);
    }

    /** Remove a subscription (user unsubscribed from the browser). */
    public function destroy(Request $request): JsonResponse
    {
        $request->validate(['endpoint' => 'required|string']);

        PushSubscription::where('user_id', $request->user()->id)
            ->where('endpoint', $request->endpoint)
            ->delete();

        return response()->json(['success' => true]);
    }

    /** Check if current user has an active push subscription. */
    public function status(Request $request): JsonResponse
    {
        $endpoint = $request->query('endpoint');

        $active = $endpoint
            ? PushSubscription::where('user_id', $request->user()->id)
                ->where('endpoint', $endpoint)
                ->exists()
            : PushSubscription::where('user_id', $request->user()->id)->exists();

        return response()->json(['active' => $active]);
    }
}
