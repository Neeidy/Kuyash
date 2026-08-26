<?php

declare(strict_types=1);

namespace Kuyash\Demo;

use Kuyash\Compliance\CompliancePolicy;
use Kuyash\Compliance\SlopScorer;
use Kuyash\Content\ContentRevision;
use Kuyash\Core\Database;
use Kuyash\Media\AssetPoster;
use Kuyash\Media\MediaPaths;
use Kuyash\Publish\OccurrenceMaterializer;
use Kuyash\Publish\OccurrenceRepository;
use Kuyash\Publish\SlotRepository;
use Kuyash\Publish\SlotResolver;
use Kuyash\Workflow\Nodes;
use RuntimeException;

/**
 * The case-study showcase seed (DEV/demo tooling — no route reaches this
 * namespace, nothing in the product reads it, and `bin/demo-seed.php` is its
 * only caller).
 *
 * It fills the screens a case study is captured from so they read as a working
 * product rather than as a wall of empty states. Three rules govern every line
 * below, and each is a hard requirement rather than a preference:
 *
 * 1. REVERSIBLE. Every row it writes and every file it places is recorded in
 *    {@see SeedManifest} as it is created, so `bin/demo-teardown.php` can delete
 *    exactly this set and leave the real data untouched. It follows that the
 *    seed only ever INSERTS: an UPDATE to a pre-existing row could not be undone
 *    from a manifest, so the workspace's own settings — timezone, approval mode,
 *    caps, the kill switch, the real account's figures — are read, never written.
 *
 * 2. INERT. Nothing it writes can make the worker do work. There is no `queued`
 *    or `processing` job anywhere in this file, so the claim loop has nothing to
 *    claim; demo publishes are already-terminal MOCK posts; and every calendar
 *    cell is placed in a state the plan runner will not act on (see seedPlan()).
 *    A demo dataset that could spend money or publish a post is not a demo.
 *
 * 3. HONEST. Every fabricated value is marked. Demo channels are non-connected
 *    mock rows, which is what makes the account card render its "sample" chip on
 *    every figure it stands behind; every seeded title, caption and hashtag
 *    starts with the {@see MARK} prefix, which survives CSS truncation because it
 *    is at the FRONT of the string; the real connected account is never given a
 *    number; and no capability that does not work is depicted as working (no AI
 *    video, no real publish, no metrics on a real channel).
 *
 * WHAT IT DELIBERATELY DOES NOT WRITE: `events`. The event log is append-only by
 * trigger — a row put there could never be taken back, and an audit trail is the
 * last place a demo belongs. /logs stays populated by the workspace's own real
 * history instead.
 */
final class ShowcaseSeed
{
    /**
     * The demo library, and the stock search term behind each item.
     *
     * The term is not decoration: it is what makes the poster a picture of the
     * thing the title names. Titles stay one or two words because a calendar
     * cell at 768px is ~68px wide (see Format::splitTag).
     */
    private const LIBRARY = [
        ['seconds' => 16, 'type' => 'own', 'title' => 'Kitchen', 'tags' => ['kitchen', 'morning'], 'query' => 'kitchen cooking'],
        ['seconds' => 19, 'type' => 'own', 'title' => 'Desk reset', 'tags' => ['desk', 'workspace'], 'query' => 'desk workspace'],
        ['seconds' => 23, 'type' => 'own', 'title' => 'Coffee pour', 'tags' => ['coffee', 'closeup'], 'query' => 'coffee pour'],
        ['seconds' => 27, 'type' => 'face', 'title' => 'Intro take', 'tags' => ['face', 'intro'], 'query' => 'person talking portrait'],
        ['seconds' => 31, 'type' => 'own', 'title' => 'Street walk', 'tags' => ['street', 'golden'], 'query' => 'city street walking'],
        ['seconds' => 35, 'type' => 'own', 'title' => 'Notebook', 'tags' => ['notebook', 'detail'], 'query' => 'notebook writing'],
        ['seconds' => 39, 'type' => 'own', 'title' => 'Window light', 'tags' => ['light', 'slow'], 'query' => 'window light plant'],
        ['seconds' => 43, 'type' => 'own', 'title' => 'Desk lamp', 'tags' => ['evening', 'desk'], 'query' => 'evening lamp room'],
        ['still' => true, 'type' => 'own', 'title' => 'Still — front', 'tags' => ['reference'], 'query' => 'portrait studio'],
        ['still' => true, 'type' => 'face', 'title' => 'Still — profile', 'tags' => ['reference', 'face'], 'query' => 'portrait profile'],
    ];

    /** @return list<string> the search term per library item, in order */
    public static function stockQueries(): array
    {
        return array_map(static fn (array $i): string => (string) $i['query'], self::LIBRARY);
    }

    /**
     * The honesty marker. At the FRONT of every seeded string on purpose: an
     * ellipsis eats the end of a title, so a trailing chip is exactly the thing
     * that disappears at 375px — which is where an unmarked fabricated value
     * would do the most damage.
     */
    public const MARK = '[SAMPLE]';

    /** Mock external post id prefix — the same one MockPublishProvider uses. */
    private const MOCK_POST_PREFIX = 'zp_';

    /**
     * Seconds between a demo run starting and its publish job reporting done.
     * Shared with the calendar so the day a post went out and the time it was
     * planned for are the same instant rather than two numbers that disagree.
     */
    private const PUBLISHED_OFFSET = 480;

    /**
     * Who demo approvals are attributed to. `.invalid` is reserved by RFC 2606
     * and can never be a real mailbox — the address itself says what it is.
     */
    private const DEMO_EMAIL = 'sample.operator@kuyash.invalid';

    private SeedManifest $manifest;
    private ?int $demoUserId = null;

    public function __construct(
        private readonly Database $db,
        private readonly ?MediaPaths $paths = null,
        private readonly ?MediaFactory $media = null,
        private readonly ?AssetPoster $posters = null,
    ) {
        $this->manifest = new SeedManifest($this->db);
    }

    public function manifest(): SeedManifest
    {
        return $this->manifest;
    }

    /**
     * Seed the showcase into $workspaceId.
     *
     * @return array{workspace: int, notes: list<string>, counts: array<string, int>}
     */
    public function run(int $workspaceId, string $nowIso): array
    {
        $ws = $this->db->one('SELECT id FROM workspaces WHERE id = ?', [$workspaceId]);
        if ($ws === null) {
            throw new RuntimeException("demo-seed: no such workspace (#{$workspaceId}).");
        }
        $owner = $this->db->one(
            "SELECT user_id FROM workspace_users WHERE workspace_id = ?
             ORDER BY (role = 'owner') DESC, id ASC LIMIT 1",
            [$workspaceId],
        );
        if ($owner === null) {
            throw new RuntimeException("demo-seed: workspace #{$workspaceId} has no member to attribute work to.");
        }
        $ownerId = (int) $owner['user_id'];

        $notes = [];

        // ── media first, OUTSIDE the transaction ────────────────────────────
        // ffmpeg must never run with a write lock held (the SQLite rule), and
        // bytes must exist before the row that claims them does — the other
        // order leaves a library tile pointing at nothing.
        [$library, $renderFiles, $mediaNotes] = $this->buildMedia($workspaceId);
        $notes = array_merge($notes, $mediaNotes);

        try {
            $this->db->transaction(function (Database $db) use (
                $workspaceId,
                $ownerId,
                $nowIso,
                $library,
                $renderFiles,
                &$notes
            ): void {
                foreach (array_merge(
                    array_column($library, 'path'),
                    array_column($renderFiles, 'video'),
                    array_column($renderFiles, 'poster'),
                ) as $file) {
                    if (is_string($file) && $file !== '') {
                        $this->manifest->trackFile($file, $nowIso);
                    }
                }

                $accounts = $this->seedAccounts($workspaceId, $nowIso);
                $assets = $this->seedLibrary($workspaceId, $library, $nowIso);
                $target = $this->postTarget($workspaceId, $accounts);
                if ($target === null) {
                    $notes[] = 'no mock channel to attribute demo posts to — history left unseeded';
                }
                $runs = $this->seedRuns($workspaceId, $ownerId, $assets, $target, $renderFiles, $nowIso);
                $notes = array_merge($notes, $this->seedPlan($workspaceId, $assets, $runs, $nowIso));
                $this->seedSpendHistory($workspaceId, $nowIso);
            });
        } catch (\Throwable $e) {
            // The rows are gone (rolled back) but the bytes are not: drop the
            // files this call created so a failed seed leaves nothing behind.
            foreach (array_merge(
                array_column($library, 'path'),
                array_column($renderFiles, 'video'),
                array_column($renderFiles, 'poster'),
            ) as $file) {
                if (is_string($file) && $file !== '') {
                    @unlink($file);
                }
            }
            throw $e;
        }

        $counts = $this->manifest->counts();
        // Said out loud, because everything downstream of the library is
        // downstream of it: no clips means no runs, which means an empty queue,
        // an empty calendar and an empty digest — while the summary below still
        // lists rows and reads like a success.
        if (($counts['assets'] ?? 0) === 0) {
            $notes[] = 'NO LIBRARY CLIPS WERE SEEDED — the queue, the calendar and the digest stay empty';
        }

        return [
            'workspace' => $workspaceId,
            'notes' => $notes,
            'counts' => $counts,
        ];
    }

