<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

#[Signature('app:create-user {--name=} {--email=} {--password=}')]
#[Description('Crea un usuario para entrar a la app')]
class CreateUser extends Command
{
    public function handle(): int
    {
        $name = $this->option('name') ?: text('Nombre', required: true);
        $email = Str::lower(trim($this->option('email') ?: text('Correo', required: true)));
        $password = $this->option('password') ?: password('Contraseña', required: true);

        if (User::where('email', $email)->exists()) {
            $this->error("Ya existe un usuario con {$email}.");

            return self::FAILURE;
        }

        User::create(compact('name', 'email', 'password'));
        $this->info("Usuario {$email} creado.");

        return self::SUCCESS;
    }
}
