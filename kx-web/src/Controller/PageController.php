<?php
declare(strict_types=1);

namespace KxWeb\Controller;

use KxWeb\Http\HttpException;
use KxWeb\Http\Request;
use KxWeb\Http\Response;
use KxWeb\Model\CompetitionModel;
use KxWeb\Model\EventModel;
use KxWeb\Model\PhaseModel;

/**
 * Server-rendered public pages for spectators. Mobile-first (people follow
 * from the river bank on phones). The phase page re-renders itself from the
 * public JSON API while the phase is live.
 */
final class PageController extends BaseController
{
    private const PHASE_LABEL = [
        'TIME_TRIAL'      => 'Time Trial',
        'QUALIFICATION'   => 'Qualification',
        'REPECHAGE'       => 'Repechage',
        'QUARTER_FINAL'   => 'Quarter-final',
        'SEMI_FINAL'      => 'Semi-final',
        'FINAL'           => 'Final',
        'OFFICIAL_RESULT' => 'Official Result',
    ];

    private const STATUS_LABEL = [
        'startlist' => 'Start list',
        'live'      => 'LIVE',
        'official'  => 'Official',
    ];

    /** GET / — list of published competitions */
    public function home(Request $request): Response
    {
        $rows = (new CompetitionModel($this->pdo))->listPublished(null, null);

        if ($rows === []) {
            $body = '<p class="empty">No published competitions yet.</p>';
        } else {
            $items = '';
            foreach ($rows as $c) {
                $items .= '<a class="card" href="' . self::e($this->url('/competition/' . $c['slug'])) . '">'
                    . '<span class="card-title">' . self::e($c['name']) . '</span>'
                    . '<span class="card-sub">' . self::e($c['location']) . ', ' . self::e($c['country'])
                    . ' &middot; ' . self::e(self::dates($c['start_date'], $c['end_date'])) . '</span>'
                    . '</a>';
            }
            $body = '<div class="cards">' . $items . '</div>';
        }

        return Response::html($this->layout('Competitions', '<h1>Competitions</h1>' . $body));
    }

    /** GET /competition/{slug} — events of the competition with their phases */
    public function competition(Request $request): Response
    {
        $c = $this->competitionOr404($request->params['slug']);
        $events = (new EventModel($this->pdo))->listWithPhases($c['competition_id']);

        $body = '<h1>' . self::e($c['name']) . '</h1>'
            . '<p class="sub">' . self::e($c['location']) . ', ' . self::e($c['country'])
            . ' &middot; ' . self::e(self::dates($c['start_date'], $c['end_date']))
            . ' &middot; ' . self::e($c['organization']) . '</p>';

        if ($events === []) {
            $body .= '<p class="empty">No events published yet.</p>';
        }

        foreach ($events as $ev) {
            $body .= '<div class="event"><h2>' . self::e($ev['event_name'])
                . ' <span class="code">' . self::e($ev['event_code']) . '</span></h2>';
            if ($ev['phases'] === []) {
                $body .= '<p class="empty">No start lists yet.</p>';
            } else {
                $body .= '<div class="phases">';
                foreach ($ev['phases'] as $p) {
                    $href = $this->url('/competition/' . $c['slug'] . '/' . $ev['event_code'] . '/' . $p['phase']);
                    $body .= '<a class="phase-link status-' . self::e($p['status']) . '" href="' . self::e($href) . '">'
                        . self::e(self::PHASE_LABEL[$p['phase']] ?? $p['phase'])
                        . ' <span class="badge">' . self::e(self::STATUS_LABEL[$p['status']] ?? $p['status']) . '</span></a>';
                }
                $body .= '</div>';
            }
            $body .= '</div>';
        }

        return Response::html($this->layout($c['name'], $body, $this->crumbs([
            [$this->url('/'), 'Competitions'],
        ])));
    }

    /** GET /competition/{slug}/{eventCode} — redirect to the most relevant phase */
    public function event(Request $request): Response
    {
        $c = $this->competitionOr404($request->params['slug']);
        $events = (new EventModel($this->pdo))->listWithPhases($c['competition_id']);

        foreach ($events as $ev) {
            if ($ev['event_code'] === $request->params['eventCode']) {
                if ($ev['phases'] === []) {
                    throw new HttpException(404, 'No published phases for this event');
                }
                // Most relevant: live > last published in phase order
                $target = $ev['phases'][count($ev['phases']) - 1];
                foreach ($ev['phases'] as $p) {
                    if ($p['status'] === 'live') {
                        $target = $p;
                        break;
                    }
                }
                $url = $this->url('/competition/' . $c['slug'] . '/' . $ev['event_code'] . '/' . $target['phase']);
                return Response::html(
                    '<!doctype html><meta http-equiv="refresh" content="0;url=' . self::e($url) . '">',
                    302,
                    ['Location' => $url]
                );
            }
        }
        throw new HttpException(404, 'Event not found');
    }

