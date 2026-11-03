<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\ComplexityAnalyzer;
use App\Services\RepositoryImporter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RepositoryController extends Controller
{
    private function access(int $id, bool $write = false): object
    {
        $repo = DB::table('repositories')->find($id);
        abort_unless($repo, 404);
        $role = DB::table('repository_members')->where('repo_id', $id)->where('user_id', auth()->id())->value('role');
        abort_unless($role && (! $write || in_array($role, ['owner', 'editor'])), 403);
        $repo->role = $role;

        return $repo;
    }

    public function index()
    {
        $repos = DB::table('repositories as r')->join('repository_members as m', 'm.repo_id', '=', 'r.id')->where('m.user_id', auth()->id())->select('r.*', 'm.role')->get();

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
        $repo = $this->access($id);
        abort_unless(in_array($tab, ['overview', 'history', 'analysis', 'compare', 'bugs', 'sql']), 404);
        $data = ['repo' => $repo, 'tab' => $tab];
        $data['checkpoint'] = DB::table('import_pages')->where('repo_id', $id)->orderByDesc('imported_at')->first();
        $data['people'] = DB::table('v_contributors')->where('repo_id', $id)->orderByDesc('commits')->get();
        $data['commits'] = DB::table('commits')->where('repo_id', $id)->orderByDesc('authored_at')->get();
        if ($tab === 'overview') {
            $data['totals'] = DB::selectOne("SELECT
    (SELECT count(*) FROM commits WHERE repo_id=?) AS commits,
    (SELECT count(*) FROM files WHERE repo_id=?) AS files,
    (SELECT count(*) FROM v_bug_current WHERE repo_id=? AND status<>'Fixed') AS open,
    (SELECT count(*) FROM v_bug_current WHERE repo_id=? AND status='Fixed') AS fixed,
    (SELECT count(*) FROM v_measurement_history WHERE repo_id=? AND warning) AS warnings", array_fill(0, 5, $id));
            $data['trend'] = DB::table('v_complexity_trend')->where('repo_id', $id)->orderBy('authored_at')->get();
            $data['activity'] = DB::table('v_daily_activity')->where('repo_id', $id)->orderByDesc('day')->limit(14)->get()->reverse();
        }
        if ($tab === 'history') {
            $data['history'] = DB::table('v_file_history')->where('repo_id', $id)
                ->when($r->filled('path'), fn ($q) => $q->where('path', 'ilike', '%'.$r->string('path').'%'))
                ->when($r->filled('author'), fn ($q) => $q->where('developer_id', $r->integer('author')))
                ->orderByDesc('authored_at')->paginate(30)->withQueryString();
        }
        if ($tab === 'analysis') {
            $data['runs'] = DB::table('analysis_runs as a')->join('v_file_history as h', 'h.change_id', '=', 'a.change_id')->where('h.repo_id', $id)->select('a.*', 'h.path', 'h.sha')->orderByDesc('a.id')->get();
            $data['measurements'] = DB::table('v_measurement_history')->where('repo_id', $id)->whereNotNull('measurement_id')->orderByDesc('authored_at')->paginate(40);
            $data['pending'] = DB::table('changes as ch')->join('files as f', 'f.id', '=', 'ch.file_id')->where('f.repo_id', $id)->whereNotExists(fn ($q) => $q->selectRaw('1')->from('analysis_runs as a')->whereColumn('a.change_id', 'ch.id')->where('a.engine', 'lizard-1.25.0'))->count();
        }
        if ($tab === 'compare') {
            $data['files'] = DB::table('files')->where('repo_id', $id)->orderBy('path')->get();
            $data['comparisons'] = [];
            $data['fileTrend'] = collect();
            if ($r->filled('file')) {
                $r->validate(['file' => 'integer', 'before' => 'nullable|integer', 'after' => 'nullable|integer']);
                $file = DB::table('files')->where('repo_id', $id)->find($r->integer('file'));
                abort_unless($file, 404);
                $data['fileTrend'] = DB::select('SELECT sha,authored_at,round(avg(score),2) AS mean_complexity,max(score) AS max_complexity FROM v_measurement_history WHERE repo_id=? AND path=? AND measurement_id IS NOT NULL GROUP BY sha,authored_at ORDER BY authored_at', [$id, $file->path]);
                foreach (['before', 'after'] as $side) {
                    $data[$side] = DB::table('v_measurement_history')->where('repo_id', $id)->where('path', $file->path)->where('commit_id', $r->integer($side))->get();
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
