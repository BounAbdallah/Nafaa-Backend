<?php

namespace App\Services;

use App\Models\PushSubscription;
use App\Models\User;
use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

class PushNotificationService
{
    private WebPush $webPush;

    public function __construct()
    {
        $this->webPush = new WebPush([
            'VAPID' => [
                'subject'    => config('app.vapid_subject', 'mailto:support@qiwam.app'),
                'publicKey'  => config('app.vapid_public_key'),
                'privateKey' => config('app.vapid_private_key'),
            ],
        ]);

        $this->webPush->setReuseVAPIDHeaders(true);
        $this->webPush->setDefaultOptions(['TTL' => 86400]); // 24h
    }

    /**
     * Send a push notification to all subscriptions of a user.
     */
    public function sendToUser(User $user, string $title, string $body, array $data = []): void
    {
        $subscriptions = PushSubscription::where('user_id', $user->id)->get();

        if ($subscriptions->isEmpty()) return;

        $payload = json_encode([
            'title' => $title,
            'body'  => $body,
            'icon'  => '/icons/icon-192x192.png',
            'badge' => '/icons/icon-32x32.png',
            'data'  => array_merge(['url' => '/dashboard'], $data),
        ]);

        foreach ($subscriptions as $sub) {
            $this->webPush->queueNotification(
                Subscription::create([
                    'endpoint'        => $sub->endpoint,
                    'keys'            => [
                        'p256dh' => $sub->public_key,
                        'auth'   => $sub->auth_token,
                    ],
                    'contentEncoding' => $sub->content_encoding,
                ]),
                $payload
            );
        }

        // Flush and clean up expired subscriptions
        foreach ($this->webPush->flush() as $report) {
            if (! $report->isSuccess()) {
                // 410 Gone = subscription expired, remove it
                PushSubscription::where('endpoint', $report->getEndpoint())->delete();
            }
        }
    }

    /**
     * Send to all admin/owner users of a tenant.
     */
    public function sendToTenantAdmins(int $tenantId, string $title, string $body, array $data = []): void
    {
        $admins = User::where('tenant_id', $tenantId)
            ->where('role', 'owner')
            ->get();

        foreach ($admins as $admin) {
            $this->sendToUser($admin, $title, $body, $data);
        }
    }
}
