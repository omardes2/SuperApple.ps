<?php

namespace App\Http\Controllers;

use App\Services\WhatsApp\WhatsAppWebhookService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Public callback endpoint for the Meta WhatsApp Cloud API. GET performs the
 * one-time verification handshake; POST receives delivery statuses and inbound
 * messages. No app authentication (Meta calls it) — it is protected by the
 * verify token and the optional payload signature, and is CSRF-exempt.
 */
class WhatsAppWebhookController extends Controller
{
    public function __construct(private readonly WhatsAppWebhookService $webhook) {}

    /** Meta's verification handshake: echo hub.challenge when the token matches. */
    public function verify(Request $request): Response
    {
        $token = $this->webhook->verifyToken();

        if ($token !== ''
            && $request->query('hub_mode') === 'subscribe'
            && (string) $request->query('hub_verify_token') === $token) {
            return response((string) $request->query('hub_challenge'), 200)
                ->header('Content-Type', 'text/plain');
        }

        return response('Forbidden', 403);
    }

    /** Receive status/inbound events. Always 200 quickly so Meta stops retrying. */
    public function receive(Request $request): Response
    {
        if (! $this->webhook->signatureValid($request->getContent(), $request->header('X-Hub-Signature-256'))) {
            return response('Invalid signature', 403);
        }

        $this->webhook->process($request->json()->all());

        return response('', 200);
    }
}
