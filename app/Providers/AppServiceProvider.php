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
                    return new QueueSmsSender();
                case 'python':
                default:
                    return new PythonApiSmsSender();
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
