<?php

declare(strict_types=1);

namespace Wohnpilot\Http;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\App;
use Slim\Factory\AppFactory;
use Slim\Routing\RouteCollectorProxy;
use Throwable;
use Wohnpilot\App\AppException;
use Wohnpilot\App\WasteService;
use Wohnpilot\App\User;
use Wohnpilot\Auth\BearId;
use Wohnpilot\Auth\Identity;
use Wohnpilot\Auth\LoginFailed;
use Wohnpilot\Services;
use Wohnpilot\Storage\StorageException;

/**
 * The JSON API under /api. Everything except /api/auth/* needs a signed-in
 * person of the household. Writing requests must carry the header
 * "X-Requested-With: wohnpilot", which other sites cannot send without CORS.
 */
final class Api
{
    public static function create(Services $services): App
    {
        $app = AppFactory::create();
        $app->addBodyParsingMiddleware();
        $app->addRoutingMiddleware();

        $app->group('/api/auth', function (RouteCollectorProxy $auth) use ($services): void {
            // Which ways to sign in the login page offers.
            $auth->get('/methods', fn (Request $r, Response $response) => self::json($response, [
                'bearid' => $services->bearId() !== null,
                'password' => true,
                'redirect' => $services->redirectLogin() !== null ? 'dev' : null,
            ]));

            // BearID: take the person from the bearid cookie, or go to BearID and come back here once.
            $auth->get('/bearid', function (Request $request, Response $response) use ($services): Response {
                $bearId = $services->bearId() ?? throw AppException::notFound('BearID login');
                try {
                    $identity = $bearId->identity($request->getCookieParams());
                } catch (LoginFailed) {
                    return $response->withHeader('Location', '/?login=nicht-freigegeben')->withStatus(302);
                }
                if ($identity === null) {
                    $target = isset($request->getQueryParams()['zurueck'])
                        ? '/?login=fehlgeschlagen'
                        : $bearId->loginUrl($services->appUrl() . '/api/auth/bearid?zurueck=1');
                    return $response->withHeader('Location', $target)->withStatus(302);
                }
                $services->notes()->refresh();
                if ($services->household()->userFor($identity) === null) {
                    return $response->withHeader('Location', '/?login=kein-mitglied')->withStatus(302);
                }
                self::startSession($services, $identity);
                return $response->withHeader('Location', '/')->withStatus(302);
            });

            $auth->post('/password', function (Request $request, Response $response) use ($services): Response {
                $body = self::body($request);
                $email = trim((string) ($body['email'] ?? ''));
                $password = (string) ($body['password'] ?? '');
                if ($email === '' || $password === '') {
                    throw AppException::validation(array_filter(['email' => $email === '' ? 'required' : null, 'password' => $password === '' ? 'required' : null]));
                }
                $ip = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? 'unknown');
                try {
                    $identity = $services->accounts()->verify($email, $password, $ip);
                } catch (LoginFailed $e) {
                    throw $e->getMessage() === 'rate_limited'
                        ? new AppException('rate_limited', 429, 'Too many failed attempts')
                        : new AppException('invalid_login', 401, 'E-mail or password wrong');
                }
                $services->notes()->refresh();
                $user = $services->household()->userFor($identity);
                if ($user === null) {
                    throw new AppException('forbidden', 403, 'Not a member of this household');
                }
                self::startSession($services, $identity);
                return self::json($response, ['user' => $user->toArray()]);
            });

            $auth->get('/login', function (Request $request, Response $response) use ($services): Response {
                $login = $services->redirectLogin() ?? throw AppException::notFound('Redirect login');
                return $response->withHeader('Location', $login->start($services->session()))->withStatus(302);
            });
            $auth->get('/callback', function (Request $request, Response $response) use ($services): Response {
                $session = $services->session();
                $login = $services->redirectLogin() ?? throw AppException::notFound('Redirect login');
                try {
                    $identity = $login->finish($session, $request->getQueryParams());
                } catch (LoginFailed $e) {
                    error_log('Wohnpilot login failed: ' . $e->getMessage());
                    return $response->withHeader('Location', '/?login=fehlgeschlagen')->withStatus(302);
                }
                self::startSession($services, $identity);
                return $response->withHeader('Location', '/')->withStatus(302);
            });

            // While a BearID cookie is valid it would sign you in again at once,
            // so logging out also leads through BearID's logout.
            $auth->post('/logout', function (Request $request, Response $response) use ($services): Response {
                $services->session()->destroy();
                $redirect = null;
                $bearId = $services->bearId();
                if ($bearId !== null && $bearId->verify((string) ($request->getCookieParams()[BearId::COOKIE] ?? '')) !== null) {
                    $redirect = $bearId->logoutUrl($services->appUrl() . '/');
                }
                return self::json($response, ['redirect' => $redirect]);
            });
        });

