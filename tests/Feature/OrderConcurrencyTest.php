<?php

namespace Tests\Feature;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OrderConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_two_connections_replace_payments_without_duplicating_them(): void
    {
        $connection = config('database.connections.pgsql');
        $this->assertStringStartsWith('taqueria_test', $connection['database']);
        config(['database.connections.order_race' => $connection]);
        $fixtures = DB::connection('order_race');
        $directory = sys_get_temp_dir().'/order-race-'.bin2hex(random_bytes(8));
        mkdir($directory);

        // Esta conexión confirma solo la venta de prueba para que ambos procesos puedan verla.
        $id = $fixtures->table('orders')->insertGetId([
            'status' => 'paid', 'payment_method' => 'cash', 'total' => 100,
            'sold_at' => now(), 'paid_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $fixtures->table('order_payments')->insert([
            'order_id' => $id, 'method' => 'cash', 'amount' => 100, 'created_at' => now(), 'updated_at' => now(),
        ]);

        try {
            Concurrency::driver('process')->run([
                static function () use ($connection, $id, $directory) {
                    config(['database.default' => 'pgsql', 'database.connections.pgsql' => $connection]);
                    DB::purge('pgsql');
                    DB::listen(static function ($query) use ($directory) {
                        if (str_starts_with($query->sql, 'delete from "order_payments"')) {
                            touch($directory.'/deleted');
                            $deadline = microtime(true) + 10;
                            while (! is_file($directory.'/second-started') && microtime(true) < $deadline) {
                                usleep(10000);
                            }
                            if (! is_file($directory.'/second-started')) {
                                throw new \RuntimeException('El segundo proceso no inició.');
                            }
                            usleep(500000);
                        }
                    });
                    Order::findOrFail($id)->recordPayments('card');

                    return true;
                },
                static function () use ($connection, $id, $directory) {
                    config(['database.default' => 'pgsql', 'database.connections.pgsql' => $connection]);
                    DB::purge('pgsql');
                    $deadline = microtime(true) + 10;
                    while (! is_file($directory.'/deleted') && microtime(true) < $deadline) {
                        usleep(10000);
                    }
                    if (! is_file($directory.'/deleted')) {
                        throw new \RuntimeException('El primer proceso no llegó al reemplazo.');
                    }
                    touch($directory.'/second-started');
                    Order::findOrFail($id)->recordPayments('transfer');

                    return true;
                },
            ], timeout: 20);

            $payments = $fixtures->table('order_payments')->where('order_id', $id)->get();
            $this->assertCount(1, $payments);
            $this->assertEquals(100, $payments->sum('amount'));
            $this->assertSame('transfer', $payments->sole()->method);
            $this->assertSame('transfer', $fixtures->table('orders')->where('id', $id)->value('payment_method'));
        } finally {
            $fixtures->table('orders')->where('id', $id)->delete();
            DB::purge('order_race');
            foreach (glob($directory.'/*') as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }
}
