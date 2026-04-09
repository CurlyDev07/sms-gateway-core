<?php

namespace App\Providers;

use App\Contracts\SmsSenderInterface;
use App\Services\Sms\PythonApiSmsSender;
use App\Services\Sms\QueueSmsSender;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->bind(SmsSenderInterface::class, function () {
            $driver = config('sms.driver');

            switch ($driver) {
                case 'queue':
                    return $this->app->make(QueueSmsSender::class);
                case 'python':
                default:
                    return $this->app->make(PythonApiSmsSender::class);
            }
        });
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        //
    }
}