    // ── media ───────────────────────────────────────────────────────────────

    /**
     * Build every file this seed needs, measuring each one.
     *
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, string>>, 2: list<string>}
     */
    private function buildMedia(int $workspaceId): array
    {
        if ($this->paths === null || $this->media === null) {
            return [[], [], ['media factory unavailable — library and renders left unseeded']];
        }

        $notes = [];
        $library = [];

        // Ten library items: eight clips whose lengths span the 15-45s format
        // band, and two stills (the library grid renders a REAL thumbnail for a
        // photo and an icon tile for a video, so a mix is what fills it).
        // TITLES ARE SHORT ON PURPOSE. The marker sits at the FRONT so an
        // ellipsis cannot eat it — but that trade has a cost the visual gate
        // showed plainly: in a calendar cell at 768px, "[SAMPLE] Morning kitchen
        // b-roll" truncated to "[SAMPLE]…" and every occupied day looked
        // identical. One or two words after the marker is what fits in the
        // narrowest cell the product has, so the marker AND the title survive.
        $plan = self::LIBRARY;

        foreach ($plan as $i => $item) {
            $isStill = ($item['still'] ?? false) === true;
            $name = self::storedName($workspaceId, 'lib', $i, $isStill ? 'jpg' : 'mp4');
            $dest = $this->paths->pathFor('asset', $workspaceId, $name);
            @mkdir(dirname($dest), 0775, true);
            // The path is a hash of (workspace, role, index), so a file already
            // sitting there is a leftover from an earlier demo run — UNLESS an
            // asset row claims it, in which case it belongs to somebody and is
            // left alone. Skipping unconditionally is what this replaces, and it
            // failed quietly in the worst way: a database wiped out of band left
            // the files behind, every item was "already there", and the seed
            // produced a library of nothing and a queue of nothing while
            // reporting success.
            if (!$this->reclaim($workspaceId, $name, $dest, $notes)) {
                continue;
            }
            // $i keeps the two stills apart: without it both were the same first
            // frame of the same fixture under two different titles — identical
            // sha256, which is a collision waiting for anything that dedups.
            $made = $isStill
                ? $this->media->still($dest, $i)
                : $this->media->clip($dest, (int) $item['seconds'], $i);
            if ($made === null) {
                @unlink($dest);
                // Named per item. A library that silently comes up short is what
                // let the screens look finished while every preview was a wash.
                $notes[] = sprintf(
                    'NO MEDIA for "%s" (%s) — that item is missing from the library',
                    (string) $item['title'],
                    (string) ($item['query'] ?? '?'),
                );
                continue;
            }
            $library[] = $made + [
                'stored_name' => $name,
                'kind' => $isStill ? 'photo' : 'video',
                'type' => (string) $item['type'],
                'title' => self::MARK . ' ' . $item['title'],
                'tags' => $item['tags'],
            ];
        }

        // One finished render per completed demo run, so the history cards and
        // the digest strip point at a file that actually plays instead of a 404.
        $renderFiles = [];
        foreach ([0, 1, 2] as $i) {
            $source = $library[$i]['path'] ?? null;
            if (!is_string($source) || ($library[$i]['kind'] ?? '') !== 'video') {
                continue;
            }
            $videoName = self::storedName($workspaceId, 'ren', $i, 'mp4');
            $posterName = self::storedName($workspaceId, 'pos', $i, 'jpg');
            $videoPath = $this->paths->pathFor('render', $workspaceId, $videoName);
            $posterPath = $this->paths->pathFor('render', $workspaceId, $posterName);
            @mkdir(dirname($videoPath), 0775, true);
            if (!$this->reclaim($workspaceId, $videoName, $videoPath, $notes, 'renders')
                || !$this->reclaim($workspaceId, $posterName, $posterPath, $notes, 'renders')
                || !@copy($source, $videoPath)
            ) {
                continue;
            }
            $poster = $this->media->still($posterPath, $i + 1);
            $renderFiles[] = [
                'video' => $videoPath,
                'poster' => $poster === null ? '' : $posterPath,
                'stored_name' => $videoName,
                'poster_name' => $poster === null ? null : $posterName,
                'duration_s' => $library[$i]['duration_s'],
                'width' => $library[$i]['width'],
                'height' => $library[$i]['height'],
                'size_bytes' => (int) filesize($videoPath),
            ];
        }

        return [$library, $renderFiles, $notes];
    }

    /**
     * May the seed write to $path? Yes when nothing is there, and yes when a
     * leftover file is there that no row in $table claims. No — loudly — when a
     * row does claim it, because that file is somebody's content.
     *
     * @param list<string> $notes
     */
    private function reclaim(int $workspaceId, string $name, string $path, array &$notes, string $table = 'assets'): bool
    {
        if (!file_exists($path)) {
            return true;
        }
        $owner = $this->db->one(
            "SELECT id FROM {$table} WHERE workspace_id = ? AND stored_name = ?",
            [$workspaceId, $name],
        );
        if ($owner !== null) {
            $notes[] = "{$name} belongs to {$table} #{$owner['id']} — left alone, that item is not seeded";

            return false;
        }
        if (!@unlink($path)) {
            $notes[] = "could not replace the leftover file {$name} — that item is not seeded";

            return false;
        }

        return true;
    }

    /**
     * Deterministic 32-hex stored name, namespaced by workspace + role + index —
     * so re-running after a teardown reuses the same paths instead of littering
     * media storage with a new set every time.
     */
    private static function storedName(int $workspaceId, string $role, int $index, string $ext): string
    {
        return substr(hash('sha256', "kuyash-demo|{$workspaceId}|{$role}|{$index}"), 0, 32) . '.' . $ext;
    }

    // ── accounts ────────────────────────────────────────────────────────────