    /** GET /competition/{slug}/{eventCode}/{phase} — start list / result table, live-updating */
    public function phase(Request $request): Response
    {
        $c = $this->competitionOr404($request->params['slug']);
        $phaseName = strtoupper($request->params['phase']);
        if (!in_array($phaseName, PhaseModel::PHASES, true)) {
            throw new HttpException(404, 'Unknown phase');
        }

        $data = (new PhaseModel($this->pdo))->publicPhase(
            $c['competition_id'], $request->params['eventCode'], $phaseName
        );
        if ($data === null) {
            throw new HttpException(404, 'Phase not published');
        }

        $apiUrl = $this->url('/api/v1/public/competitions/' . $c['slug']
            . '/events/' . $data['event_code'] . '/phases/' . $phaseName);

        $title = $data['event_name'] . ' — ' . (self::PHASE_LABEL[$phaseName] ?? $phaseName);
        $body = '<h1>' . self::e($data['event_name'])
            . ' <span class="code">' . self::e($data['event_code']) . '</span></h1>'
            . '<p class="sub">' . self::e(self::PHASE_LABEL[$phaseName] ?? $phaseName)
            . ' <span id="statusBadge" class="badge status-' . self::e($data['status']) . '">'
            . self::e(self::STATUS_LABEL[$data['status']] ?? $data['status']) . '</span></p>'
            . '<div id="tables">' . $this->renderPhaseTables($data) . '</div>'
            . $this->phaseScript($apiUrl, $data['status']);

        return Response::html(
            $this->layout($title, $body, $this->crumbs([
                [$this->url('/'), 'Competitions'],
                [$this->url('/competition/' . $c['slug']), $c['name']],
            ])),
            200,
            ['Cache-Control' => $data['status'] === 'official' ? 'max-age=300' : 'no-cache']
        );
    }

    // ------------------------------------------------------------------
    // Rendering helpers
    // ------------------------------------------------------------------

    /** One table per group; start-list order when no ranks, result order otherwise. */
    private function renderPhaseTables(array $data): string
    {
        $byGroup = [];
        foreach ($data['entries'] as $e) {
            $byGroup[$e['grp']][] = $e;
        }
        if ($byGroup === []) {
            return '<p class="empty">No athletes yet.</p>';
        }

        $isTT = $data['phase'] === 'TIME_TRIAL' || $data['phase'] === 'OFFICIAL_RESULT';
        $gateCount = (int)$data['gates'];
        $html = '';

        foreach ($byGroup as $grp => $entries) {
            $anyRank = array_filter($entries, fn ($e) => $e['rank'] !== null) !== [];
            if ($anyRank) {
                usort($entries, fn ($a, $b) =>
                    ($a['rank'] ?? 999) <=> ($b['rank'] ?? 999) ?: $a['slot_no'] <=> $b['slot_no']);
            }

            if (count($byGroup) > 1) {
                $html .= '<h3>Heat ' . self::e((string)$grp) . '</h3>';
            }
            $html .= '<table class="results"><thead><tr>'
                . '<th class="num">' . ($anyRank ? 'Rank' : 'Slot') . '</th>'
                . '<th class="num">Bib</th><th>Name</th><th class="hide-sm">Club</th><th>Ctry</th>';
            $html .= '<th class="gate">Faults</th>'
                . '<th class="num">' . ($isTT ? 'Time' : 'Result') . '</th></tr></thead><tbody>';

            foreach ($entries as $e) {
                $penaltyClass = ($e['dsq'] || $e['ral']) ? ' class="pen"' : '';
                $html .= '<tr' . $penaltyClass . '>'
                    . '<td class="num">' . self::e((string)($anyRank ? ($e['rank'] ?? '') : $e['slot_no'])) . '</td>'
                    . '<td class="num">' . self::e((string)($e['bib'] ?? '')) . '</td>'
                    . '<td>' . self::e($e['first_name'] . ' ' . $e['last_name']) . '</td>'
                    . '<td class="hide-sm">' . self::e($e['club']) . '</td>'
                    . '<td>' . self::e($e['country']) . '</td>';
                $html .= '<td class="gate">' . self::e(self::faultList($e['gates'], $gateCount)) . '</td>';
                $html .= '<td class="num">' . self::e(self::resultCell($e, $isTT)) . '</td></tr>';
            }
            $html .= '</tbody></table>';
        }
        return $html;
    }

