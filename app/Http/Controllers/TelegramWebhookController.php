<?php

namespace App\Http\Controllers;

use App\Support\Telegram;
use App\Support\TelegramBot;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Throwable;

/** Recibe los mensajes que Telegram manda al bot. Solo acepta peticiones con la clave secreta que se le dio al registrar el webhook. */
class TelegramWebhookController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $secret = Telegram::webhookSecret();

        // Sin bot conectado esta ruta no existe para nadie.
        abort_unless($secret !== null && Telegram::enabled(), 404);
        abort_unless(hash_equals($secret, (string) $request->header('X-Telegram-Bot-Api-Secret-Token')), 403);

        $update = $request->json()->all();
        $updateId = $update['update_id'] ?? null;

        // Telegram reintenta si tarda en recibir respuesta: un mismo update no se procesa dos veces.
        if (is_int($updateId) && ! Cache::add('telegram:update:'.$updateId, true, 600)) {
            return response('', 200);
        }

        try {
            TelegramBot::handle($update);
        } catch (Throwable $e) {
            // Siempre se responde 200: si no, Telegram reenviaría el mismo mensaje una y otra vez.
            report($e);
        }

        return response('', 200);
    }
}