    /**
     * Two demo channels, NEITHER of them `connected`.
     *
     * That is a deliberate safety property, not an aesthetic one: publishing
     * fans out to every account AccountRepository::connectedFor() returns, so a
     * connected mock row would attach itself to the operator's next REAL publish
     * and fail it. `reauth_needed` and `disconnected` are states the product
     * produces, they render the health variety the accounts screen is meant to
     * show, and they keep this seed out of the publish path entirely.
     *
     * The handles say what they are, and because no provider ever reported a
     * follower count for them the account card marks every figure it derives
     * with its "sample" chip — the existing product rule, unchanged.
     *
     * @return list<int>
     */
    private function seedAccounts(int $workspaceId, string $now): array
    {
        $ids = [];
        $demo = [
            ['youtube', '@sample.reels', 'reauth_needed', 'degraded'],
            ['instagram', '@sample.kitchen', 'disconnected', 'unknown'],
        ];
        foreach ($demo as [$platform, $handle, $status, $health]) {
            $existing = $this->db->one(
                'SELECT id FROM accounts WHERE workspace_id = ? AND platform = ? AND handle = ? COLLATE NOCASE',
                [$workspaceId, $platform, $handle],
            );
            if ($existing !== null) {
                continue;
            }
            $ids[] = $this->insert('accounts', [
                'workspace_id' => $workspaceId,
                'platform' => $platform,
                'handle' => $handle,
                'external_ref' => null,
                'status' => $status,
                'health' => $health,
                'connected_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ], $now);
        }

        return $ids;
    }

    /**
     * Which channel demo posts are attributed to.
     *
     * NEVER a provider-backed one. A fabricated "published" record on a real
     * channel is a claim about that channel — the single thing this seed may not
     * make. A mock row (no provider reference, or a mock one) carries no such
     * claim, so an existing mock channel is preferred and a demo row is the
     * fallback.
     *
     * @param list<int> $seeded
     */
    private function postTarget(int $workspaceId, array $seeded): ?int
    {
        // The seed's OWN channels first. They are the only ones guaranteed to
        // satisfy both halves of the rule below, and preferring an existing row
        // is what got this wrong the first time.
        if (($seeded[0] ?? null) !== null) {
            return $seeded[0];
        }

        // Fallback: an existing channel that is BOTH not provider-backed AND not
        // connected.
        //
        // `status <> 'connected'` is the half this query used to be missing, and
        // it is the half that matters most. Provenance decides whether a
        // fabricated post makes a claim about a real channel; CONNECTEDNESS
        // decides whether it touches live machinery — AccountRepository::
        // connectedFor() is what PublishGateExecutor and PlanRunner walk to count
        // the per-account daily cap, and what ZernioPublishExecutor fans a real
        // publish out to. A mock-but-connected row (there is one on the dev
        // database) passed the old predicate, so a demo post dated today spent
        // real cap headroom and could defer a real publish to the operator's
        // actual channel.
        $mock = $this->db->one(
            "SELECT id FROM accounts
             WHERE workspace_id = ? AND status <> 'connected' AND followers_count IS NULL
               AND (external_ref IS NULL OR external_ref LIKE 'zacct\\_%' ESCAPE '\\')
             ORDER BY id ASC LIMIT 1",
            [$workspaceId],
        );

        return $mock === null ? null : (int) $mock['id'];
    }

    // ── library ─────────────────────────────────────────────────────────────

    /**
     * @param list<array<string, mixed>> $library
     *
     * @return list<int> asset ids, in the order they were built
     */
    private function seedLibrary(int $workspaceId, array $library, string $now): array
    {
        $ids = [];
        foreach ($library as $i => $item) {
            $id = $this->insert('assets', [
                'workspace_id' => $workspaceId,
                'kind' => $item['kind'],
                'type' => $item['type'],
                'title' => $item['title'],
                'original_filename' => 'demo-' . ($i + 1) . ($item['kind'] === 'photo' ? '.jpg' : '.mp4'),
                'stored_name' => $item['stored_name'],
                'mime' => $item['mime'],
                'size_bytes' => $item['size_bytes'],
                'sha256' => $item['sha256'],
                // measured by the factory, never asserted here
                'duration_s' => $item['duration_s'],
                'width' => $item['width'],
                'height' => $item['height'],
                'aspect' => $item['aspect'],
                'tags' => json_encode($item['tags'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'status' => 'ready',
                'created_at' => self::shift($now, -86400 * (12 - $i)),
                'updated_at' => self::shift($now, -86400 * (12 - $i)),
                'storage_disk' => 'local',
            ], $now);
            $ids[] = $id;

            // A still frame, through the SAME service the product uses at ingest —
            // so a demo clip previews exactly the way an uploaded one does, and
            // the poster is tracked so teardown takes it too.
            $poster = $this->posters?->ensure([
                'id' => $id,
                'workspace_id' => $workspaceId,
                'kind' => $item['kind'],
                'stored_name' => $item['stored_name'],
                'sha256' => $item['sha256'],
                'storage_disk' => 'local',
            ]);
            if ($poster !== null) {
                $this->manifest->trackFile($poster, $now);
            }
        }

        return $ids;
    }

    // ── runs, jobs, approvals, posts ────────────────────────────────────────

    /**
     * Eight demo runs: three finished-and-published, five parked at the publish
     * gate in the states the approval screen is built to show (plain, edited,
     * pinned to a planned day, produced by automatic mode).
     *
     * NOT ONE of them carries a `queued` or `processing` job. A run parked at
     * `awaiting_approval` is invisible to the claim loop — the worker claims
     * `queued` and nothing else — so these are as inert as the finished ones.
     *
     * @param list<int>                  $assets
     * @param list<array<string, mixed>> $renderFiles
     *
     * @return array{done: list<int>, awaiting: list<int>, first_published_at: string|null}
     */
    private function seedRuns(
        int $workspaceId,
        int $ownerId,
        array $assets,
        ?int $accountId,
        array $renderFiles,
        string $now
    ): array {
        $workflowId = $this->distributionWorkflow($workspaceId, $now);
        $videos = array_slice($assets, 0, 8);
        if ($videos === []) {
            return ['done' => [], 'awaiting' => [], 'first_published_at' => null];
        }

        $packs = self::packs();
        $done = [];
        $awaiting = [];

        // Three finished runs: 3 hours, 2 days and 5 days ago. The first is
        // TODAY on purpose — the daily digest reports one UTC date, and a digest
        // with nothing in it teaches a reader nothing.
        $firstPublishedAt = null;
        //
        // ALL THREE ARE `manual`, AND ONLY THE FIRST IS DATED TODAY.
        //
        // No `auto` approval is written at all, for two reasons that both proved
        // out on the live database:
        //   • AutoApprovalGate::autoApprovalsToday() counts auto approvals for the
        //     workspace across the UTC day and compares that count to the real
        //     daily_post_cap. One seeded row took a live auto-mode workspace from
        //     2 of 2 to 3 of 2 — and when that counter trips, the gate writes
        //     `guardrail.daily_cap_reached` with the inflated count into `events`,
        //     which is APPEND-ONLY. A seed that refuses to write the audit log
        //     itself must not induce the product to write a false line into it.
        //   • The daily digest renders auto-approvals from ids and timestamps
        //     only — no title, no caption, no handle — so the [SAMPLE] marker
        //     cannot reach that screen. Filling a compliance audit surface with
        //     fabricated records that carry no marker is the exact thing the
        //     honesty rule exists to stop. The digest therefore stays honestly
        //     empty, and the truthful auto-approval record is left to be
        //     photographed where it can be explained: a run's own page.
        //
        // The two older runs are dated into PREVIOUS months so their charges can
        // be dated with them (see seedSpendHistory) without landing inside the
        // window the budget cap is enforced against.
        // OLDEST FIRST, and that ordering is load-bearing. Both the charge feed
        // and the credit ledger read `ORDER BY id DESC` — insertion order, not
        // timestamp — so seeding the newer month first made /usage list its
        // "recent" charges oldest-first. The run seeded earlier gets the earlier
        // date, and id order then agrees with the clock.
        foreach ([[0, 180], [1, 57 * 1440 + 45], [2, 41 * 1440 + 90]] as $i => [$p, $minsAgo]) {
            if ($i === 0) {
                // when the publish job on this run reports having finished
                $firstPublishedAt = self::shift($now, -$minsAgo * 60 + self::PUBLISHED_OFFSET);
            }
            $done[] = $this->seedRun(
                $workspaceId,
                $ownerId,
                $workflowId,
                $videos[$p % count($videos)],
                $packs[$p],
                $now,
                $minsAgo,
                published: true,
                accountId: $accountId,
                render: $renderFiles[$i] ?? null,
            );
        }

        // Five runs waiting for a person. #4 carries an edit (the state the
        // "put back what Kuyash wrote" control exists for).
        foreach ([[3, 240], [4, 300], [5, 420], [6, 610], [7, 900]] as $k => [$p, $minsAgo]) {
            $awaiting[] = $this->seedRun(
                $workspaceId,
                $ownerId,
                $workflowId,
                $videos[$p % count($videos)],
                $packs[$p],
                $now,
                $minsAgo,
                published: false,
                accountId: $accountId,
                render: null,
                // the DEMO account, never the operator. `edit.by` is not rendered
                // today (the text editor asks for `by_email`, which nothing
                // populates), so this was invisible — and would have started
                // showing the real operator as the editor of demo text the day
                // that got wired up. Same class as the fabricated approval.
                edited: $k === 1 ? $this->demoUserId($now) : null,
            );
        }

        return ['done' => $done, 'awaiting' => $awaiting, 'first_published_at' => $firstPublishedAt];
    }

    /**
     * One demo run and the whole distribution job chain behind it.
     *
     * @param array<string, mixed>      $pack
     * @param array<string, mixed>|null $render
     */
    private function seedRun(
        int $workspaceId,
        int $ownerId,
        int $workflowId,
        int $assetId,
        array $pack,
        string $now,
        int $minsAgo,
        bool $published,
        ?int $accountId,
        ?array $render,
        ?int $edited = null
    ): int {
        $started = self::shift($now, -$minsAgo * 60);
        $asset = $this->db->one('SELECT stored_name, duration_s, width, height FROM assets WHERE id = ?', [$assetId]);
        $duration = $asset === null ? null : ($asset['duration_s'] === null ? null : (float) $asset['duration_s']);
        $width = $asset === null || $asset['width'] === null ? null : (int) $asset['width'];
        $height = $asset === null || $asset['height'] === null ? null : (int) $asset['height'];

        // Straight from the canonical registry: a run snapshots the node graph,
        // and a hand-rolled shape here would depict a graph the engine cannot run.
        $nodes = json_encode(Nodes::defaultNodes(Nodes::TEMPLATE_DISTRIBUTION), JSON_THROW_ON_ERROR);

        $runId = $this->insert('runs', [
            'workspace_id' => $workspaceId,
            'workflow_id' => $workflowId,
            'entity_type' => 'library',
            'entity_id' => $assetId,
            'nodes_json' => $nodes,
            'status' => $published ? 'completed' : 'awaiting_approval',
            'current_node' => 'PUBLISH',
            'created_by' => $ownerId,
            'created_at' => $started,
            'updated_at' => self::shift($started, 600),
        ], $now);

        $step = 0;
        $at = static fn (int $offset): string => self::shift($started, $offset);

        $this->job($workspaceId, $runId, 'LIBRARY', ++$step, 'asset_fetch', 'ready', [
            'source' => 'library',
            'visual_kind' => 'video',
            'visual_ref' => 'asset:' . $workspaceId . ':' . (string) ($asset['stored_name'] ?? ''),
            'asset_id' => $assetId,
            'title' => (string) $pack['title'],
            'ai_label_required' => false,
            'duration_s' => $duration,
        ], $at(60), $now);

        $captionPayload = ['captions' => $pack['captions'], 'prompt_version' => 'caption.v1'];
        $tagPayload = ['hashtags' => $pack['hashtags'], 'prompt_version' => 'hashtag.v1'];
        if ($edited !== null) {
            // The shape ContentExecutor writes after a person edits: what Kuyash
            // originally produced is kept alongside, which is the only reason the
            // "put it back" control can exist at all.
            $captionPayload['captions_ai'] = $pack['captions_ai'];
            $tagPayload['hashtags_ai'] = $pack['hashtags_ai'];
            $editBlock = [
                'by' => $edited,
                'at' => $at(900),
                'hash' => ContentRevision::hash($pack['captions'], $pack['hashtags']),
                'verdict' => [
                    'status' => 'pass',
                    'policy' => 'kuyash-v1',
                    'reasons' => [],
                    'warnings' => [],
                    // measured, like the compliance card's own score below
                    'slop' => (new SlopScorer($this->db))->score(
                        $workspaceId,
                        $runId,
                        SlopScorer::candidateText(['caption_generation' => ['captions' => $pack['captions']]]),
                    ),
                ],
            ];
            $captionPayload['edit'] = $editBlock;
            $tagPayload['edit'] = $editBlock;
        }
        $this->job($workspaceId, $runId, 'CAPTION', ++$step, 'caption_generation', 'ready', $captionPayload, $at(150), $now);
        $this->job($workspaceId, $runId, 'HASHTAGS', ++$step, 'hashtag_generation', 'ready', $tagPayload, $at(200), $now);
        $this->job($workspaceId, $runId, 'MUSIC NOTE / STYLE', ++$step, 'music_note', 'ready', [
            'mood' => (string) $pack['mood'],
            'note' => 'suggestion only — platform-native sounds cannot be published via API',
        ], $at(240), $now);
        $this->job($workspaceId, $runId, 'PREVIEW', ++$step, 'preview', 'ready', [
            'note' => 'preview is the in-pipeline checkpoint; the reviewable render is the draft',
        ], $at(280), $now);
        $slop = (new SlopScorer($this->db))->score(
            $workspaceId,
            $runId,
            SlopScorer::candidateText(['caption_generation' => ['captions' => $pack['captions']]]),
        );
        $this->job($workspaceId, $runId, 'COMPLIANCE', ++$step, 'compliance_check', 'ready', [
            'status' => 'pass',
            'policy' => 'kuyash-v1',
            'checks' => [
                'ai_label' => ['required' => false, 'reasons' => []],
                // The exact shape ComplianceCheckExecutor::formatCheck() returns —
                // {status, duration_s, width, height, source, reasons} — not a
                // near-miss of it. `source` is 'asset' because a DISTRIBUTION run
                // has no ASSEMBLE node and therefore no draft render at the time
                // the check runs; the executor falls back to the asset in exactly
                // that case. And the band is checked against the asset's MEASURED
                // duration, so this row repeats a measurement, never a target.
                'format' => [
                    'status' => 'pass',
                    'duration_s' => $duration,
                    'width' => $width,
                    'height' => $height,
                    'source' => 'asset',
                    'reasons' => [],
                ],
                // MEASURED by the product's own scorer against the runs already
                // seeded, not asserted from a literal. Same rule the durations
                // follow, and for the same reason: a number on a compliance card
                // cannot carry the [SAMPLE] marker, so it has to be true. The
                // caption job is written at step 2 above, so the candidate text
                // this scores is the one the card actually shows.
                'slop' => [
                    'status' => 'pass',
                    'score' => $slop['score'],
                    'warn_at' => CompliancePolicy::SLOP_WARN,
                    'block_at' => CompliancePolicy::SLOP_BLOCK,
                    'history_runs' => $slop['history_runs'],
                ],
            ],
            'reasons' => [],
            'ai_label_required' => false,
        ], $at(320), $now);

        // The exact shape MockExecutor emits for this gate. `library_asset_id` is
        // what lets the approval card play a real preview: a DISTRIBUTION run has
        // no ASSEMBLE node and therefore no draft render, so the reviewable video
        // IS the library clip — which is also exactly what will be published.
        // Without it every showcase approval card was a grey "preview pending"
        // box, on the most visual screen in the product.
        $reviewResult = [
            'summary' => 'Render review: compliance pass (policy kuyash-v1)',
            'draft_render_id' => null,
            'poster_ref' => null,
            'library_asset_id' => $assetId,
            'duration_s' => $duration,
            'compliance' => [
                'status' => 'pass',
                'policy' => 'kuyash-v1',
                'slop_score' => $slop['score'],
            ],
            'ai_label_required' => false,
        ];
        $renderId = null;
        if ($render !== null) {
            $renderId = $this->insert('renders', [
                'workspace_id' => $workspaceId,
                'run_id' => $runId,
                'job_id' => null,
                'kind' => 'final',
                'stored_name' => (string) $render['stored_name'],
                'poster_name' => $render['poster_name'],
                'mime' => 'video/mp4',
                'width' => $render['width'],
                'height' => $render['height'],
                'duration_s' => $render['duration_s'],
                'size_bytes' => $render['size_bytes'],
                'created_at' => $at(400),
                'storage_disk' => 'local',
            ], $now);
        }

        $reviewJobId = $this->job(
            $workspaceId,
            $runId,
            'PUBLISH',
            ++$step,
            'render_review',
            $published ? 'ready' : 'awaiting_approval',
            $reviewResult,
            $at(360),
            $now,
        );

        if (!$published) {
            return $runId;
        }

        // A REAL approval record, attributed to a DEMO ACCOUNT.
        //
        // The distinction is the whole point. Writing `decided_by` = the
        // operator's own user id makes the run page render "Approved by you ·
        // <their real email>" for a decision they never made — that is
        // fabrication, and `.claude/rules/compliance.md` forbids it by name. A
        // record naming a demo account fabricates nothing: that account really
        // did approve this run, in the sense that the demo dataset is what it is,
        // and the email says so on its face (a reserved .invalid domain that can
        // never belong to a person).
        //
        // It also restores a state the engine can actually reach. `render_review`
        // = ready is only produced by Engine::approve()/finalizeAutoApproved(),
        // and both write an approval row — so a published run with NO record was
        // a state the product cannot produce, and the run page hides the whole
        // card when the list is empty, which reads as "this publish had no
        // approval gate" on a compliance-first product.
        //
        // `manual` + a real user + no policy stamp is exactly what the 0007 CHECK
        // demands of a human record, and this one is human: a demo human.
        $this->insert('approvals', [
            'workspace_id' => $workspaceId,
            'run_id' => $runId,
            'job_id' => $reviewJobId,
            'node' => 'PUBLISH',
            'decision' => 'approved',
            'mode' => 'manual',
            'decided_by' => $this->demoUserId($now),
            'decided_at' => $at(380),
            'policy_version' => null,
            'score_json' => null,
        ], $now);

        $this->job($workspaceId, $runId, 'PUBLISH', ++$step, 'final_render', 'ready', [
            'render_id' => $renderId,
            'final' => true,
            'ai_label_required' => false,
        ], $at(420), $now);
        // The exact shape ZernioPublishExecutor returns. A near-miss here is not
        // cosmetic: the daily digest reads these keys, and a result carrying a
        // boolean where a count belongs rendered as "published to 1 of 0
        // account(s)" — a sentence the product cannot produce.
        $publishJobId = $this->job($workspaceId, $runId, 'PUBLISH', ++$step, 'publish', 'published', [
            'posts' => $accountId === null ? 0 : 1,
            'published' => $accountId === null ? 0 : 1,
            'accepted' => 0,
            'failed' => 0,
            'ai_label_applied' => 0,
        ], $at(self::PUBLISHED_OFFSET), $now, finishedAt: $at(self::PUBLISHED_OFFSET));

        if ($accountId !== null) {
            $account = $this->db->one('SELECT platform FROM accounts WHERE id = ? AND workspace_id = ?', [$accountId, $workspaceId]);
            $this->insert('posts', [
                'workspace_id' => $workspaceId,
                'run_id' => $runId,
                'job_id' => $publishJobId,
                'account_id' => $accountId,
                'platform' => (string) ($account['platform'] ?? 'tiktok'),
                'status' => 'published',
                // the mock provider's own id shape: nothing here claims a post
                // exists on a real platform
                'external_post_id' => self::MOCK_POST_PREFIX . substr(hash('sha256', 'demo|' . $runId), 0, 16),
                'external_url' => null,
                'ai_label_applied' => 0,
                'scheduled_for' => null,
                'idempotency_key' => 'demo:run:' . $runId . ':acct:' . $accountId . ':publish',
                'error_message' => null,
                'created_at' => $at(440),
                'posted_at' => $at(self::PUBLISHED_OFFSET),
                'updated_at' => $at(self::PUBLISHED_OFFSET),
            ], $now);
        }

        return $runId;
    }

    /**
     * @param array<string, mixed> $result
     */
    private function job(
        int $workspaceId,
        int $runId,
        string $node,
        int $step,
        string $type,
        string $status,
        array $result,
        string $createdAt,
        string $now,
        ?string $finishedAt = null
    ): int {
        return $this->insert('jobs', [
            'workspace_id' => $workspaceId,
            'run_id' => $runId,
            'node' => $node,
            'step' => $step,
            'type' => $type,
            'status' => $status,
            'payload_json' => '{}',
            'result_json' => json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'retry_count' => 0,
            'max_retries' => 3,
            // A demo job must never be claimable. `run_after` is irrelevant for
            // these statuses, but it is set in the past for readability only —
            // the claim loop filters on status first, and no status here is
            // 'queued'.
            'run_after' => $createdAt,
            'priority' => 100,
            'created_at' => $createdAt,
            'started_at' => $createdAt,
            'finished_at' => $finishedAt ?? ($status === 'awaiting_approval' ? null : $createdAt),
        ], $now);
    }

    /**
     * The account demo approvals are attributed to — created once per seed run
     * and recorded in the manifest like everything else.
     *
     * NOT LOGINABLE. The password hash is taken over 32 random bytes that are
     * discarded immediately, so no password matches it; the address sits on the
     * reserved `.invalid` TLD, which by RFC 2606 can never resolve to a real
     * mailbox. The name carries the marker, because the run page prints the
     * address and the digest prints nothing at all.
     */
    private function demoUserId(string $now): int
    {
        if ($this->demoUserId !== null) {
            return $this->demoUserId;
        }
        $existing = $this->db->one('SELECT id FROM users WHERE email = ?', [self::DEMO_EMAIL]);
        if ($existing !== null) {
            // an earlier seed's account that teardown could not remove (a pinned
            // run still points at it) — reuse rather than collide on the UNIQUE
            return $this->demoUserId = (int) $existing['id'];
        }

        return $this->demoUserId = $this->insert('users', [
            'email' => self::DEMO_EMAIL,
            'password_hash' => password_hash(bin2hex(random_bytes(32)), PASSWORD_ARGON2ID),
            'name' => self::MARK . ' Demo operator',
            'created_at' => $now,
            'updated_at' => $now,
        ], $now);
    }

    private function distributionWorkflow(int $workspaceId, string $now): int
    {
        $existing = $this->db->one(
            "SELECT id FROM workflows WHERE workspace_id = ? AND template = 'distribution' ORDER BY id ASC LIMIT 1",
            [$workspaceId],
        );
        if ($existing !== null) {
            return (int) $existing['id'];
        }

        return $this->insert('workflows', [
            'workspace_id' => $workspaceId,
            'name' => self::MARK . ' Distribution',
            'template' => 'distribution',
            'nodes_json' => json_encode(Nodes::defaultNodes(Nodes::TEMPLATE_DISTRIBUTION), JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ], $now);
    }

    // ── weekly plan ─────────────────────────────────────────────────────────

    /**
     * A used-looking calendar that the plan runner will not act on.
     *
     * The calendar window starts at TODAY 00:00 local, so a filled past is
     * invisible there — every cell below therefore lands inside the next two
     * weeks, and each one is placed in a state the runner provably skips:
     *
     *   • `dueAuto()` selects mode='auto' AND status='open' AND run_id IS NULL.
     *     Every automatic cell here is 'assigned' with a run attached, so
     *     automatic PRODUCTION — the only path in the product that can spend
     *     money on its own — has nothing to pick up. The automatic time is also
     *     left disabled, so the materializer creates no further cells for it.
     *   • `overdue()` selects cells at or past the grace cutoff. Every cell here
     *     is in the future, so the sweep — which would cancel runs and append
     *     guardrail events that no teardown could remove — has nothing to close.
     *
     * @param list<int>                        $assets
     * @param array{done: list<int>, awaiting: list<int>} $runs
     *
     * @return list<string> notes
     */
    private function seedPlan(int $workspaceId, array $assets, array $runs, string $now): array
    {
        $zone = (string) ($this->db->one('SELECT timezone FROM workspaces WHERE id = ?', [$workspaceId])['timezone'] ?? 'UTC');
        $occurrences = new OccurrenceRepository($this->db);
        $materializer = new OccurrenceMaterializer($occurrences, new SlotResolver());
        $notes = [];

        // Publishing times on weekdays the workspace is not already using, so a
        // demo time can never collide with a real one (the UNIQUE index would
        // reject it, and a silently skipped slot is worse than a loud one).
        $taken = [];
        foreach ($this->db->all('SELECT weekday, time_hhmm FROM publish_slots WHERE workspace_id = ?', [$workspaceId]) as $row) {
            $taken[(int) $row['weekday'] . '|' . (string) $row['time_hhmm']] = true;
        }
        // The FIRST time is placed on today's weekday, at the local wall-clock
        // minute the first finished run actually published — which is what makes
        // a "Published" day appear on a calendar whose window starts this
        // morning. The rest are spread across the week from there, so a demo
        // time can never be a duplicate of another demo time either.
        $today = self::localParts($now, $zone);
        $published = $runs['first_published_at'];
        $todayTime = $published === null || $today === null
            ? null
            : self::localHhmm(self::floorLocalHour($published, $zone), $zone);
        $anchor = $today === null ? 3 : $today['weekday'];
        $wanted = [
            ['weekday' => $anchor, 'time' => $todayTime ?? '07:00', 'mode' => 'manual'],
            ['weekday' => self::wrapWeekday($anchor + 2), 'time' => '12:30', 'mode' => 'manual'],
            ['weekday' => self::wrapWeekday($anchor + 4), 'time' => '19:00', 'mode' => 'manual'],
            ['weekday' => self::wrapWeekday($anchor + 5), 'time' => '10:00', 'mode' => 'auto'],
        ];
        $slots = [];
        foreach ($wanted as $slot) {
            if (isset($taken[$slot['weekday'] . '|' . $slot['time']])) {
                $notes[] = "publishing time {$slot['weekday']} {$slot['time']} already exists — skipped";
                continue;
            }
            $slots[$slot['mode'] === 'auto' ? 'auto' : 'manual'][] = [
                'id' => $this->insert('publish_slots', [
                    'workspace_id' => $workspaceId,
                    'account_id' => null,
                    'weekday' => $slot['weekday'],
                    'time_hhmm' => $slot['time'],
                    'enabled' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                    'mode' => $slot['mode'],
                ], $now),
                'weekday' => $slot['weekday'],
                'time_hhmm' => $slot['time'],
                'mode' => $slot['mode'],
                'enabled' => 1,
            ];
        }

        // Cells come from the REAL materializer, so the calendar shows what the
        // product produces rather than dates this file invented. The automatic
        // time gets a ONE-week horizon (a single cell) and is then switched off:
        // a paused time creates no further days, which is what pause means.
        $before = $this->existingOccurrenceIds($workspaceId);
        if (($slots['manual'] ?? []) !== []) {
            $materializer->materialize($workspaceId, $zone, $slots['manual'], $now);
        }
        if (($slots['auto'] ?? []) !== []) {
            $materializer->materialize($workspaceId, $zone, $slots['auto'], $now, 7);
            $this->db->run(
                'UPDATE publish_slots SET enabled = 0, updated_at = ? WHERE id = ? AND workspace_id = ?',
                [$now, $slots['auto'][0]['id'], $workspaceId],
            );
        }
        $created = array_values(array_diff($this->existingOccurrenceIds($workspaceId), $before));
        foreach ($created as $id) {
            $this->manifest->track('slot_occurrences', $id, $now);
        }

        // One extra cell for EARLIER TODAY on the Wednesday time, carrying the
        // finished run — without it nothing on the calendar is ever shown as
        // published, because the materializer refuses to create a cell in the
        // past and the board window begins this morning.
        $todayCell = null;
        if (($slots['manual'] ?? []) !== [] && ($runs['done'][0] ?? null) !== null && $published !== null) {
            $todayCell = $this->seedTodayCell(
                $workspaceId,
                $slots['manual'][0],
                (int) $runs['done'][0],
                $published,
                $zone,
                $now,
                $occurrences,
            );
        }

        // Decorate. Automatic cells are handled FIRST and unconditionally: an
        // automatic cell left 'open' is the one row in this whole file that
        // could make the product spend money on its own.
        $awaiting = $runs['awaiting'];
        $autoSlotId = $slots['auto'][0]['id'] ?? null;
        $ordered = $created === [] ? [] : $this->db->all(
            'SELECT id, slot_id FROM slot_occurrences WHERE workspace_id = ? AND id IN (' . self::placeholders($created) . ')
             ORDER BY publish_at ASC',
            array_merge([$workspaceId], $created),
        );

        $auto = [];
        $manual = [];
        foreach ($ordered as $cell) {
            if ($autoSlotId !== null && (int) $cell['slot_id'] === $autoSlotId) {
                $auto[] = (int) $cell['id'];
            } else {
                $manual[] = (int) $cell['id'];
            }
        }

        // Manual days: the first three hold content, the rest stay empty so the
        // "put a video on this day" affordance is on screen too.
        $fill = array_merge($auto, array_slice($manual, 0, 3));
        if (count($auto) > count($awaiting)) {
            // Never reachable with the current run count, but if it ever were,
            // an unfilled automatic cell must be REMOVED rather than left open.
            $notes[] = 'not enough demo runs for every automatic day';
        }

        foreach ($fill as $k => $cellId) {
            $runId = array_shift($awaiting);
            if ($runId === null) {
                // No run to hold this cell: delete it rather than leave an open
                // automatic day behind (see the note above).
                $this->db->run('DELETE FROM slot_occurrences WHERE id = ? AND workspace_id = ?', [$cellId, $workspaceId]);
                $this->manifest->forget('slot_occurrences', $cellId);
                continue;
            }
            $isAuto = in_array($cellId, $auto, true);
            // An automatic day carries no chosen video — Kuyash produced it —
            // while a manual day is the operator's own clip on that date.
            $assetId = $isAuto ? null : ($assets[$k % max(1, count($assets))] ?? null);
            $occurrences->reserve($workspaceId, $cellId, $assetId, $now);
            $occurrences->attachRun($workspaceId, $cellId, $runId, $now);
            // The planned instant is written on the RUN as well, exactly as the
            // plan runner writes it at birth — otherwise approving the demo card
            // would read as "publish now" instead of "publish on that day".
            $this->db->run(
                'UPDATE runs SET publish_after = (SELECT publish_at FROM slot_occurrences WHERE id = ?)
                 WHERE id = ? AND workspace_id = ?',
                [$cellId, $runId, $workspaceId],
            );
        }

        if ($todayCell === null) {
            $notes[] = 'no cell for earlier today — the calendar shows no published day';
        }

        return $notes;
    }

    /**
     * The one cell the materializer cannot produce: a day EARLIER TODAY, holding
     * the run that already went out. The materializer refuses to create a cell
     * in the past (correctly — a passed time is not something you can still
     * fill), and the board window begins this morning, so without this the
     * calendar could never show a published day at all.
     *
     * Its instant is the moment the demo post reports having gone out, so the
     * planned time and the actual publish are the same instant rather than two
     * numbers that quietly disagree on screen.
     *
     * @param array<string, mixed> $slot
     */
    private function seedTodayCell(
        int $workspaceId,
        array $slot,
        int $runId,
        string $publishedAt,
        string $zone,
        string $now,
        OccurrenceRepository $occurrences
    ): ?int {
        // floored to the hour, for the same reason the slot's own time is (see
        // seedPlan): a publishing time reads as a round time, and a job fires at
        // or AFTER its instant — so "planned 13:00, went out 13:08" is what the
        // product actually does, while "planned 13:08" reads as a typo.
        $publishedAt = self::floorLocalHour($publishedAt, $zone);
        $local = self::localParts($publishedAt, $zone);
        $today = self::localParts($now, $zone);
        if ($local === null || $today === null
            || $local['date'] !== $today['date']
            || $local['weekday'] !== (int) $slot['weekday']
            || strtotime($publishedAt) >= strtotime($now)
        ) {
            return null;
        }

        if (!$occurrences->materialize($workspaceId, (int) $slot['id'], $local['date'], $publishedAt, 'manual', $now)) {
            return null;
        }
        $row = $this->db->one(
            'SELECT id FROM slot_occurrences WHERE workspace_id = ? AND slot_id = ? AND local_date = ?',
            [$workspaceId, (int) $slot['id'], $local['date']],
        );
        if ($row === null) {
            return null;
        }
        $id = (int) $row['id'];
        $this->manifest->track('slot_occurrences', $id, $now);

        // Holding the finished run is also what keeps this cell out of the
        // sweep: overdue() skips a cell whose run has a published post, so the
        // plan runner will not close it as missed and will not append a
        // guardrail event that no teardown could remove.
        // the clip that day actually went out with — read from the run, so the
        // calendar names the same video the run does
        $entity = $this->db->one(
            "SELECT entity_id FROM runs WHERE id = ? AND workspace_id = ? AND entity_type = 'library'",
            [$runId, $workspaceId],
        );
        $occurrences->reserve($workspaceId, $id, $entity === null ? null : (int) $entity['entity_id'], $now);
        $occurrences->attachRun($workspaceId, $id, $runId, $now);
        $this->db->run(
            'UPDATE runs SET publish_after = ? WHERE id = ? AND workspace_id = ?',
            [$publishedAt, $runId, $workspaceId],
        );

        return $id;
    }

    /**
     * Local weekday/date for a UTC instant, or null when the zone is unusable.
     *
     * @return array{weekday: int, date: string, hhmm: string}|null
     */
    private static function localParts(string $iso, string $zone): ?array
    {
        try {
            $local = (new \DateTimeImmutable($iso, new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone($zone));
        } catch (\Throwable) {
            return null;
        }

        return [
            'weekday' => (int) $local->format('N'),
            'date' => $local->format('Y-m-d'),
            'hhmm' => $local->format('H:i'),
        ];
    }

    private static function localHhmm(string $iso, string $zone): ?string
    {
        return self::localParts($iso, $zone)['hhmm'] ?? null;
    }

    /**
     * The instant at the top of the LOCAL hour containing $iso. Floored in the
     * workspace's own zone, not in UTC: a zone at a half-hour offset would
     * otherwise produce a "round" time of 13:30, which is neither round nor what
     * the operator typed.
     */
    private static function floorLocalHour(string $iso, string $zone): string
    {
        try {
            $local = (new \DateTimeImmutable($iso, new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone($zone));
        } catch (\Throwable) {
            return gmdate('Y-m-d\TH:00:00\Z', (int) strtotime($iso));
        }

        return $local->setTime((int) $local->format('G'), 0)
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s\Z');
    }

    /** 1..7, wrapping — ISO weekdays, the way publish_slots stores them. */
    private static function wrapWeekday(int $n): int
    {
        return (($n - 1) % 7) + 1;
    }

    /** @return list<int> */
    private function existingOccurrenceIds(int $workspaceId): array
    {
        return array_map(
            static fn (array $r): int => (int) $r['id'],
            $this->db->all('SELECT id FROM slot_occurrences WHERE workspace_id = ?', [$workspaceId]),
        );
    }

    // ── spend history ───────────────────────────────────────────────────────

    /**
     * A charge history for the usage screen.
     *
     * TWO RULES, and the second one is why this used to be incoherent:
     *
     *  1. A charge is dated WITH THE JOB THAT INCURRED IT. The first version
     *     scattered rows across the two previous months and attached them to
     *     whichever demo jobs it found — including jobs on runs still parked at
     *     an approval gate today. The screen then showed run #12 paying for AI
     *     text three months before run #12 existed, which is not a state the
     *     product can reach.
     *  2. Nothing lands inside the CURRENT month. Month-to-date spend is the
     *     control the budget cap actually enforces (PreflightGate refuses to
     *     start work once it is reached), so a demo charge there would spend a
     *     real budget on nothing.
     *
     * The two together mean only the finished runs DATED INTO PREVIOUS MONTHS
     * carry charges. The runs from today carry none — which is not a fudge: the
     * usage screen's own footer says charges are recorded only for real spend and
     * that free steps and reuses report no cost.
     *
     * Credits are a display layer over cents (there is no prepaid economy in V1
     * and nothing is enforced against the balance), so the mirrored ledger rows
     * move a number on screen and nothing else. Each carries the marker in the
     * reason it is listed under.
     */
    private function seedSpendHistory(int $workspaceId, string $now): void
    {
        // The current month is off limits — see rule 2 above.
        $monthStart = gmdate('Y-m-01\T00:00:00\Z', (int) strtotime($now));

        $jobs = $this->db->all(
            "SELECT j.id, j.run_id, j.type, j.created_at FROM jobs j
             JOIN demo_seed_manifest m ON m.table_name = 'jobs' AND m.row_id = j.id
             WHERE j.workspace_id = ? AND j.type IN ('caption_generation', 'hashtag_generation', 'asset_fetch')
               AND j.created_at < ?
             ORDER BY j.id ASC",
            [$workspaceId, $monthStart],
        );
        if ($jobs === []) {
            return;
        }

        // The provider column is what the charge row PRINTS, and it is the only
        // free-text field on that row — so the marker goes there, in front, where
        // truncation cannot eat it. It still names the provider the charge stands
        // in for, because "[SAMPLE] openai" says both true things at once: no call
        // was made, and this is what such a call would look like.
        $mark = self::MARK . ' ';
        $categories = [
            'caption_generation' => ['ai_text', $mark . 'openai', 4],
            'hashtag_generation' => ['ai_text', $mark . 'openai', 2],
            'asset_fetch' => ['stock', $mark . 'pexels', 3],
        ];
        // NO 'publish' row. Demo publishes are mock, and mock work is never
        // recorded as spend (the usage_events rule) — a charge for a post that
        // never left the machine would be the one fabricated number here that
        // the ledger presents as money.

        foreach ($jobs as $job) {
            [$category, $provider, $cents] = $categories[(string) $job['type']];
            // the job's own clock: a charge cannot predate the work it paid for
            $at = (string) $job['created_at'];

            $this->insert('usage_events', [
                'workspace_id' => $workspaceId,
                'run_id' => (int) $job['run_id'],
                'job_id' => (int) $job['id'],
                'provider' => $provider,
                'category' => $category,
                'model' => null,
                'units' => null,
                'unit_type' => null,
                'cost_cents' => $cents,
                'created_at' => $at,
            ], $now);
            // NO MIRRORED LEDGER ROW.
            //
            // The marker can live in credit_transactions.reason, so the ledger
            // LIST stays honest — but the numbers that card leads with cannot
            // carry it: balanceCents() and totals() are lifetime SUMs with no
            // date filter, so seeded spends silently become part of a headline
            // balance. On the dev workspace six demo rows were 72% of the
            // displayed lifetime spend. Same failure as the digest and the
            // approval chip: an aggregate has nowhere to put a marker.
            //
            // Nothing is lost by leaving them out. usage_events alone drives the
            // month-to-date panel, the breakdown and the charge feed, which is
            // the whole cost story the usage screen tells.
        }
    }

    // ── plumbing ────────────────────────────────────────────────────────────

    /**
     * INSERT one row and record it in the manifest in the SAME transaction, so
     * a row can never exist without its undo entry.
     *
     * @param array<string, mixed> $columns
     */
    private function insert(string $table, array $columns, string $now): int
    {
        $names = array_keys($columns);
        $sql = 'INSERT INTO ' . $table . ' (' . implode(', ', $names) . ') VALUES ('
            . implode(', ', array_fill(0, count($names), '?')) . ')';
        $this->db->run($sql, array_values($columns));
        $id = $this->db->lastInsertId();
        $this->manifest->track($table, $id, $now);

        return $id;
    }

    /** @param list<int> $ids */
    private static function placeholders(array $ids): string
    {
        return $ids === [] ? 'NULL' : implode(', ', array_fill(0, count($ids), '?'));
    }

    private static function shift(string $iso, int $seconds): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', (int) strtotime($iso) + $seconds);
    }

    /**
     * The eight content packs. Every operator-visible string starts with the
     * marker — including the captions, which are the text that would actually go
     * out if somebody approved a demo card by mistake.
     *
     * @return list<array<string, mixed>>
     */
    private static function packs(): array
    {
        $m = self::MARK . ' ';

        return [
            [
                'title' => $m . 'Kitchen reset',
                'mood' => 'calm',
                'captions' => [
                    'instagram' => $m . 'Ten minutes, one counter, and the kitchen stops arguing with you.',
                    'tiktok' => $m . 'The ten-minute kitchen reset I do before anything else.',
                    'youtube' => $m . 'A ten-minute morning kitchen reset — one counter at a time.',
                ],
                'captions_ai' => [
                    'instagram' => $m . 'Ten minutes and the kitchen is calm again.',
                    'tiktok' => $m . 'My ten-minute kitchen reset.',
                    'youtube' => $m . 'A ten-minute morning kitchen reset.',
                ],
                'hashtags' => ['#sample', '#kitchenreset', '#morningroutine', '#tidyup', '#tenminutes'],
                'hashtags_ai' => ['#sample', '#kitchenreset', '#morningroutine'],
            ],
            [
                'title' => $m . 'Desk setup',
                'mood' => 'upbeat',
                'captions' => [
                    'instagram' => $m . 'The second pass is where a desk setup actually becomes usable.',
                    'tiktok' => $m . 'Everyone shows the first setup. Nobody shows the fix.',
                    'youtube' => $m . 'Desk setup, second pass: what I changed after a month of using it.',
                ],
                'captions_ai' => [
                    'instagram' => $m . 'The second pass is where a desk setup gets usable.',
                    'tiktok' => $m . 'Everyone shows the first setup.',
                    'youtube' => $m . 'Desk setup, second pass.',
                ],
                'hashtags' => ['#sample', '#desksetup', '#workspace', '#secondpass'],
                'hashtags_ai' => ['#sample', '#desksetup', '#workspace'],
            ],
            [
                'title' => $m . 'Coffee, no gear',
                'mood' => 'warm',
                'captions' => [
                    'instagram' => $m . 'Good coffee at home does not need four gadgets. It needs one habit.',
                    'tiktok' => $m . 'Skip the gadgets. This is the only step that changed my coffee.',
                    'youtube' => $m . 'Better home coffee without the ceremony — one habit, no new gear.',
                ],
                'captions_ai' => [
                    'instagram' => $m . 'Good coffee at home needs one habit, not four gadgets.',
                    'tiktok' => $m . 'The only coffee step that mattered.',
                    'youtube' => $m . 'Better home coffee without the ceremony.',
                ],
                'hashtags' => ['#sample', '#homecoffee', '#nogear', '#morninghabit'],
                'hashtags_ai' => ['#sample', '#homecoffee', '#morninghabit'],
            ],
            [
                'title' => $m . 'The out-loud test',
                'mood' => 'direct',
                'captions' => [
                    'instagram' => $m . 'If the first line does not survive being said out loud, it is not a hook.',
                    'tiktok' => $m . 'Read your first line out loud. If you cringe, rewrite it.',
                    'youtube' => $m . 'The out-loud test: how I check a hook before I film it.',
                ],
                'captions_ai' => [
                    'instagram' => $m . 'If a first line does not survive being said out loud, rewrite it.',
                    'tiktok' => $m . 'Read your first line out loud.',
                    'youtube' => $m . 'The out-loud test for hooks.',
                ],
                'hashtags' => ['#sample', '#hooks', '#scriptwriting', '#outloudtest'],
                'hashtags_ai' => ['#sample', '#hooks', '#scriptwriting'],
            ],
            [
                'title' => $m . 'Golden hour',
                'mood' => 'cinematic',
                'captions' => [
                    'instagram' => $m . 'You do not need a location. You need twenty minutes and the right twenty.',
                    'tiktok' => $m . 'Same street, same phone, twenty minutes later.',
                    'youtube' => $m . 'Golden hour on an ordinary street — timing beats location.',
                ],
                'captions_ai' => [
                    'instagram' => $m . 'You do not need a location, you need the right twenty minutes.',
                    'tiktok' => $m . 'Same street, twenty minutes later.',
                    'youtube' => $m . 'Golden hour on an ordinary street.',
                ],
                'hashtags' => ['#sample', '#goldenhour', '#phonevideo', '#timing'],
                'hashtags_ai' => ['#sample', '#goldenhour', '#timing'],
            ],
            [
                'title' => $m . 'One page a day',
                'mood' => 'quiet',
                'captions' => [
                    'instagram' => $m . 'One page a day beats a perfect system you abandon in March.',
                    'tiktok' => $m . 'The notebook system that survived a whole year: one page.',
                    'youtube' => $m . 'One page a day — the smallest notebook habit that actually stuck.',
                ],
                'captions_ai' => [
                    'instagram' => $m . 'One page a day beats a perfect system.',
                    'tiktok' => $m . 'The notebook habit that stuck: one page.',
                    'youtube' => $m . 'One page a day.',
                ],
                'hashtags' => ['#sample', '#notebook', '#dailyhabit', '#onepage'],
                'hashtags_ai' => ['#sample', '#notebook', '#dailyhabit'],
            ],
            [
                'title' => $m . 'Window light',
                'mood' => 'soft',
                'captions' => [
                    'instagram' => $m . 'A window and a white wall out-light most kits under three hundred.',
                    'tiktok' => $m . 'Put the window in front of you. That is the whole tip.',
                    'youtube' => $m . 'Window light, no softbox — the cheapest lighting fix there is.',
                ],
                'captions_ai' => [
                    'instagram' => $m . 'A window and a white wall beat most cheap kits.',
                    'tiktok' => $m . 'Put the window in front of you.',
                    'youtube' => $m . 'Window light, no softbox.',
                ],
                'hashtags' => ['#sample', '#lighting', '#windowlight', '#nokit'],
                'hashtags_ai' => ['#sample', '#lighting', '#windowlight'],
            ],
            [
                'title' => $m . 'Evening edit',
                'mood' => 'low-key',
                'captions' => [
                    'instagram' => $m . 'The evening edit is slower and it is better. Nobody is waiting.',
                    'tiktok' => $m . 'Edit at night. The footage does not get worse, you just get calmer.',
                    'youtube' => $m . 'Why the evening edit is the one I keep — slower, calmer, better.',
                ],
                'captions_ai' => [
                    'instagram' => $m . 'The evening edit is slower and better.',
                    'tiktok' => $m . 'Edit at night, you get calmer.',
                    'youtube' => $m . 'Why the evening edit is the one I keep.',
                ],
                'hashtags' => ['#sample', '#editing', '#eveningedit', '#slowwork'],
                'hashtags_ai' => ['#sample', '#editing', '#eveningedit'],
            ],
        ];
    }
}