    private static function resultCell(array $e, bool $isTT): string
    {
        if ($e['dsq']) {
            return 'DSQ';
        }
        if ($e['dns']) {
            return 'DNS';
        }
        if ($e['dnf']) {
            return 'DNF';
        }
        if ($isTT && self::faultList($e['gates'] ?? [], count($e['gates'] ?? [])) !== '') {
            return '';   // a faulted TT run shows only the fault, never the time
        }
        if ($isTT && $e['score'] !== null) {
            $s = (float)$e['score'];
            return $s >= 60
                ? sprintf('%d:%05.2f', intdiv((int)$s, 60), fmod($s, 60.0))
                : sprintf('%.2f', $s);
        }
        return $e['rank'] !== null ? (string)$e['rank'] . '.' : '';
    }

    /** "FLT G3, RAL G5" from the gates array (FLT=1, RAL=2). */
    private static function faultList(array $gates, int $gateCount): string
    {
        $parts = [];
        foreach (array_slice($gates, 0, $gateCount) as $i => $g) {
            if ($g !== null) {
                $parts[] = ($g === 2 ? 'RAL' : 'FLT') . ' G' . ($i + 1);
            }
        }
        return implode(', ', $parts);
    }

    /** JS: poll the public JSON while live and re-render the tables client-side. */
    private function phaseScript(string $apiUrl, string $status): string
    {
        if ($status !== 'live' && $status !== 'startlist') {
            return '';
        }
        $api = json_encode($apiUrl);
        // Client-side renderer mirrors renderPhaseTables (kept intentionally simple)
        return <<<HTML
<script>
const API = $api;
const PH = {startlist:'Start list', live:'LIVE', official:'Official'};
const esc = s => String(s ?? '').replace(/[&<>"']/g,
  ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));
function cell(e, isTT) {
  if (e.dsq) return 'DSQ';
  if (e.dns) return 'DNS';
  if (e.dnf) return 'DNF';
  if (isTT && e.gates.some(g => g != null)) return '';  // fault: no time shown
  if (isTT && e.score != null) {
    const s = e.score;
    return s >= 60 ? Math.floor(s/60) + ':' + (s%60).toFixed(2).padStart(5,'0') : s.toFixed(2);
  }
  return e.rank != null ? e.rank + '.' : '';
}
function render(d) {
  document.getElementById('statusBadge').textContent = PH[d.status] ?? d.status;
  document.getElementById('statusBadge').className = 'badge status-' + d.status;
  const isTT = d.phase === 'TIME_TRIAL' || d.phase === 'OFFICIAL_RESULT';
  const groups = {};
  for (const e of d.entries) (groups[e.grp] ??= []).push(e);
  let html = '';
  const keys = Object.keys(groups);
  for (const grp of keys) {
    let es = groups[grp];
    const anyRank = es.some(e => e.rank != null);
    if (anyRank) es = [...es].sort((a,b) => (a.rank ?? 999) - (b.rank ?? 999) || a.slot_no - b.slot_no);
    if (keys.length > 1) html += '<h3>Heat ' + esc(grp) + '</h3>';
    html += '<table class="results"><thead><tr><th class="num">' + (anyRank ? 'Rank' : 'Slot')
         + '</th><th class="num">Bib</th><th>Name</th><th class="hide-sm">Club</th><th>Ctry</th>';
    html += '<th class="gate">Faults</th>'
         + '<th class="num">' + (isTT ? 'Time' : 'Result') + '</th></tr></thead><tbody>';
    for (const e of es) {
      html += '<tr' + ((e.dsq || e.ral) ? ' class="pen"' : '') + '><td class="num">'
        + esc(anyRank ? (e.rank ?? '') : e.slot_no) + '</td><td class="num">' + esc(e.bib ?? '')
        + '</td><td>' + esc(e.first_name + ' ' + e.last_name) + '</td><td class="hide-sm">'
        + esc(e.club) + '</td><td>' + esc(e.country) + '</td>';
      const faults = e.gates.slice(0, d.gates)
        .map((g, i) => g == null ? null : (g === 2 ? 'RAL' : 'FLT') + ' G' + (i + 1))
        .filter(Boolean).join(', ');
      html += '<td class="gate">' + esc(faults) + '</td>';
      html += '<td class="num">' + esc(cell(e, isTT)) + '</td></tr>';
    }
    html += '</tbody></table>';
  }
  document.getElementById('tables').innerHTML = html || '<p class="empty">No athletes yet.</p>';
}
let etag = null;
async function poll() {
  try {
    const r = await fetch(API, { headers: etag ? { 'If-None-Match': etag } : {} });
    if (r.status === 200) {
      etag = r.headers.get('ETag');
      const d = await r.json();
      render(d);
      if (d.status === 'official') return;   // final state: stop polling
    }
  } catch (e) { /* transient network error — keep trying */ }
  setTimeout(poll, 5000);
}
setTimeout(poll, 5000);
</script>
HTML;
    }

