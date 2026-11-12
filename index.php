<?php
declare(strict_types=1);

function runGit(array $arguments, string $directory): string
{
    $process = proc_open(
        array_merge(['git'], $arguments),
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        $directory
    );

    if (!is_resource($process)) {
        throw new RuntimeException('Could not start Git.');
    }

    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    if ($exitCode !== 0) {
        throw new RuntimeException(trim($error) ?: 'Git command failed.');
    }

    return $output;
}

function escapeHtml(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function chooseFile(): ?string
{
    if (PHP_OS_FAMILY !== 'Darwin') {
        throw new RuntimeException('Sistemsko okno za izbiro datoteke je trenutno podprto samo v macOS.');
    }

    $process = proc_open(
        ['osascript', '-e', 'POSIX path of (choose file with prompt "Izberi datoteko za prikaz Git zgodovine")'],
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes
    );

    if (!is_resource($process)) {
        throw new RuntimeException('Sistemskega okna za izbiro datoteke ni bilo mogoče odpreti.');
    }

    fclose($pipes[0]);
    $output = trim(stream_get_contents($pipes[1]));
    $error = trim(stream_get_contents($pipes[2]));
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    if ($exitCode !== 0) {
        if (stripos($error, 'User canceled') !== false || stripos($error, 'User cancelled') !== false) {
            return null;
        }

        throw new RuntimeException($error ?: 'Izbira datoteke ni uspela.');
    }

    return $output;
}

$commits = [];
$branches = [];
$fileCommitHashes = [];
$selectedFile = '';
$error = '';

$cancelled = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['choose_file'])) {
    try {
        $chosenFile = chooseFile();
        if ($chosenFile === null) {
            $cancelled = true;
        } else {
            $absoluteFile = realpath($chosenFile);
            if ($absoluteFile === false || !is_file($absoluteFile)) {
                throw new RuntimeException('Izbrana datoteka ne obstaja ali ni običajna datoteka.');
            }

            $repositoryRoot = realpath(trim(runGit(['rev-parse', '--show-toplevel'], dirname($absoluteFile))));
            if ($repositoryRoot === false) {
                throw new RuntimeException('Korenske mape Git repozitorija ni bilo mogoče najti.');
            }

            $rootPrefix = rtrim($repositoryRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
            if (!str_starts_with($absoluteFile, $rootPrefix)) {
                throw new RuntimeException('Izbrana datoteka ni znotraj Git repozitorija.');
            }

            $selectedFile = str_replace(
                DIRECTORY_SEPARATOR,
                '/',
                substr($absoluteFile, strlen($rootPrefix))
            );
            $branchRefs = runGit([
                'for-each-ref',
                '--format=%(refname:short)%00%(objectname)%00%(symref)',
                'refs/heads',
                'refs/remotes',
            ], $repositoryRoot);

            foreach (array_filter(explode("\n", trim($branchRefs)), static fn(string $line): bool => $line !== '') as $line) {
                [$name, $tip, $symbolicRef] = array_pad(explode("\0", $line, 3), 3, '');
                if ($symbolicRef !== '') {
                    continue;
                }

                $branches[] = [
                    'name' => $name,
                    'tip' => $tip,
                ];
            }

            $history = runGit([
                'log',
                '--all',
                '--topo-order',
                '--date=short',
                '--format=%H%x1f%P%x1f%ad%x1f%s',
                '--max-count=250',
            ], $repositoryRoot);

            foreach (array_filter(explode("\n", trim($history)), static fn(string $line): bool => $line !== '') as $line) {
                $parts = explode("\x1f", $line, 4);
                if (count($parts) !== 4) {
                    continue;
                }

                [$hash, $parents, $date, $subject] = $parts;
                $commits[] = [
                    'hash' => $hash,
                    'parents' => preg_split('/\s+/', trim($parents), -1, PREG_SPLIT_NO_EMPTY) ?: [],
                    'date' => $date,
                    'subject' => $subject,
                ];
            }

            $fileHistory = runGit([
                'log',
                '--all',
                '--follow',
                '--format=%H',
                '--',
                $selectedFile,
            ], $repositoryRoot);
            $fileCommitHashes = array_fill_keys(
                array_filter(explode("\n", trim($fileHistory)), static fn(string $hash): bool => $hash !== ''),
                true
            );
        }
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    }
}

$historyHashes = array_fill_keys(array_column($commits, 'hash'), true);
$branchesOutsideGraph = array_values(array_filter(
    $branches,
    static fn(array $branch): bool => !isset($historyHashes[$branch['tip']])
));
?>
<!doctype html>
<html lang="sl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>FileVersionTree</title>
    <style>
        :root { color-scheme: light dark; font: 16px/1.5 system-ui, sans-serif; }
        body { max-width: 860px; margin: 3rem auto; padding: 0 1.25rem; }
        h1 { margin-bottom: .25rem; }
        .muted { color: GrayText; }
        form { display: flex; flex-wrap: wrap; align-items: end; gap: .75rem; margin: 2rem 0; }
        label { display: grid; gap: .35rem; }
        select, button { font: inherit; padding: .5rem .75rem; }
        select { min-width: min(28rem, 75vw); }
        .error { padding: .75rem 1rem; border-left: 4px solid #d33; background: color-mix(in srgb, #d33 10%, transparent); }
        code { overflow-wrap: anywhere; }
        .graph { overflow: auto; margin: 1.5rem 0; border: 1px solid #8886; border-radius: .6rem; }
        .graph svg { display: block; min-width: 100%; }
        .graph-edge { fill: none; stroke-width: 3; }
        .graph-branch { font: 12px system-ui, sans-serif; }
        .graph-node { stroke: Canvas; stroke-width: 2; cursor: pointer; }
        .graph-node.file-change { stroke: #e58b24; stroke-width: 4; }
        .graph-node.selected { stroke: #e33; stroke-width: 4; }
        .graph-label { font: 12px system-ui, sans-serif; fill: CanvasText; }
        .graph-help { margin-top: 1rem; }
        .branch-list { display: flex; flex-wrap: wrap; gap: .5rem; list-style: none; padding: 0; }
    </style>
</head>
<body>
    <h1>FileVersionTree</h1>
    <p class="muted">Izberi datoteko iz katerega koli lokalnega Git repozitorija.</p>

    <form method="post">
        <button type="submit" name="choose_file" value="1">Izberi datoteko …</button>
    </form>

    <?php if ($error !== ''): ?>
        <p class="error"><?= escapeHtml($error) ?></p>
    <?php elseif ($cancelled): ?>
        <p class="muted">Izbira datoteke je bila preklicana.</p>
    <?php endif; ?>

    <?php if ($selectedFile !== ''): ?>
        <h2><?= escapeHtml($selectedFile) ?></h2>
        <?php if ($commits === []): ?>
            <p>Za to datoteko v Git zgodovini ni najdenih commitov.</p>
        <?php else: ?>
            <p class="muted">Graf prikazuje do 250 commitov iz vseh lokalnih in prenesenih vej. Oranžna obroba označuje commit, ki je spremenil izbrano datoteko.</p>
            <div class="graph" id="graph" role="img" aria-label="Graf Git commitov in vej"></div>
            <p class="graph-help muted" id="commit-details" aria-live="polite">Izberi vozlišče za podrobnosti commita.</p>
            <?php if ($branchesOutsideGraph !== []): ?>
                <h3>Veje</h3>
                <ul class="branch-list">
                    <?php foreach ($branchesOutsideGraph as $branch): ?>
                        <li><?= escapeHtml($branch['name']) ?> <span class="muted">(glava ni med prikazanimi 250 commiti)</span></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <script>
                const commits = <?= json_encode($commits, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_THROW_ON_ERROR) ?>;
                const branches = <?= json_encode($branches, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_THROW_ON_ERROR) ?>;
                const fileCommits = new Set(<?= json_encode(array_keys($fileCommitHashes), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_THROW_ON_ERROR) ?>);
                const commitByHash = new Map(commits.map(commit => [commit.hash, commit]));
                const activeLanes = [];
                const columns = new Map();
                let maxColumn = 0;

                for (const commit of commits) {
                    let lane = activeLanes.indexOf(commit.hash);
                    if (lane === -1) {
                        lane = activeLanes.length;
                        activeLanes.push(commit.hash);
                    }
                    columns.set(commit.hash, lane);
                    maxColumn = Math.max(maxColumn, lane);

                    const parents = commit.parents.filter(parent => commitByHash.has(parent));
                    if (parents.length === 0) {
                        activeLanes.splice(lane, 1);
                        continue;
                    }

                    const firstParentLane = activeLanes.indexOf(parents[0]);
                    if (firstParentLane !== -1 && firstParentLane !== lane) {
                        activeLanes.splice(lane, 1);
                    } else {
                        activeLanes[lane] = parents[0];
                    }

                    let insertAt = lane + 1;
                    for (const parent of parents.slice(1)) {
                        if (!activeLanes.includes(parent)) {
                            activeLanes.splice(insertAt++, 0, parent);
                        }
                    }
                }

                const nodeGap = 44;
                const laneGap = 34;
                const left = 28;
                const topOffset = 28;
                const graphWidth = Math.max(260, left * 2 + (maxColumn + 1) * laneGap);
                const graphHeight = topOffset * 2 + Math.max(0, commits.length - 1) * nodeGap;
                const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
                svg.setAttribute('viewBox', `0 0 ${graphWidth} ${graphHeight}`);
                svg.setAttribute('width', graphWidth);
                svg.setAttribute('height', graphHeight);
                const colors = ['#5687dc', '#d36bb1', '#29a37a', '#dc8b37', '#8a72c2', '#42a2b8'];
                const x = hash => left + columns.get(hash) * laneGap;
                const y = hash => topOffset + commits.findIndex(commit => commit.hash === hash) * nodeGap;
                const make = (name, attributes, text = '') => {
                    const element = document.createElementNS('http://www.w3.org/2000/svg', name);
                    for (const [key, value] of Object.entries(attributes)) element.setAttribute(key, value);
                    if (text) element.textContent = text;
                    return element;
                };

                for (const commit of commits) {
                    for (const parent of commit.parents.filter(hash => commitByHash.has(hash))) {
                        const startX = x(commit.hash);
                        const startY = y(commit.hash);
                        const endX = x(parent);
                        const endY = y(parent);
                        const middleY = (startY + endY) / 2;
                        svg.append(make('path', {
                            d: `M ${startX} ${startY} C ${startX} ${middleY}, ${endX} ${middleY}, ${endX} ${endY}`,
                            class: 'graph-edge',
                            stroke: colors[columns.get(commit.hash) % colors.length]
                        }));
                    }
                }

                const branchesByTip = new Map();
                for (const branch of branches) {
                    if (!commitByHash.has(branch.tip)) continue;
                    const names = branchesByTip.get(branch.tip) || [];
                    names.push(branch.name);
                    branchesByTip.set(branch.tip, names);
                }

                commits.forEach((commit, index) => {
                    const cx = x(commit.hash);
                    const cy = topOffset + index * nodeGap;
                    const node = make('circle', {
                        cx, cy, r: 7,
                        class: `graph-node${fileCommits.has(commit.hash) ? ' file-change' : ''}`,
                        fill: colors[columns.get(commit.hash) % colors.length],
                        tabindex: 0,
                        role: 'button',
                        'aria-label': `${commit.date} ${commit.subject}`
                    });
                    const title = make('title', {}, `${commit.date} ${commit.hash.slice(0, 12)} ${commit.subject}`);
                    node.append(title);
                    const showDetails = () => {
                        document.querySelectorAll('.graph-node.selected').forEach(selected => selected.classList.remove('selected'));
                        node.classList.add('selected');
                        document.getElementById('commit-details').textContent =
                            `${commit.date} · ${commit.hash} · ${commit.subject}` +
                            `${fileCommits.has(commit.hash) ? ' · spremenil je izbrano datoteko' : ''}`;
                    };
                    node.addEventListener('click', showDetails);
                    node.addEventListener('keydown', event => {
                        if (event.key === 'Enter' || event.key === ' ') {
                            event.preventDefault();
                            showDetails();
                        }
                    });
                    svg.append(node);

                    const names = branchesByTip.get(commit.hash) || [];
                    if (names.length > 0) {
                        const label = names.join(', ');
                        svg.append(make('text', {
                            x: cx + 12, y: cy + 4, class: 'graph-branch',
                            fill: colors[columns.get(commit.hash) % colors.length]
                        }, label));
                    }

                    const subjectX = graphWidth + 18;
                    svg.append(make('text', {
                        x: subjectX, y: cy + 4, class: 'graph-label'
                    }, `${commit.date}  ${commit.subject}`));
                });

                svg.setAttribute('viewBox', `0 0 ${graphWidth + 460} ${graphHeight}`);
                svg.setAttribute('width', graphWidth + 460);
                document.getElementById('graph').append(svg);
            </script>
        <?php endif; ?>
    <?php endif; ?>
</body>
</html>
