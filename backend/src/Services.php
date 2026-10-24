<?php

declare(strict_types=1);

namespace Wohnpilot;

use RuntimeException;
use Wohnpilot\App\AgendaService;
use Wohnpilot\App\AppointmentService;
use Wohnpilot\App\Clock;
use Wohnpilot\App\ContactService;
use Wohnpilot\App\ContractService;
use Wohnpilot\App\DeadlineService;
use Wohnpilot\App\DefectService;
use Wohnpilot\App\DeviceService;
use Wohnpilot\App\DocumentService;
use Wohnpilot\App\EntryService;
use Wohnpilot\App\HouseholdService;
use Wohnpilot\App\KnowledgeService;
use Wohnpilot\App\LeaseService;
use Wohnpilot\App\MaintenanceService;
use Wohnpilot\App\MeterService;
use Wohnpilot\App\NoteRepository;
use Wohnpilot\App\ObjectNotes;
use Wohnpilot\App\PhotoService;
use Wohnpilot\App\SearchService;
use Wohnpilot\App\SettingsService;
use Wohnpilot\App\KioskService;
use Wohnpilot\App\ReportService;
use Wohnpilot\App\UtilityService;
use Wohnpilot\App\WasteService;
use Wohnpilot\Auth\KioskDevices;
use Wohnpilot\Auth\AccountStore;
use Wohnpilot\Auth\BearId;
use Wohnpilot\Auth\DevLogin;
use Wohnpilot\Auth\LoginProvider;
use Wohnpilot\Auth\NativeSession;
use Wohnpilot\Auth\Session;
use Wohnpilot\Calendar\CalendarFetcher;
use Wohnpilot\Calendar\CalendarLinks;
use Wohnpilot\Calendar\IcsFeed;
use Wohnpilot\Http\IdempotencyStore;
use Wohnpilot\Index\NoteIndex;
use Wohnpilot\Push\MorningPush;
use Wohnpilot\Push\PushSender;
use Wohnpilot\Push\PushSubscriptions;
use Wohnpilot\Push\WebPushSender;
use Wohnpilot\Storage\AttachmentStore;
use Wohnpilot\Storage\FilesystemAdapter;
use Wohnpilot\Storage\NextcloudFiles;
use Wohnpilot\Storage\NextcloudNotesAdapter;
use Wohnpilot\Storage\StorageAdapter;

/** Builds the app's objects from the config, each once and only when needed. */
class Services
{
    private array $instances = [];

    public function __construct(public readonly Config $config, private readonly string $backendDir)
    {
    }

    public function storage(): StorageAdapter
    {
        return $this->instances[__FUNCTION__] ??= match ($this->config->get('STORAGE', 'nextcloud')) {
            'nextcloud' => NextcloudNotesAdapter::connect(
                $this->config->require('NEXTCLOUD_URL'),
                $this->config->require('NEXTCLOUD_USER'),
                $this->config->require('NEXTCLOUD_APP_PASSWORD'),
                $this->config->get('NEXTCLOUD_FOLDER', 'Wohnpilot'),
            ),
            'filesystem' => new FilesystemAdapter($this->path($this->config->get('FILESYSTEM_ROOT', 'var/notes'))),
            default => throw new RuntimeException('STORAGE must be nextcloud or filesystem'),
        };
    }

    /** Photos: the local folder itself, or WebDAV next to the notes in Nextcloud. */
    public function files(): AttachmentStore
    {
        if (isset($this->instances[__FUNCTION__])) {
            return $this->instances[__FUNCTION__];
        }
        $storage = $this->storage();
        return $this->instances[__FUNCTION__] = $storage instanceof AttachmentStore ? $storage : NextcloudFiles::connect(
            $this->config->require('NEXTCLOUD_URL'),
            $this->config->require('NEXTCLOUD_USER'),
            $this->config->require('NEXTCLOUD_APP_PASSWORD'),
            $storage,
            $this->config->get('NEXTCLOUD_FOLDER', 'Wohnpilot'),
        );
    }

    public function documents(): DocumentService
    {
        return $this->instances[__FUNCTION__] ??= new DocumentService($this->notes(), $this->files(), $this->clock());
    }

    public function contacts(): ContactService
    {
        return $this->instances[__FUNCTION__] ??= new ContactService($this->notes(), $this->household());
    }

    public function knowledge(): KnowledgeService
    {
        return $this->instances[__FUNCTION__] ??= new KnowledgeService($this->notes(), $this->objectNotes(), $this->contacts());
    }