        // Calendar apps cannot sign in: the secret link is the key (CalendarLinks).
        $app->get('/api/calendar/{token:[A-Za-z0-9_-]+}.ics', function (Request $r, Response $response, array $args) use ($services): Response {
            if ($services->calendarLinks()->personFor($args['token']) === null) {
                throw AppException::notFound('Calendar');
            }
            $services->notes()->refresh();
            $agenda = $services->agenda()->forOverview([], WasteService::DAYS_AHEAD)['agenda'];
            $response->getBody()->write($services->calendarFeed()->build($agenda, gmdate('Ymd\THis\Z')));
            return $response
                ->withHeader('Content-Type', 'text/calendar; charset=utf-8')
                ->withHeader('Content-Disposition', 'inline; filename="wohnpilot.ics"');
        });

        $app->group('/api', function (RouteCollectorProxy $api) use ($services): void {
            $api->get('/me', fn (Request $request, Response $response) => self::json($response, ['user' => self::user($request)->toArray()]));

            $api->get('/overview', function (Request $r, Response $response) use ($services): Response {
                $overview = $services->meters()->overview();
                return self::json($response, $overview + $services->agenda()->forOverview($overview['meters']));
            });
            $api->get('/meters', fn (Request $r, Response $response) => self::json($response, ['meters' => $services->meters()->list()]));
            $api->post('/meters', fn (Request $request, Response $response) => self::json(
                $response,
                $services->meters()->create(self::body($request), self::user($request)),
                201,
            ));
            $api->get('/meters/{id}', fn (Request $r, Response $response, array $args) => self::json($response, $services->meters()->detail($args['id'])));
            $api->put('/meters/{id}', function (Request $request, Response $response, array $args) use ($services): Response {
                $body = self::body($request);
                return self::json($response, $services->meters()->update(
                    $args['id'],
                    (array) ($body['changes'] ?? []),
                    (array) ($body['original'] ?? []),
                    (string) ($body['etag'] ?? ''),
                    self::user($request),
                ));
            });
            // JSON, or multipart with the fields as text and the meter photo as "foto"
            $api->post('/meters/{id}/readings', function (Request $request, Response $response, array $args) use ($services): Response {
                $body = self::body($request);
                $file = $request->getUploadedFiles()['foto'] ?? null;
                $photo = null;
                if ($file instanceof UploadedFileInterface) {
                    if ($file->getError() !== UPLOAD_ERR_OK) {
                        throw AppException::validation(['foto' => in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 'too_large' : 'required']);
                    }
                    $photo = (string) $file->getStream();
                    if (is_string($body['value'] ?? null) && is_numeric($body['value'])) {
                        $body['value'] = (float) $body['value'];
                    }
                }
                return self::json($response, $services->meters()->addReading($args['id'], $body, self::user($request), $photo), 201);
            });

            $api->get('/objects/active', fn (Request $r, Response $response) => self::json($response, ['object' => $services->household()->activeObject()]));
            $api->post('/objects', fn (Request $request, Response $response) => self::json(
                $response,
                ['object' => $services->household()->createObject(self::body($request), self::user($request))],
                201,
            ));
            $api->put('/objects/{id}', function (Request $request, Response $response, array $args) use ($services): Response {
                $body = self::body($request);
                return self::json($response, ['object' => $services->household()->updateObject(
                    $args['id'],
                    (array) ($body['changes'] ?? []),
                    (array) ($body['original'] ?? []),
                    (string) ($body['etag'] ?? ''),
                    self::user($request),
                )]);
            });
            $api->get('/contracts', fn (Request $r, Response $response) => self::json($response, ['contracts' => $services->contracts()->list()]));
            $api->post('/contracts', fn (Request $request, Response $response) => self::json(
                $response,
                $services->contracts()->create(self::body($request), self::user($request)),
                201,
            ));
            $api->get('/contracts/{id}', fn (Request $r, Response $response, array $args) => self::json($response, $services->contracts()->detail($args['id'])));
            $api->put('/contracts/{id}', function (Request $request, Response $response, array $args) use ($services): Response {
                [$changes, $original, $etag] = self::merge($request);
                return self::json($response, $services->contracts()->update($args['id'], $changes, $original, $etag, self::user($request)));
            });
            $api->post('/contracts/{id}/prices', fn (Request $request, Response $response, array $args) => self::json(
                $response,
                $services->contracts()->addPrice($args['id'], self::body($request), self::user($request)),
                201,
            ));

            // The year in review and the § 35a list
            $api->get('/reports', fn (Request $r, Response $response) => self::json($response, ['years' => $services->reports()->years()]));
            $api->get('/reports/{year:[0-9]{4}}', fn (Request $r, Response $response, array $args) => self::json($response, $services->reports()->year((int) $args['year'])));
            $api->get('/reports/{year:[0-9]{4}}/steuer.csv', function (Request $r, Response $response, array $args) use ($services): Response {
                [$csv, $name] = $services->reports()->taxCsv((int) $args['year']);
                $response->getBody()->write($csv);
                return $response->withHeader('Content-Type', 'text/csv; charset=utf-8')->withHeader('Content-Disposition', 'attachment; filename="' . $name . '"');
            });

            // Utility statements (landlord, metering service)
            $api->get('/utilities', fn (Request $r, Response $response) => self::json($response, ['statements' => $services->utilities()->list()]));
            $api->post('/utilities', fn (Request $request, Response $response) => self::json($response, $services->utilities()->create(self::body($request), self::user($request)), 201));
            $api->get('/utilities/{id}', fn (Request $r, Response $response, array $args) => self::json($response, $services->utilities()->detail($args['id'])));
            $api->put('/utilities/{id}', function (Request $request, Response $response, array $args) use ($services): Response {
                [$changes, $original, $etag] = self::merge($request);
                return self::json($response, $services->utilities()->update($args['id'], $changes, $original, $etag, self::user($request)));
            });
            $api->put('/utilities/{id}/positions', function (Request $request, Response $response, array $args) use ($services): Response {
                $body = self::body($request);
                return self::json($response, $services->utilities()->setPositions($args['id'], (array) ($body['positionen'] ?? []), (array) ($body['original'] ?? []), self::user($request)));
            });
            $api->post('/utilities/{id}/prepayment', fn (Request $request, Response $response, array $args) => self::json(
                $response,
                $services->utilities()->adoptPrepayment($args['id'], self::user($request)),
            ));
            $api->put('/utilities/{id}/paid', function (Request $request, Response $response, array $args) use ($services): Response {
                $date = self::body($request)['datum'] ?? null;
                return self::json($response, $services->utilities()->setPaid($args['id'], is_string($date) ? $date : null, self::user($request)));
            });

            $api->get('/lease', fn (Request $r, Response $response) => self::json($response, ['lease' => $services->lease()->get()]));
            $api->post('/lease', fn (Request $request, Response $response) => self::json(
                $response,
                ['lease' => $services->lease()->create(self::body($request), self::user($request))],
                201,
            ));
            $api->put('/lease', function (Request $request, Response $response) use ($services): Response {
                [$changes, $original, $etag] = self::merge($request);
                return self::json($response, ['lease' => $services->lease()->update($changes, $original, $etag, self::user($request))]);
            });
            $api->post('/lease/rent', fn (Request $request, Response $response) => self::json(
                $response,
                ['lease' => $services->lease()->addRentChange(self::body($request), self::user($request))],
                201,
            ));
            $api->put('/lease/rent/{validFrom}/agreed', function (Request $request, Response $response, array $args) use ($services): Response {
                $date = self::body($request)['datum'] ?? null;
                return self::json($response, ['lease' => $services->lease()->agree($args['validFrom'], is_string($date) ? $date : null, self::user($request))]);
            });

            $api->get('/deadlines', fn (Request $r, Response $response) => self::json($response, $services->deadlines()->list()));
            $api->post('/deadlines', fn (Request $request, Response $response) => self::json(
                $response,
                $services->deadlines()->create(self::body($request), self::user($request)),
                201,
            ));
            $api->get('/deadlines/{id}', fn (Request $r, Response $response, array $args) => self::json($response, $services->deadlines()->detail($args['id'])));
            $api->put('/deadlines/{id}', function (Request $request, Response $response, array $args) use ($services): Response {
                [$changes, $original, $etag] = self::merge($request);
                return self::json($response, $services->deadlines()->update($args['id'], $changes, $original, $etag, self::user($request)));
            });
            $api->put('/deadlines/{id}/done', function (Request $request, Response $response, array $args) use ($services): Response {
                $date = self::body($request)['datum'] ?? null;
                return self::json($response, $services->deadlines()->setDone($args['id'], is_string($date) ? $date : null, self::user($request)));
            });

            // The person's own calendar link: status, a new one (the old one stops), or none.
            $api->get('/calendar', function (Request $request, Response $response) use ($services): Response {
                $created = $services->calendarLinks()->createdAt(self::user($request)->id);
                return self::json($response, ['active' => $created !== null, 'createdAt' => $created === null ? null : gmdate('c', $created)]);
            });
            $api->post('/calendar', function (Request $request, Response $response) use ($services): Response {
                $token = $services->calendarLinks()->create(self::user($request)->id);
                return self::json($response, ['active' => true, 'createdAt' => gmdate('c'), 'url' => $services->appUrl() . "/api/calendar/$token.ics"], 201);
            });
            $api->delete('/calendar', function (Request $request, Response $response) use ($services): Response {
                $services->calendarLinks()->revoke(self::user($request)->id);
                return $response->withStatus(204);
            });

            // Push: the key for the browser, devices of the signed-in person, a test message.
            $api->get('/push', fn (Request $r, Response $response) => self::json($response, [
                'publicKey' => $services->vapidPublicKey() ?: null,
            ]));
            $api->post('/push/subscriptions', function (Request $request, Response $response) use ($services): Response {
                $body = self::body($request);
                $endpoint = (string) ($body['endpoint'] ?? '');
                $keys = is_array($body['keys'] ?? null) ? $body['keys'] : [];
                $encoding = (string) ($body['contentEncoding'] ?? 'aes128gcm');
                if (!preg_match('#^https://[^\s]+$#', $endpoint) || strlen($endpoint) > 1000) {
                    throw AppException::validation(['endpoint' => 'url']);
                }
                if (!is_string($keys['p256dh'] ?? null) || !is_string($keys['auth'] ?? null) || !in_array($encoding, ['aes128gcm', 'aesgcm'], true)) {
                    throw AppException::validation(['keys' => 'required']);
                }
                $services->pushSubscriptions()->save(self::user($request)->id, $endpoint, $keys['p256dh'], $keys['auth'], $encoding);
                return $response->withStatus(204);
            });
            $api->post('/push/unsubscribe', function (Request $request, Response $response) use ($services): Response {
                $services->pushSubscriptions()->remove((string) (self::body($request)['endpoint'] ?? ''), self::user($request)->id);
                return $response->withStatus(204);
            });
            $api->post('/push/test', function (Request $request, Response $response) use ($services): Response {
                $sender = $services->pushSender() ?? throw new AppException('push_off', 409, 'VAPID keys are not set');
                $subscription = $services->pushSubscriptions()->find((string) (self::body($request)['endpoint'] ?? ''));
                if ($subscription === null || $subscription['person'] !== self::user($request)->id) {
                    throw AppException::notFound('Push subscription');
                }
                $outcome = $sender->send($subscription, ['title' => 'Wohnpilot', 'body' => 'So sehen deine Erinnerungen aus. Sie kommen morgens, wenn eine Frist näher rückt.', 'url' => '/mehr', 'tag' => 'wohnpilot-test']);
                if ($outcome === \Wohnpilot\Push\PushSender::GONE) {
                    $services->pushSubscriptions()->remove($subscription['endpoint']);
                }
                return self::json($response, ['result' => $outcome]);
            });

            // Maintenance: list, suggestions, done (and taking it back)
            $api->get('/maintenance', fn (Request $r, Response $response) => self::json($response, ['tasks' => $services->maintenance()->list()]));
            $api->get('/maintenance/suggestions', fn (Request $r, Response $response) => self::json($response, ['suggestions' => $services->maintenance()->suggestions()]));
            $api->post('/maintenance', fn (Request $request, Response $response) => self::json($response, $services->maintenance()->create(self::body($request), self::user($request)), 201));
            $api->get('/maintenance/{id}', fn (Request $r, Response $response, array $args) => self::json($response, $services->maintenance()->detail($args['id'])));
            $api->put('/maintenance/{id}', function (Request $request, Response $response, array $args) use ($services): Response {
                [$changes, $original, $etag] = self::merge($request);
                return self::json($response, $services->maintenance()->update($args['id'], $changes, $original, $etag, self::user($request)));
            });
            // Deleting any entry, only at the version the app has seen
            $api->delete('/entries/{type}/{id}', function (Request $request, Response $response, array $args) use ($services): Response {
                $services->entries()->delete($args['type'], $args['id'], (string) ($request->getQueryParams()['etag'] ?? ''));
                return self::json($response, ['deleted' => true]);
            });
            $api->post('/maintenance/{id}/done', fn (Request $request, Response $response, array $args) => self::json($response, $services->maintenance()->done($args['id'], self::body($request), self::user($request))));
            $api->delete('/maintenance/{id}/done/{date}', fn (Request $request, Response $response, array $args) => self::json($response, $services->maintenance()->undo($args['id'], $args['date'], self::user($request))));

            $api->get('/appointments', fn (Request $r, Response $response) => self::json($response, $services->appointments()->list()));
            $api->post('/appointments', fn (Request $request, Response $response) => self::json($response, $services->appointments()->create(self::body($request), self::user($request)), 201));
            $api->get('/appointments/{id}', fn (Request $r, Response $response, array $args) => self::json($response, $services->appointments()->detail($args['id'])));
            $api->put('/appointments/{id}', function (Request $request, Response $response, array $args) use ($services): Response {
                [$changes, $original, $etag] = self::merge($request);
                return self::json($response, $services->appointments()->update($args['id'], $changes, $original, $etag, self::user($request)));
            });

            $api->get('/defects', fn (Request $r, Response $response) => self::json($response, ['defects' => $services->defects()->list()]));
            $api->get('/defects/minor-repairs', function (Request $request, Response $response) use ($services): Response {
                $year = (int) ($request->getQueryParams()['jahr'] ?? substr($services->clock()->today(), 0, 4));
                return self::json($response, $services->defects()->minorRepairs($year));
            });
            $api->post('/defects', fn (Request $request, Response $response) => self::json($response, $services->defects()->create(self::body($request), self::user($request)), 201));
            $api->get('/defects/{id}', fn (Request $r, Response $response, array $args) => self::json($response, $services->defects()->detail($args['id'])));
            $api->put('/defects/{id}', function (Request $request, Response $response, array $args) use ($services): Response {
                [$changes, $original, $etag] = self::merge($request);
                return self::json($response, $services->defects()->update($args['id'], $changes, $original, $etag, self::user($request)));
            });
            $api->post('/defects/{id}/status', fn (Request $request, Response $response, array $args) => self::json($response, $services->defects()->addStatus($args['id'], self::body($request), self::user($request))));

            $api->get('/devices', fn (Request $r, Response $response) => self::json($response, ['devices' => $services->devices()->list()]));
            $api->post('/devices', fn (Request $request, Response $response) => self::json($response, $services->devices()->create(self::body($request), self::user($request)), 201));
            $api->get('/devices/{id}', fn (Request $r, Response $response, array $args) => self::json($response, $services->devices()->detail($args['id'])));
            $api->put('/devices/{id}', function (Request $request, Response $response, array $args) use ($services): Response {
                [$changes, $original, $etag] = self::merge($request);
                return self::json($response, $services->devices()->update($args['id'], $changes, $original, $etag, self::user($request)));
            });

            // Waste: the calendar address and bins; refresh loads the calendar now
            $api->get('/waste', fn (Request $request, Response $response) => self::json($response, $services->waste()->get(isset($request->getQueryParams()['neu']))));
            $api->put('/waste', fn (Request $request, Response $response) => self::json($response, $services->waste()->save(self::body($request), self::user($request))));

            $api->get('/settings', fn (Request $r, Response $response) => self::json($response, $services->settings()->get()));
            $api->put('/settings', fn (Request $request, Response $response) => self::json($response, $services->settings()->save(self::body($request), self::user($request))));

            // Contacts, with where they are used, and as vCard for the phone
            $api->get('/contacts', fn (Request $r, Response $response) => self::json($response, ['contacts' => $services->contacts()->list()]));
            $api->post('/contacts', fn (Request $request, Response $response) => self::json($response, $services->contacts()->create(self::body($request), self::user($request)), 201));
            $api->get('/contacts/{id}', fn (Request $r, Response $response, array $args) => self::json($response, $services->contacts()->detail($args['id'])));
            $api->put('/contacts/{id}', function (Request $request, Response $response, array $args) use ($services): Response {
                [$changes, $original, $etag] = self::merge($request);
                return self::json($response, $services->contacts()->update($args['id'], $changes, $original, $etag, self::user($request)));
            });
            $api->get('/contacts/{id}/vcard', function (Request $r, Response $response, array $args) use ($services): Response {
                [$card, $name] = $services->contacts()->vcard($args['id']);
                $response->getBody()->write($card);
                return $response->withHeader('Content-Type', 'text/vcard; charset=utf-8')->withHeader('Content-Disposition', 'attachment; filename="' . $name . '"');
            });

            // Knowledge pages and the emergency sheet
            $api->get('/knowledge', fn (Request $r, Response $response) => self::json($response, ['pages' => $services->knowledge()->list()]));
            $api->post('/knowledge', fn (Request $request, Response $response) => self::json($response, $services->knowledge()->create(self::body($request), self::user($request)), 201));
            $api->get('/knowledge/{id}', fn (Request $r, Response $response, array $args) => self::json($response, $services->knowledge()->detail($args['id'])));
            $api->put('/knowledge/{id}', function (Request $request, Response $response, array $args) use ($services): Response {
                [$changes, $original, $etag] = self::merge($request);
                return self::json($response, $services->knowledge()->update($args['id'], $changes, $original, $etag, self::user($request)));
            });
            $api->get('/emergency', fn (Request $r, Response $response) => self::json($response, ['scenarios' => $services->knowledge()->emergency()]));

            $api->get('/search', function (Request $request, Response $response) use ($services): Response {
                $query = $request->getQueryParams();
                $type = isset($query['typ']) && is_string($query['typ']) && $query['typ'] !== '' ? $query['typ'] : null;
                return self::json($response, ['results' => $services->search()->search((string) ($query['q'] ?? ''), $type)]);
            });

            // Documents (PDF), e.g. a letter shared from the phone
            $api->get('/documents/{type}/{id}', fn (Request $r, Response $response, array $args) => self::json(
                $response,
                $services->documents()->files($args['type'], $args['id']),
            ));
            $api->post('/documents/{type}/{id}', function (Request $request, Response $response, array $args) use ($services): Response {
                $file = $request->getUploadedFiles()['dokument'] ?? null;
                if (!$file instanceof UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
                    $tooLarge = $file instanceof UploadedFileInterface && in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);
                    throw AppException::validation(['dokument' => $tooLarge ? 'too_large' : 'required']);
                }
                $title = (string) (((array) $request->getParsedBody())['titel'] ?? pathinfo((string) $file->getClientFilename(), PATHINFO_FILENAME));
                $documents = $services->documents()->add($args['type'], $args['id'], (string) $file->getStream(), $title, self::user($request));
                return self::json($response, ['documents' => $documents], 201);
            });
            $api->delete('/documents/{type}/{id}', function (Request $request, Response $response, array $args) use ($services): Response {
                $path = (string) ($request->getQueryParams()['path'] ?? '');
                return self::json($response, ['documents' => $services->documents()->remove($args['type'], $args['id'], $path, self::user($request))]);
            });

            // Photos of defects, devices and maintenance tasks
            $api->post('/photos/{type}/{id}', function (Request $request, Response $response, array $args) use ($services): Response {
                $file = $request->getUploadedFiles()['foto'] ?? null;
                if (!$file instanceof UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
                    $tooLarge = $file instanceof UploadedFileInterface && in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);
                    throw AppException::validation(['foto' => $tooLarge ? 'too_large' : 'required']);
                }
                $alt = (string) (((array) $request->getParsedBody())['alt'] ?? '');
                $photos = $services->photos()->add($args['type'], $args['id'], (string) $file->getStream(), $alt, self::user($request));
                return self::json($response, ['photos' => $photos], 201);
            });
            $api->delete('/photos/{type}/{id}', function (Request $request, Response $response, array $args) use ($services): Response {
                $path = (string) ($request->getQueryParams()['path'] ?? '');
                return self::json($response, ['photos' => $services->photos()->remove($args['type'], $args['id'], $path, self::user($request))]);
            });
            $api->get('/files/{path:.+}', function (Request $request, Response $response, array $args) use ($services): Response {
                [$bytes, $type] = $services->photos()->file(rawurldecode($args['path']));
                $response->getBody()->write($bytes);
                if ($type === 'application/pdf') {
                    $response = $response->withHeader('Content-Disposition', 'inline; filename="' . basename(rawurldecode($args['path'])) . '"');
                }
                return $response
                    ->withHeader('Content-Type', $type)
                    ->withHeader('Cache-Control', 'private, max-age=86400')
                    ->withHeader('X-Content-Type-Options', 'nosniff');
            });

