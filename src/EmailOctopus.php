<?php

declare(strict_types=1);

namespace CMS;

/**
 * The slice of EmailOctopus's v2 API this site needs: put a reader on the list,
 * and email an article to the people already on it.
 *
 * The second half is shaped by something the API does not have. There is no
 * call that creates or sends a campaign — campaigns are read-only — so an
 * article is delivered through an automation instead. Its one email is written
 * in EmailOctopus with merge tags ({{ArticleTitle}} and so on), and for each
 * subscriber we write the article into their contact fields and then start
 * the automation for them. See Newsletter for the consequences of that.
 *
 * Every failure is logged and returned as false/null rather than thrown. The
 * signup endpoint has a reader waiting on it and the sender runs from cron;
 * neither has anywhere better to put an exception.
 */
class EmailOctopus
{
    private const BASE_URL = 'https://api.emailoctopus.com';

    /**
     * Custom fields the automation's email reads. They have to exist on the
     * list, with these exact tags, before a send — see the Settings page.
     */
    public const FIELD_TITLE   = 'ArticleTitle';
    public const FIELD_URL     = 'ArticleUrl';
    public const FIELD_EXCERPT = 'ArticleExcerpt';
    public const FIELD_IMAGE   = 'ArticleImage';

    public function __construct(
        private string $apiKey,
        private string $listId,
        private string $automationId = '',
    ) {
    }

    /**
     * Build a client from the settings table, or null when it is not fully set up.
     *
     * The automation id is required even though signing readers up does not
     * use it: a half-configured site should not show a form whose subscribers
     * will never be emailed, so the form and the sender ask for the same set.
     */
    public static function fromSettings(Database $db): ?self
    {
        $key        = trim($db->getSetting('emailoctopus_api_key'));
        $list       = trim($db->getSetting('emailoctopus_list_id'));
        $automation = trim($db->getSetting('emailoctopus_automation_id'));

        return ($key !== '' && $list !== '' && $automation !== '')
            ? new self($key, $list, $automation)
            : null;
    }

    /**
     * Whether the settings name a complete setup — the same test as
     * fromSettings(), for templates that hold the settings array, not a Database.
     *
     * @param array<string,string> $settings
     */
    public static function isConfigured(array $settings): bool
    {
        foreach (['emailoctopus_api_key', 'emailoctopus_list_id', 'emailoctopus_automation_id'] as $key) {
            if (trim((string) ($settings[$key] ?? '')) === '') {
                return false;
            }
        }
        return true;
    }

    /**
     * Add a reader to the list as `pending`, which is what makes EmailOctopus
     * send them the confirmation email (the list must have double opt-in on).
     *
     * An address already on the list is a success, and it is *not* touched:
     * upserting it as pending would demote a confirmed subscriber back to
     * unconfirmed just because they filled the form in twice. The caller shows
     * the same message either way, so the form cannot be used to test whether
     * someone is subscribed.
     */
    public function subscribe(string $email): bool
    {
        $res = $this->request('POST', '/lists/' . rawurlencode($this->listId) . '/contacts', [
            'email_address' => $email,
            'status'        => 'pending',
        ]);

        if ($res === null) {
            return false;
        }
        if ($res['code'] === 201 || $res['code'] === 409) {
            return true;
        }

        self::log("subscribe failed: HTTP {$res['code']} " . self::snippet($res['body']));
        return false;
    }