    public function search(): SearchService
    {
        return $this->instances[__FUNCTION__] ??= new SearchService($this->notes(), $this->household());
    }

    /** Answers to repeated writes of the offline queue (settings file, not a cache). */
    public function idempotency(): IdempotencyStore
    {
        return $this->instances[__FUNCTION__] ??= IdempotencyStore::open($this->path($this->config->get('SETTINGS_FILE', 'var/settings.sqlite')));
    }

    public function photos(): PhotoService
    {
        return $this->instances[__FUNCTION__] ??= new PhotoService($this->notes(), $this->files(), $this->clock());
    }

    public function index(): NoteIndex
    {
        return $this->instances[__FUNCTION__] ??= NoteIndex::open($this->path($this->config->get('INDEX_FILE', 'var/index.sqlite')));
    }

    public function notes(): NoteRepository
    {
        return $this->instances[__FUNCTION__] ??= new NoteRepository($this->storage(), $this->index(), (int) $this->config->get('SYNC_MAX_AGE', '30'));
    }

    public function clock(): Clock
    {
        return $this->instances[__FUNCTION__] ??= new Clock();
    }

    public function household(): HouseholdService
    {
        return $this->instances[__FUNCTION__] ??= new HouseholdService($this->notes(), $this->config->list('WOHNPILOT_USERS'));
    }

    public function meters(): MeterService
    {
        return $this->instances[__FUNCTION__] ??= new MeterService($this->notes(), $this->household(), $this->clock(), $this->files());
    }

    public function contracts(): ContractService
    {
        return $this->instances[__FUNCTION__] ??= new ContractService($this->notes(), $this->clock());
    }

    public function lease(): LeaseService
    {
        return $this->instances[__FUNCTION__] ??= new LeaseService($this->notes(), $this->household(), $this->clock());
    }

    public function deadlines(): DeadlineService
    {
        return $this->instances[__FUNCTION__] ??= new DeadlineService($this->notes(), $this->household(), $this->meters(), $this->clock());
    }

    public function objectNotes(): ObjectNotes
    {
        return $this->instances[__FUNCTION__] ??= new ObjectNotes($this->notes(), $this->household());
    }

    public function maintenance(): MaintenanceService
    {
        return $this->instances[__FUNCTION__] ??= new MaintenanceService($this->notes(), $this->objectNotes(), $this->household(), $this->clock());
    }

    public function appointments(): AppointmentService
    {
        return $this->instances[__FUNCTION__] ??= new AppointmentService($this->notes(), $this->objectNotes(), $this->clock());
    }

    public function defects(): DefectService
    {
        return $this->instances[__FUNCTION__] ??= new DefectService($this->notes(), $this->objectNotes(), $this->household(), $this->clock());
    }

    public function devices(): DeviceService
    {
        return $this->instances[__FUNCTION__] ??= new DeviceService($this->notes(), $this->objectNotes(), $this->maintenance(), $this->clock());
    }

    public function calendarFetcher(): CalendarFetcher
    {
        return $this->instances[__FUNCTION__] ??= new CalendarFetcher();
    }

    /** Waste calendar; the downloaded copy is a cache in CACHE_DIR. */
    public function waste(): WasteService
    {
        return $this->instances[__FUNCTION__] ??= new WasteService(
            $this->notes(),
            $this->objectNotes(),
            $this->calendarFetcher(),
            $this->path($this->config->get('CACHE_DIR', 'var/cache')),
            $this->clock(),
        );
    }

    public function settings(): SettingsService
    {
        return $this->instances[__FUNCTION__] ??= new SettingsService($this->notes(), $this->household());
    }

    public function reports(): ReportService
    {
        return $this->instances[__FUNCTION__] ??= new ReportService($this->notes(), $this->household(), $this->meters(), $this->lease(), $this->utilities(), $this->defects(), $this->clock());
    }

    public function entries(): EntryService
    {
        return $this->instances[__FUNCTION__] ??= new EntryService($this->notes(), $this->files());
    }

    public function utilities(): UtilityService
    {
        return $this->instances[__FUNCTION__] ??= new UtilityService($this->notes(), $this->household(), $this->meters(), $this->lease(), $this->clock());
    }

    public function agenda(): AgendaService
    {
        return $this->instances[__FUNCTION__] ??= new AgendaService(
            $this->contracts(),
            $this->lease(),
            $this->deadlines(),
            $this->clock(),
            $this->maintenance(),
            $this->appointments(),
            $this->defects(),
            $this->devices(),
            $this->waste(),
            $this->utilities(),
        );
    }

