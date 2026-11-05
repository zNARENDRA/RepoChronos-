<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\ComplexityAnalyzer;
use App\Services\RepositoryImporter;
use App\Services\RepositoryOverview;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RepositoryController extends Controller
{
    private function access(int $id, bool $write = false, bool $checkpoint = false): object
    {
        abort_unless($id > 0 && $id <= 2147483647, 404);
        $query = DB::table('repositories as r')
            ->leftJoin('repository_members as m', function ($join) {
                $join->on('m.repo_id', '=', 'r.id')->where('m.user_id', auth()->id());
            })->where('r.id', $id)->select('r.*', 'm.role');
        if ($checkpoint) {
            $query->selectSub(DB::table('import_pages as p')
                ->selectRaw("jsonb_build_object('head_sha',p.head_sha,'more',p.more)")
                ->whereColumn('p.repo_id', 'r.id')
                ->whereRaw("r.source_key <> ? AND m.role IN ('owner','editor')", ['sample'])
                ->orderByDesc('p.imported_at')->limit(1), 'checkpoint');
        }
        $repo = $query->first();
        abort_unless($repo, 404);
        abort_if(! config('repochronos.allow_sample_data') && ! str_starts_with($repo->source_key, 'github:'), 404);
        abort_unless($repo->role && (! $write || in_array($repo->role, ['owner', 'editor'])), 403);

        return $repo;
    }

    public function index()
    {
        $repos = DB::table('repositories as r')->join('repository_members as m', 'm.repo_id', '=', 'r.id')->where('m.user_id', auth()->id())
            ->when(! config('repochronos.allow_sample_data'), fn ($query) => $query->where('r.source_key', 'like', 'github:%'))
            ->select('r.*', 'm.role')->orderByDesc('r.id')->get();

        return view('repositories', compact('repos'));
    }

    public function connect(Request $r, RepositoryImporter $importer)
    {
        $data = $r->validate(['repository' => 'required|string|max:200']);
        $repo = $importer->connect($data['repository'], auth()->id());

        return redirect('/r/'.$repo->id)->with('notice', 'Repository connected. Import its commits.');
    }

    public function import(Request $r, int $id, RepositoryImporter $importer)
    {
        $repo = $this->access($id, true);
        set_time_limit(180);
        abort_if($repo->source_key === 'sample', 422);
        $data = $r->validate(['head' => 'nullable|regex:/^[a-f0-9]{40}$/']);

        return back()->with('notice', $importer->import($repo, $data['head'] ?? null));
    }

    public function member(Request $r, int $id)
    {
        $repo = $this->access($id, true);
        abort_unless($repo->role === 'owner', 403);
        $data = $r->validate(['email' => 'required|email', 'role' => 'required|in:viewer,editor']);
        $user = User::where('email', $data['email'])->first();
        if (! $user) {
            throw ValidationException::withMessages(['email' => 'That user must register first.']);
        }
        $existing = DB::table('repository_members')->where('repo_id', $id)->where('user_id', $user->id);
        abort_if((clone $existing)->value('role') === 'owner', 422, 'Owner membership cannot be changed.');
        DB::table('repository_members')->upsert([['repo_id' => $id, 'user_id' => $user->id, 'role' => $data['role']]], ['repo_id', 'user_id'], ['role']);

        return back()->with('notice', 'Repository membership saved.');
    }

    public function show(Request $r, int $id, string $tab = 'overview')
    {
        $repo = $this->access($id, checkpoint: true);
        abort_unless(in_array($tab, ['overview', 'history', 'analysis', 'compare', 'bugs', 'sql']), 404);
        $engine = in_array($tab, ['overview', 'analysis', 'compare']) ? app(ComplexityAnalyzer::class)->engine() : null;
        $data = ['repo' => $repo, 'tab' => $tab, 'engine' => $engine];
        $data['checkpoint'] = $repo->checkpoint === null ? null : json_decode($repo->checkpoint, false, 512, JSON_THROW_ON_ERROR);
        if ($tab === 'history') {
            $data['people'] = DB::table('v_contributors')->where('repo_id', $id)->orderByDesc('commits')->get();
        }
        if ($tab === 'compare') {
            $data['commits'] = DB::table('commits')->where('repo_id', $id)->orderByDesc('authored_at')->get();
        }
        if ($tab === 'overview') {
            $data += app(RepositoryOverview::class)->load($id, $engine);
        }
        if ($tab === 'history') {
            $r->validate(['path' => 'nullable|string|max:300', 'author' => 'nullable|integer|min:1|max:2147483647', 'page' => 'nullable|integer|min:1|max:1000000']);
            $data['history'] = DB::table('v_file_history')->where('repo_id', $id)
                ->when($r->filled('path'), fn ($q) => $q->where('path', 'ilike', '%'.$r->string('path').'%'))
                ->when($r->filled('author'), fn ($q) => $q->where('developer_id', $r->integer('author')))
                ->orderByDesc('authored_at')->simplePaginate(30)->withQueryString();
        }
        if ($tab === 'analysis') {
            $r->validate(['page' => 'nullable|integer|min:1|max:1000000']);
            $data['runs'] = DB::table('v_file_history as h')
                ->leftJoin('analysis_runs as a', function ($join) use ($engine) {
                    $join->on('a.change_id', '=', 'h.change_id')->where('a.engine', $engine);
                })->where('h.repo_id', $id)
                ->select('h.path', 'h.sha', 'a.engine', 'a.language', 'a.reason', 'a.threshold', 'a.analyzed_at')
                ->selectRaw("COALESCE(a.status, 'not analyzed') AS status")
                ->orderByDesc('h.authored_at')->get();
            $data['measurements'] = DB::table('v_measurement_history')->where('engine', $engine)->where('repo_id', $id)->whereNotNull('measurement_id')->orderByDesc('authored_at')->simplePaginate(40);
            $data['pending'] = $data['runs']->where('status', 'not analyzed')->count();
        }
        if ($tab === 'compare') {
            $data['files'] = DB::table('files')->where('repo_id', $id)->orderBy('path')->get();
            $data['comparisons'] = [];
            $data['fileTrend'] = collect();
            if ($r->filled('file')) {
                $r->validate(['file' => 'integer', 'before' => 'required_with:file|integer', 'after' => 'required_with:file|integer']);
                $file = $data['files']->firstWhere('id', $r->integer('file'));
                abort_unless($file, 404);
                foreach (['before', 'after'] as $side) {
                    abort_unless($data['commits']->contains('id', $r->integer($side)), 422);
                }
                $commitIds = [$r->integer('before'), $r->integer('after')];
                $changes = DB::table('changes as ch')->leftJoin('analysis_runs as a', function ($join) use ($engine) {
                    $join->on('a.change_id', '=', 'ch.id')->where('a.engine', $engine);
                })->where('ch.file_id', $file->id)->whereIn('ch.commit_id', $commitIds)
                    ->select('ch.commit_id', 'a.status')->get()->keyBy('commit_id');
                foreach (['before', 'after'] as $side) {
                    $change = $changes->get($r->integer($side));
                    $data['comparisonStatus'][$side] = $change
                        ? ($change->status ?? 'not analyzed')
                        : 'not changed in this commit';
                }
                $data['fileTrend'] = DB::select('SELECT sha,authored_at,round(avg(score),2) AS mean_complexity,max(score) AS max_complexity FROM v_measurement_history WHERE repo_id=? AND path=? AND engine=? AND measurement_id IS NOT NULL GROUP BY sha,authored_at ORDER BY authored_at', [$id, $file->path, $engine]);
                $measurements = DB::table('v_measurement_history')->where('engine', $engine)->where('repo_id', $id)
                    ->where('path', $file->path)->whereIn('commit_id', $commitIds)->get();
                foreach (['before', 'after'] as $side) {
                    $data[$side] = $measurements->where('commit_id', $r->integer($side))->values();
                }
            }
        }
        if ($tab === 'compare' && isset($data['before'])) {
            $old = $data['before']->whereNotNull('measurement_id')->keyBy('signature');
            $new = $data['after']->whereNotNull('measurement_id')->keyBy('signature');
            $data['deltas'] = $old->keys()->merge($new->keys())->unique()->map(function ($signature) use ($old, $new) {
                $before = $old->get($signature);
                $after = $new->get($signature);

                return (object) ['function' => $signature, 'before' => $before?->score,
                    'after' => $after?->score, 'delta' => $before && $after ? $after->score - $before->score : null];
            });
        }
        if ($tab === 'bugs') {
            $data['bugs'] = DB::table('v_bug_current')->where('repo_id', $id)->orderByDesc('id')->get();
            if ($repo->role !== 'viewer' && $data['bugs']->contains(fn ($bug) => ! $bug->fixed_at)) {
                $data['people'] = DB::table('v_contributors')->where('repo_id', $id)->orderByDesc('commits')->get();
                $data['commits'] = DB::table('commits')->where('repo_id', $id)->orderByDesc('authored_at')->get();
            }
            $data['events'] = DB::table('bug_events as e')->join('bugs as b', 'b.id', '=', 'e.bug_id')->leftJoin('users as u', 'u.id', '=', 'e.actor_user_id')->leftJoin('developers as d', 'd.id', '=', 'e.developer_id')->leftJoin('commits as c', 'c.id', '=', 'e.commit_id')->where('b.repo_id', $id)->select('e.*', 'u.name as actor', 'd.display_name', 'c.sha')->orderBy('e.at')->get();
        }
        if ($tab === 'sql') {
            $data['sql'] = 'SELECT path, count(DISTINCT developer_id) AS authors FROM v_file_history WHERE repo_id = ? GROUP BY path HAVING count(DISTINCT developer_id) > 1';
            $data['result'] = DB::select($data['sql'], [$id]);
        }

        return view('repository', $data);
    }

    public function analyze(int $id, ComplexityAnalyzer $analyzer)
    {
        $repo = $this->access($id, true);
        set_time_limit(180);
        $count = $analyzer->run($repo);

        return back()->with('notice', "$count revisions processed. Previously recorded measurements are preserved.");
    }

    public function report(Request $r, int $id)
    {
        $this->access($id, true);
        $data = $r->validate(['title' => 'required|string|max:160', 'details' => 'nullable|string|max:4000']);
        DB::table('bugs')->insert(['repo_id' => $id, 'title' => $data['title'], 'details' => $data['details'] ?? '', 'reporter' => auth()->user()->name, 'reporter_user_id' => auth()->id()]);

        return back()->with('notice', 'Bug reported.');
    }

    public function event(Request $r, int $id, int $bug)
    {
        $this->access($id, true);
        abort_unless($bug > 0 && $bug <= 2147483647, 404);
        abort_unless(DB::table('bugs')->where('repo_id', $id)->where('id', $bug)->exists(), 404);
        $data = $r->validate(['kind' => 'required|in:assigned,progress,fixed', 'target' => 'nullable|integer']);
        if ($data['kind'] !== 'progress' && empty($data['target'])) {
            throw ValidationException::withMessages(['target' => 'Choose a developer or fixing commit.']);
        }
        DB::transaction(function () use ($bug, $data) {
            DB::table('bugs')->where('id', $bug)->lockForUpdate()->first();
            DB::table('bug_events')->insert(['bug_id' => $bug, 'kind' => $data['kind'], 'actor_user_id' => auth()->id(),
                'developer_id' => $data['kind'] === 'assigned' ? $data['target'] : null, 'commit_id' => $data['kind'] === 'fixed' ? $data['target'] : null]);
        });

        return back()->with('notice', 'Bug event recorded.');
    }
}
