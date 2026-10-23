<?php

declare(strict_types=1);

namespace Wohnpilot\Tests\Storage;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

/**
 * An in-memory stand-in for the Nextcloud Notes API v1, enough to run the
 * storage contract against the Nextcloud adapter.
 */
final class FakeNotesServer
{
    /** @var array<int, array{id: int, title: string, category: string, content: string, modified: int, etag: string}> */
    public array $notes = [];

    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @var list<int> notes the list endpoint does not return (e.g. another file suffix) */
    public array $hiddenFromList = [];

    private int $nextId = 1;
    private int $clock = 1_760_000_000;

    public function add(string $category, string $title, string $content): int
    {
        $id = $this->nextId++;
        $this->notes[$id] = ['id' => $id, 'title' => $title, 'category' => $category, 'content' => $content, 'modified' => ++$this->clock, 'etag' => md5($id . $content)];
        return $id;
    }

    /** Simulates an edit in the Notes app. */
    public function edit(int $id, string $content): void
    {
        $this->notes[$id]['content'] = $content;
        $this->notes[$id]['modified'] = ++$this->clock;
        $this->notes[$id]['etag'] = md5($id . $content);
    }

    public function client(): Client
    {
        return new Client([
            'base_uri' => 'https://cloud.example/index.php/apps/notes/api/v1/',
            'handler' => HandlerStack::create(fn (RequestInterface $request, array $options): PromiseInterface => Create::promiseFor($this->handle($request))),
        ]);
    }

    private function handle(RequestInterface $request): Response
    {
        $this->requests[] = $request;
        $path = preg_replace('#^.*/api/v1/#', '', $request->getUri()->getPath());
        $method = $request->getMethod();
        parse_str($request->getUri()->getQuery(), $query);

        if ($method === 'GET' && $path === 'settings') {
            return self::json(['notesPath' => 'Notizen', 'fileSuffix' => '.md']);
        }
        if ($method === 'GET' && $path === 'notes') {
            $exclude = explode(',', $query['exclude'] ?? '');
            return self::json(array_values(array_map(
                static fn (array $n): array => array_diff_key($n, array_flip($exclude)),
                array_diff_key($this->notes, array_flip($this->hiddenFromList)),
            )));
        }
        if ($method === 'POST' && $path === 'notes') {
            $body = json_decode((string) $request->getBody(), true);
            $title = $body['title'];
            $taken = array_filter($this->notes, static fn (array $n): bool => $n['category'] === $body['category'] && $n['title'] === $title);
            if ($taken !== []) {
                $title .= ' (2)';
            }
            $id = $this->add($body['category'], $title, $body['content']);
            return self::json($this->notes[$id]);
        }
        if (preg_match('#^notes/(\d+)$#', $path, $m)) {
            $id = (int) $m[1];
            if (!isset($this->notes[$id])) {
                return self::json(['message' => 'not found'], 404);
            }
            if ($method === 'GET') {
                return self::json($this->notes[$id]);
            }
            if ($method === 'DELETE') {
                unset($this->notes[$id]);
                return self::json([]);
            }
            if ($method === 'PUT') {
                $ifMatch = trim($request->getHeaderLine('If-Match'), '"');
                if ($ifMatch !== '' && $ifMatch !== $this->notes[$id]['etag']) {
                    return self::json($this->notes[$id], 412);
                }
                $body = json_decode((string) $request->getBody(), true);
                $this->edit($id, $body['content']);
                return self::json($this->notes[$id]);
            }
        }
        return self::json(['message' => 'unexpected request'], 400);
    }

    private static function json(array $data, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json', 'ETag' => '"list"'], json_encode($data));
    }
}
