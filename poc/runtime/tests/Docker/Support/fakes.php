<?php

declare(strict_types=1);

// Test support (runs INSIDE the sealed container): starts the target app the way measure-one does, then pokes every
// fake and prints what happened as one JSON object.
//   php fakes.php <scale>

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PeakSim\Runtime\Boot\AppBooter;
use PeakSim\Runtime\Config\MeasureConfig;
use PeakSim\Runtime\Run\Classification;

require dirname(__DIR__, 3) . '/bootstrap.php';

$config = MeasureConfig::fromEnv(getenv());
$booted = (new AppBooter())->boot($config, (int) ($argv[1] ?? 0));
$out = ['report' => $booted->report];

$mailable = new class extends Illuminate\Mail\Mailable {
    public function build(): static
    {
        return $this->html('x');
    }
};
Mail::to('a@example.test')->send($mailable);
$out['mail_recorded'] = Mail::sent(get_class($mailable))->count();

$job = new class {
    public function handle(): void
    {
    }
};
Queue::push($job);
$out['queue_recorded'] = Queue::pushed(get_class($job))->count();

$notifiable = new class {
    use Illuminate\Notifications\Notifiable;

    public function routeNotificationForMail(): string
    {
        return 'a@example.test';
    }

    public function getKey(): int
    {
        return 1;
    }
};
$notification = new class extends Illuminate\Notifications\Notification {
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): Illuminate\Notifications\Messages\MailMessage
    {
        return (new Illuminate\Notifications\Messages\MailMessage())->line('x');
    }
};
Notification::send($notifiable, $notification);
$out['notification_recorded'] = Notification::sent($notifiable, get_class($notification))->count();

try {
    Http::get('https://api.example.test/v1/users?token=SECRET');
    $out['http'] = 'no exception';
} catch (Throwable $e) {
    $out['http'] = get_class($e);
}
$out['external_hosts'] = $booted->externalCalls->hosts();

$started = microtime(true);
try {
    (new GuzzleHttp\Client(['connect_timeout' => 3, 'timeout' => 3]))->get('http://example.com/');
    $out['guzzle'] = 'no exception';
} catch (Throwable $e) {
    $out['guzzle'] = [get_class($e), Classification::fromThrowable(get_class($e), $e->getMessage())];
}
$out['guzzle_seconds'] = round(microtime(true) - $started, 1);

$started = microtime(true);
try {
    $read = file_get_contents('http://example.com/');
    $out['file_get_contents'] = [$read === false ? 'failed' : 'succeeded', 'no exception'];
} catch (Throwable $e) {
    // the app's error handler turns the warning into an ErrorException, as it does in a real request
    $out['file_get_contents'] = ['failed', Classification::fromThrowable(get_class($e), $e->getMessage())];
}
$out['file_get_contents_seconds'] = round(microtime(true) - $started, 1);

$fired = false;
Event::listen('probe.event', static function () use (&$fired): void {
    $fired = true;
});
Event::dispatch('probe.event');
$out['event_listener_ran'] = $fired;
$out['event_dispatcher'] = get_class(Event::getFacadeRoot());

Cache::put('k', 'v', 60);
$seen = Cache::get('k');
Cache::flush();
$out['cache'] = [$seen, Cache::get('k')];

Storage::disk('local')->put('probe.txt', 'x');
$out['storage_local'] = Storage::disk('local')->exists('probe.txt');
Storage::disk('s3')->put('probe.txt', 'x');
$out['storage_s3_replaced'] = Storage::disk('s3')->exists('probe.txt');

$out['connections'] = [];
foreach (array_keys((array) config('database.connections')) as $name) {
    $out['connections'][$name] = Illuminate\Support\Facades\DB::connection($name)->getDatabaseName();
}
$out['select_database'] = Illuminate\Support\Facades\DB::selectOne('select database() as db')->db;
$out['scratch'] = $booted->scratch->path;
$out['storage_path'] = storage_path();

echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