            $api->post('/sync', function (Request $r, Response $response) use ($services): Response {
                $services->notes()->refresh(true);
                return $response->withStatus(204);
            });
        })->add(function (Request $request, Handler $handler) use ($services): Response {
            // A write sent again with the same Idempotency-Key gets the first answer (offline queue).
            $key = trim($request->getHeaderLine('Idempotency-Key'));
            if ($key === '' || in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS'], true) || strlen($key) > 100) {
                return $handler->handle($request);
            }
            $key = self::user($request)->id . ':' . $request->getMethod() . ':' . $request->getUri()->getPath() . ':' . $key;
            $earlier = $services->idempotency()->find($key);
            if ($earlier !== null) {
                $response = (new \Slim\Psr7\Response($earlier['status']))->withHeader('Content-Type', $earlier['contentType'])->withHeader('Idempotent-Replay', 'true');
                $response->getBody()->write($earlier['body']);
                return $response;
            }
            $response = $handler->handle($request);
            if ($response->getStatusCode() < 300) {
                $services->idempotency()->save($key, $response->getStatusCode(), $response->getHeaderLine('Content-Type'), (string) $response->getBody());
            }
            return $response;
        })->add(function (Request $request, Handler $handler) use ($services): Response {
            $identity = $services->session()->get('identity');
            $identity = is_array($identity) ? Identity::fromArray($identity) : null;
            if ($identity === null && $services->bearId() !== null) {
                // Signed in at BearID already: no click needed.
                try {
                    $identity = $services->bearId()->identity($request->getCookieParams());
                } catch (LoginFailed) {
                    throw new AppException('not_released', 403, 'Wohnpilot is not released for this BearID account');
                }
            }
            if ($identity === null) {
                throw new AppException('unauthorized', 401, 'Not signed in');
            }
            $services->notes()->refresh();
            $user = $services->household()->userFor($identity);
            if ($user !== null && $services->session()->get('identity') === null) {
                self::startSession($services, $identity);
            }
            if ($user === null) {
                throw new AppException('forbidden', 403, 'Not a member of this household');
            }
            return $handler->handle($request->withAttribute('user', $user));
        });

        $app->add(function (Request $request, Handler $handler): Response {
            $writes = !in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS'], true);
            if ($writes && $request->getHeaderLine('X-Requested-With') !== 'wohnpilot') {
                throw new AppException('forbidden', 403, 'Missing X-Requested-With header');
            }
            $response = $handler->handle($request);
            // API answers are never cached, unless a route says otherwise (photos).
            return $response->hasHeader('Cache-Control') ? $response : $response->withHeader('Cache-Control', 'no-store');
        });

        $errors = $app->addErrorMiddleware($services->config->isDev(), true, true);
        $errors->setDefaultErrorHandler(function (Request $request, Throwable $e) use ($app): Response {
            [$status, $body] = match (true) {
                $e instanceof AppException => [$e->status, ['error' => $e->errorCode, 'message' => $e->getMessage()] + $e->details],
                $e instanceof StorageException => [503, ['error' => 'storage_unavailable', 'message' => $e->getMessage()]],
                $e instanceof \Slim\Exception\HttpNotFoundException => [404, ['error' => 'not_found']],
                $e instanceof \Slim\Exception\HttpMethodNotAllowedException => [405, ['error' => 'method_not_allowed']],
                $e instanceof \JsonException, $e instanceof \Slim\Exception\HttpBadRequestException => [400, ['error' => 'bad_request']],
                default => [500, ['error' => 'internal']],
            };
            if ($status >= 500) {
                error_log('Wohnpilot: ' . $e::class . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            }
            return self::json($app->getResponseFactory()->createResponse(), $body, $status);
        });

        return $app;
    }

    private static function startSession(Services $services, Identity $identity): void
    {
        $session = $services->session();
        $session->regenerate();
        $session->set('identity', $identity->toArray());
    }

    private static function user(Request $request): User
    {
        return $request->getAttribute('user');
    }

    /** @return array{array<string, mixed>, array<string, mixed>, string} changes, original, etag of a three-way merge */
    private static function merge(Request $request): array
    {
        $body = self::body($request);
        return [(array) ($body['changes'] ?? []), (array) ($body['original'] ?? []), (string) ($body['etag'] ?? '')];
    }

    private static function body(Request $request): array
    {
        $body = $request->getParsedBody();
        if (!is_array($body)) {
            throw new AppException('bad_request', 400, 'Expected a JSON object');
        }
        return $body;
    }

    private static function json(Response $response, mixed $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
        return $response->withHeader('Content-Type', 'application/json; charset=utf-8')->withStatus($status);
    }
}