    /**
     * Every confirmed subscriber's contact id, a page at a time.
     *
     * Returns null when a page fails, not the ids gathered so far: a partial
     * list would let the sender mark an article done with half the list never
     * emailed.
     *
     * @return string[]|null
     */
    public function subscribedContactIds(): ?array
    {
        $ids    = [];
        $cursor = null;

        // A hard ceiling on pages, so a paging bug on their side cannot keep a
        // cron run looping forever. 1,000 pages is 100,000 subscribers.
        for ($page = 0; $page < 1000; $page++) {
            $query = ['status' => 'subscribed', 'limit' => 100];
            if ($cursor !== null) {
                $query['starting_after'] = $cursor;
            }

            $res = $this->request('GET', '/lists/' . rawurlencode($this->listId) . '/contacts?' . http_build_query($query));
            if ($res === null || $res['code'] !== 200) {
                self::log('listing contacts failed' . ($res !== null ? ": HTTP {$res['code']} " . self::snippet($res['body']) : ''));
                return null;
            }

            $json = json_decode($res['body'], true);
            if (!is_array($json) || !is_array($json['data'] ?? null)) {
                self::log('listing contacts failed: unreadable response');
                return null;
            }

            foreach ($json['data'] as $contact) {
                if (is_array($contact) && is_string($contact['id'] ?? null) && $contact['id'] !== '') {
                    $ids[] = $contact['id'];
                }
            }

            $next = $json['paging']['next']['starting_after'] ?? null;
            if (!is_string($next) || $next === '' || $next === $cursor) {
                return $ids;
            }
            $cursor = $next;
        }

        self::log('listing contacts stopped at the page ceiling');
        return null;
    }

    /**
     * Write an article into one subscriber's contact fields.
     *
     * @param array<string,string> $fields keyed by the FIELD_* tags
     */
    public function setFields(string $contactId, array $fields): bool
    {
        $res = $this->request(
            'PUT',
            '/lists/' . rawurlencode($this->listId) . '/contacts/' . rawurlencode($contactId),
            ['fields' => $fields]
        );

        if ($res !== null && $res['code'] === 200) {
            return true;
        }

        self::log("setting fields on {$contactId} failed" . ($res !== null ? ": HTTP {$res['code']} " . self::snippet($res['body']) : ''));
        return false;
    }

    /** Start the article automation for one subscriber. */
    public function queueAutomation(string $contactId): bool
    {
        $res = $this->request(
            'POST',
            '/automations/' . rawurlencode($this->automationId) . '/queue',
            ['contact_id' => $contactId]
        );

        if ($res !== null && $res['code'] === 204) {
            return true;
        }

        self::log("queueing {$contactId} failed" . ($res !== null ? ": HTTP {$res['code']} " . self::snippet($res['body']) : ''));
        return false;
    }

    /**
     * Whether the key and list id are good — for the Settings page, so a typo
     * shows up when it is entered rather than on the first article.
     */
    public function checkList(): bool
    {
        $res = $this->request('GET', '/lists/' . rawurlencode($this->listId));
        return $res !== null && $res['code'] === 200;
    }

    /**
     * One API call, retried once after a 429.
     *
     * EmailOctopus meters requests with a token bucket — 100 deep, refilled at
     * ten a second — so a send paced by Newsletter should never see a 429.
     * The retry is for the case where something else shares the key.
     *
     * Protected so tests can answer in place of the network.
     *
     * @param array<string,mixed>|null $json
     * @return array{code:int, body:string}|null
     */
    protected function request(string $method, string $path, ?array $json = null): ?array
    {
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $opts = [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HTTPHEADER    => [
                    'Authorization: Bearer ' . $this->apiKey,
                    'Accept: application/json',
                    'Content-Type: application/json',
                ],
            ];
            if ($json !== null) {
                $opts[CURLOPT_POSTFIELDS] = json_encode($json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }

            // A host we chose, but SafeHttp all the same: it pins the resolved
            // address and never follows a redirect to somewhere we did not.
            $res = SafeHttp::request(self::BASE_URL . $path, $opts, 1, 10);
            if ($res === null) {
                self::log("{$method} {$path} failed in transport");
                return null;
            }

            if ($res['status'] === 429 && $attempt === 0) {
                sleep(2);
                continue;
            }

            return ['code' => $res['status'], 'body' => $res['body']];
        }

        return null;
    }

    private static function log(string $message): void
    {
        error_log('[emailoctopus] ' . $message);
    }

    /** A response body cut to something a log line can carry. */
    private static function snippet(string $body, int $max = 300): string
    {
        $body = trim(preg_replace('/\s+/', ' ', $body) ?? '');
        return mb_strlen($body) > $max ? mb_substr($body, 0, $max) . '…' : $body;
    }
}
