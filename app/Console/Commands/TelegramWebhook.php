<?php

namespace App\Console\Commands;

use App\Support\Telegram;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('telegram:webhook {--delete : Quita el webhook sin descartar mensajes pendientes}')]
#[Description('Registra o quita el webhook de Telegram')]
class TelegramWebhook extends Command
{
    public function handle(): int
    {
        if ($this->option('delete')) {
            $ok = Telegram::call('deleteWebhook', ['drop_pending_updates' => false]) !== null;

            if (! $ok) {
                $this->error('No se pudo quitar el webhook. Revisa la conexión y el token del bot en Tienda en línea.');

                return self::FAILURE;
            }

            $this->info('Webhook de Telegram eliminado. Se conservaron los mensajes pendientes.');

            return self::SUCCESS;
        }

        if (! Telegram::canUseWebhook()) {
            $this->warn('Telegram requiere una URL con HTTPS para el webhook. En desarrollo usa php artisan telegram:poll.');

            return self::FAILURE;
        }

        if (! Telegram::enabled()) {
            $this->error('El bot de Telegram no está conectado o está apagado. Conéctalo en Tienda en línea.');

            return self::FAILURE;
        }

        if (! Telegram::register()) {
            $this->error('No se pudo registrar el webhook de Telegram. Revisa la URL pública y la conexión.');

            return self::FAILURE;
        }

        $this->info('Webhook de Telegram registrado: '.Telegram::webhookUrl());

        return self::SUCCESS;
    }
}