    private function crumbs(array $links): string
    {
        $parts = [];
        foreach ($links as [$href, $label]) {
            $parts[] = '<a href="' . self::e($href) . '">' . self::e($label) . '</a>';
        }
        return '<nav class="crumbs">' . implode(' › ', $parts) . '</nav>';
    }

    private function competitionOr404(string $slug): array
    {
        $c = (new CompetitionModel($this->pdo))->findPublishedBySlug($slug);
        if ($c === null) {
            throw new HttpException(404, 'Competition not found');
        }
        return $c;
    }

    private static function dates(string $start, string $end): string
    {
        return $start === $end ? $start : $start . ' – ' . $end;
    }

    private function layout(string $title, string $body, string $nav = ''): string
    {
        $title = self::e($title);
        return <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>$title — KX Results</title>
<style>
  :root { --line:#e3e9ef; --dim:#5a7286; --accent:#0b5cad; --live:#c0392b; }
  * { box-sizing:border-box; }
  body { font:15px/1.45 system-ui,-apple-system,Segoe UI,sans-serif; margin:0;
         color:#1c2b39; background:#f6f8fa; }
  main { max-width:900px; margin:0 auto; padding:12px 14px 40px; }
  h1 { font-size:1.35rem; margin:.6rem 0 .1rem; }
  h2 { font-size:1.05rem; margin:1.4rem 0 .4rem; }
  h3 { font-size:.9rem; color:var(--dim); margin:1rem 0 .3rem; }
  .sub { color:var(--dim); margin:.1rem 0 1rem; }
  .code { color:var(--dim); font-weight:normal; font-size:.8em; }
  .crumbs { font-size:.85rem; padding:.5rem 0; }
  .crumbs a { color:var(--accent); text-decoration:none; }
  .empty { color:var(--dim); font-style:italic; }
  .cards { display:flex; flex-direction:column; gap:8px; margin-top:1rem; }
  .card { display:block; background:#fff; border:1px solid var(--line); border-radius:8px;
          padding:10px 14px; text-decoration:none; color:inherit; }
  .card-title { display:block; font-weight:600; }
  .card-sub { display:block; color:var(--dim); font-size:.85rem; }
  .phases { display:flex; flex-wrap:wrap; gap:6px; }
  .phase-link { background:#fff; border:1px solid var(--line); border-radius:6px;
                padding:6px 10px; text-decoration:none; color:inherit; font-size:.9rem; }
  .badge { font-size:.72rem; padding:1px 6px; border-radius:8px;
           background:#e8eef4; color:var(--dim); vertical-align:middle; }
  .status-live .badge, .badge.status-live { background:var(--live); color:#fff; }
  .badge.status-official, .status-official .badge { background:#256029; color:#fff; }
  table.results { border-collapse:collapse; width:100%; background:#fff;
                  border:1px solid var(--line); border-radius:8px; overflow:hidden;
                  margin:.4rem 0 1rem; font-size:.92rem; }
  .results th { text-align:left; font-size:.75rem; text-transform:uppercase;
                color:var(--dim); padding:6px 8px; border-bottom:2px solid var(--line); }
  .results td { padding:6px 8px; border-bottom:1px solid var(--line); }
  .results tr:last-child td { border-bottom:none; }
  .num { text-align:right; width:1%; white-space:nowrap; }
  .gate { color:var(--live); }
  tr.pen td { background:#fdf3f2; }
  @media (max-width:560px) { .hide-sm { display:none; } }
</style>
</head>
<body>
<main>
$nav
$body
</main>
</body>
</html>
HTML;
    }

    private static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}
