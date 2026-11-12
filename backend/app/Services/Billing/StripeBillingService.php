<?php

namespace App\Services\Billing;

use App\Models\CreditLedger;
use App\Models\Subscription;
use App\Models\User;
use Stripe\Checkout\Session;
use Stripe\Stripe;
use Stripe\Webhook;

class StripeBillingService
{
    public function __construct()
    {
        Stripe::setApiKey((string) config('crime_report.stripe.secret'));
    }

    public function plans(): array
    {
        return config('crime_report.plans');
    }

    public function createCheckoutSession(User $user, string $planKey): Session
    {
        $priceId = config('crime_report.stripe.prices.'.$planKey);
        if (! $priceId) {
            throw new \InvalidArgumentException('Unknown or unconfigured plan: '.$planKey);
        }

        if (! $user->stripe_customer_id) {
            $customer = \Stripe\Customer::create([
                'email' => $user->email,
                'name' => $user->name,
                'metadata' => ['user_id' => (string) $user->_id],
            ]);
            $user->stripe_customer_id = $customer->id;
            $user->save();
        }

        $mode = $planKey === 'credits_pack' ? 'payment' : 'subscription';

        return Session::create([
            'mode' => $mode,
            'customer' => $user->stripe_customer_id,
            'line_items' => [['price' => $priceId, 'quantity' => 1]],
            'success_url' => rtrim((string) config('crime_report.frontend_url'), '/').'/billing/success?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => rtrim((string) config('crime_report.frontend_url'), '/').'/pricing',
            'metadata' => [
                'user_id' => (string) $user->_id,
                'plan' => $planKey,
            ],
        ]);
    }

    public function createPortal(User $user): string
    {
        if (! $user->stripe_customer_id) {
            throw new \RuntimeException('No Stripe customer on file.');
        }

        $session = \Stripe\BillingPortal\Session::create([
            'customer' => $user->stripe_customer_id,
            'return_url' => rtrim((string) config('crime_report.frontend_url'), '/').'/dashboard',
        ]);

        return $session->url;
    }

    public function handleWebhook(string $payload, ?string $signature): void
    {
        $secret = config('crime_report.stripe.webhook_secret');
        $event = $secret
            ? Webhook::constructEvent($payload, (string) $signature, $secret)
            : json_decode($payload);

        $type = is_object($event) ? ($event->type ?? null) : ($event['type'] ?? null);
        $object = is_object($event) ? ($event->data->object ?? null) : ($event['data']['object'] ?? null);

        if ($type === 'checkout.session.completed' && $object) {
            $this->onCheckoutCompleted($object);
        }

        if (in_array($type, ['customer.subscription.updated', 'customer.subscription.deleted'], true) && $object) {
            $this->onSubscriptionChange($object);
        }
    }

    private function onCheckoutCompleted(object $session): void
    {
        $userId = $session->metadata->user_id ?? null;
        $plan = $session->metadata->plan ?? null;
        if (! $userId || ! $plan) {
            return;
        }

        $user = User::query()->find($userId);
        if (! $user) {
            return;
        }

        if ($plan === 'credits_pack') {
            $user->credits = (int) $user->credits + 10;
            $user->save();
            CreditLedger::query()->create([
                'user_id' => (string) $user->_id,
                'delta' => 10,
                'reason' => 'credits_pack_purchase',
                'meta' => ['session_id' => $session->id ?? null],
            ]);

            return;
        }

        $planConfig = config('crime_report.plans.'.$plan, []);
        $user->plan = $plan;
        $user->credits = (int) ($planConfig['credits'] ?? $user->credits);
        $user->max_radius_km = (int) ($planConfig['max_radius_km'] ?? 1);
        $user->save();

        Subscription::query()->updateOrCreate(
            ['user_id' => (string) $user->_id],
            [
                'stripe_subscription_id' => $session->subscription ?? null,
                'plan' => $plan,
                'status' => 'active',
            ]
        );

        CreditLedger::query()->create([
            'user_id' => (string) $user->_id,
            'delta' => (int) ($planConfig['credits'] ?? 0),
            'reason' => 'subscription_'.$plan,
            'meta' => ['session_id' => $session->id ?? null],
        ]);
    }

    private function onSubscriptionChange(object $subscription): void
    {
        $sub = Subscription::query()
            ->where('stripe_subscription_id', $subscription->id ?? null)
            ->first();
        if (! $sub) {
            return;
        }
        $sub->status = $subscription->status ?? $sub->status;
        $sub->save();

        if (($subscription->status ?? null) === 'canceled') {
            $user = User::query()->find($sub->user_id);
            if ($user) {
                $user->plan = 'free';
                $user->max_radius_km = (int) config('crime_report.free_radius_km');
                $user->save();
            }
        }
    }
}