    public function session(): Session
    {
        return $this->instances[__FUNCTION__] ??= new NativeSession(str_starts_with($this->appUrl(), 'https://'));
    }

    /**
     * Sign-in by redirect (GET /api/auth/login → callback). Today only the dev
     * login with APP_ENV=dev; the BearID guard will plug in here later.
     */
    public function redirectLogin(): ?LoginProvider
    {
        if ($this->config->isDev() && $this->config->get('DEV_LOGIN_USER') !== '') {
            return $this->instances[__FUNCTION__] ??= new DevLogin($this->config->get('DEV_LOGIN_USER'), $this->appUrl() . '/api/auth/callback');
        }
        return null;
    }

    /**
     * BearID sign-in, if BEARID_ISSUER and BEARID_PUBLIC_KEY_FILE
     * (bearid-guard/public.key on the webspace) are set.
     */
    public function bearId(): ?BearId
    {
        if (array_key_exists(__FUNCTION__, $this->instances)) {
            return $this->instances[__FUNCTION__];
        }
        $issuer = $this->config->get('BEARID_ISSUER');
        $keyFile = $this->config->get('BEARID_PUBLIC_KEY_FILE');
        $key = $issuer !== '' && $keyFile !== '' && is_readable($this->path($keyFile)) ? trim((string) file_get_contents($this->path($keyFile))) : '';
        if ($issuer !== '' && $keyFile !== '' && $key === '') {
            error_log("Wohnpilot: BearID public key not readable at $keyFile");
        }
        return $this->instances[__FUNCTION__] = $key === '' ? null : new BearId($issuer, $key, $this->config->get('BEARID_APP', 'wohnpilot'));
    }

    /** E-mail and password accounts (not a cache: belongs in the backup). */
    public function accounts(): AccountStore
    {
        return $this->instances[__FUNCTION__] ??= AccountStore::open($this->path($this->config->get('ACCOUNTS_FILE', 'var/accounts.sqlite')));
    }

    /** Personal settings like the calendar link (not a cache: belongs in the backup). */
    public function kioskDevices(): KioskDevices
    {
        return $this->instances[__FUNCTION__] ??= KioskDevices::open($this->path($this->config->get('SETTINGS_FILE', 'var/settings.sqlite')));
    }

    public function kiosk(): KioskService
    {
        return $this->instances[__FUNCTION__] ??= new KioskService($this->meters(), $this->agenda(), $this->waste(), $this->household(), $this->clock());
    }

    public function calendarLinks(): CalendarLinks
    {
        return $this->instances[__FUNCTION__] ??= CalendarLinks::open($this->path($this->config->get('SETTINGS_FILE', 'var/settings.sqlite')));
    }

    public function pushSubscriptions(): PushSubscriptions
    {
        return $this->instances[__FUNCTION__] ??= PushSubscriptions::open($this->path($this->config->get('SETTINGS_FILE', 'var/settings.sqlite')));
    }

    /** Public VAPID key for the browser, or '' while push is not set up. */
    public function vapidPublicKey(): string
    {
        return $this->config->get('VAPID_PRIVATE_KEY') === '' ? '' : $this->config->get('VAPID_PUBLIC_KEY');
    }

    /** Null while VAPID_PUBLIC_KEY / VAPID_PRIVATE_KEY are missing. */
    public function pushSender(): ?PushSender
    {
        if (array_key_exists(__FUNCTION__, $this->instances)) {
            return $this->instances[__FUNCTION__];
        }
        $public = $this->vapidPublicKey();
        return $this->instances[__FUNCTION__] = $public === '' ? null : new WebPushSender(
            $public,
            $this->config->get('VAPID_PRIVATE_KEY'),
            $this->config->get('VAPID_SUBJECT', $this->appUrl()),
        );
    }

    public function morningPush(): ?MorningPush
    {
        $sender = $this->pushSender();
        return $sender === null ? null : new MorningPush($this->pushSubscriptions(), $sender);
    }

    public function calendarFeed(): IcsFeed
    {
        return $this->instances[__FUNCTION__] ??= new IcsFeed($this->appUrl());
    }

    public function appUrl(): string
    {
        return rtrim($this->config->get('APP_URL', 'http://localhost:8080'), '/');
    }

    private function path(string $path): string
    {
        return preg_match('#^([a-zA-Z]:)?[\\\\/]#', $path) ? $path : $this->backendDir . '/' . $path;
    }
}
