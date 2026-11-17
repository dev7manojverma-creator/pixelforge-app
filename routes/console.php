<?php

use Illuminate\Support\Facades\Schedule;

// Polling fallback for generations whose webhooks never arrived.
Schedule::command('pixelforge:reconcile')->everyMinute()->withoutOverlapping();
