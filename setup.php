<?php

/**
 * What a fresh upload runs: fetches the release this zip names (bundle.json) and puts it in
 * runtime/<version>/, in steps small enough for any host. No dependencies: the code isn't here yet.
 * The release is exactly what `composer install` gives for the same composer.lock, so a site can move
 * to Composer whenever it likes. index.php comes here only while there is no vendor/.
 */

declare(strict_types=1);

$budget = microtime(true) + 12;
$bundle = is_file(__DIR__.'/bundle.json') ? json_decode((string) file_get_contents(__DIR__.'/bundle.json'), true) : null;
$runtime = __DIR__.'/runtime';
$work = $runtime.'/.setup';
$lang = substr(strtolower((string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? 'en')), 0, 2);
$words = [
    'en' => ['title' => 'Setting up :brand', 'lead' => 'Getting your site ready. This takes about a minute, and you can leave this page open.', 'needs' => 'First, this server needs:', 'php' => 'PHP 8.4 or newer', 'zip' => 'The zip extension', 'curl' => 'The curl extension', 'write' => 'Write access to this folder', 'go' => 'Set Up', 'retry' => 'Try Again', 'download' => 'Downloading', 'check' => 'Checking the download', 'unpack' => 'Unpacking', 'almost' => 'Starting up', 'nobundle' => 'This folder has no release to fetch. Run composer install here, or download a new copy.', 'damaged' => 'The download was damaged. Try again.', 'failed' => 'That did not work. Your server said:', 'network' => 'This server could not reach the download. Check that it may connect to the internet, then try again.'],
    'nl' => ['title' => ':brand instellen', 'lead' => 'Je site wordt klaargezet. Dit duurt ongeveer een minuut; laat deze pagina open.', 'needs' => 'Deze server heeft eerst nodig:', 'php' => 'PHP 8.4 of nieuwer', 'zip' => 'De zip-extensie', 'curl' => 'De curl-extensie', 'write' => 'Schrijfrechten op deze map', 'go' => 'Instellen', 'retry' => 'Opnieuw Proberen', 'download' => 'Downloaden', 'check' => 'Download controleren', 'unpack' => 'Uitpakken', 'almost' => 'Opstarten', 'nobundle' => 'In deze map staat geen release om op te halen. Voer hier composer install uit, of download een nieuwe kopie.', 'damaged' => 'De download is beschadigd. Probeer het opnieuw.', 'failed' => 'Dat is niet gelukt. Je server meldde:', 'network' => 'Deze server kan de download niet bereiken. Controleer of hij verbinding met internet mag maken en probeer het opnieuw.'],
    'fr' => ['title' => 'Installation de :brand', 'lead' => 'Votre site se prépare. Cela prend environ une minute ; laissez cette page ouverte.', 'needs' => 'Ce serveur a d’abord besoin de :', 'php' => 'PHP 8.4 ou plus récent', 'zip' => 'L’extension zip', 'curl' => 'L’extension curl', 'write' => 'Droits d’écriture sur ce dossier', 'go' => 'Installer', 'retry' => 'Réessayer', 'download' => 'Téléchargement', 'check' => 'Vérification du téléchargement', 'unpack' => 'Décompression', 'almost' => 'Démarrage', 'nobundle' => 'Ce dossier ne contient aucune version à récupérer. Lancez composer install ici, ou téléchargez une nouvelle copie.', 'damaged' => 'Le téléchargement est endommagé. Réessayez.', 'failed' => 'Cela n’a pas fonctionné. Votre serveur indique :', 'network' => 'Ce serveur n’arrive pas à joindre le téléchargement. Vérifiez qu’il peut se connecter à Internet, puis réessayez.'],
    'de' => ['title' => ':brand einrichten', 'lead' => 'Deine Website wird vorbereitet. Das dauert etwa eine Minute; lass diese Seite geöffnet.', 'needs' => 'Dieser Server braucht zuerst:', 'php' => 'PHP 8.4 oder neuer', 'zip' => 'Die zip-Erweiterung', 'curl' => 'Die curl-Erweiterung', 'write' => 'Schreibrechte für diesen Ordner', 'go' => 'Einrichten', 'retry' => 'Erneut versuchen', 'download' => 'Herunterladen', 'check' => 'Download wird geprüft', 'unpack' => 'Entpacken', 'almost' => 'Wird gestartet', 'nobundle' => 'In diesem Ordner liegt kein Release zum Abrufen. Führe hier composer install aus oder lade eine neue Kopie herunter.', 'damaged' => 'Der Download ist beschädigt. Versuche es erneut.', 'failed' => 'Das hat nicht geklappt. Dein Server meldet:', 'network' => 'Dieser Server erreicht den Download nicht. Prüfe, ob er sich mit dem Internet verbinden darf, und versuche es erneut.'],
];
$text = $words[$lang] ?? $words['en'];

/** One of the three steps, as far as this request's time allows. @return array{step: string, done?: int, total?: int} */
$advance = static function (string $step) use ($bundle, $runtime, $work, $budget, $text): array {
    $version = (string) $bundle['version'];
    $zipFile = $work.'/release.zip';
    is_dir($work) || mkdir($work, 0o755, true);

    if ($step === 'download') {
        $total = (int) $bundle['size'];
        $have = is_file($zipFile) ? (int) filesize($zipFile) : 0;

        if ($have < $total) {
            $url = (string) $bundle['url'];
            $out = fopen($zipFile, 'ab');
            $curl = curl_init($url);
            curl_setopt_array($curl, [CURLOPT_FILE => $out, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 12, CURLOPT_FAILONERROR => true, CURLOPT_RANGE => $have.'-', CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS]);
            $ok = curl_exec($curl);
            $code = curl_errno($curl);
            fclose($out);
            clearstatcache();

            // A timeout only ends this request's share: the next one resumes where it stopped.
            if (! $ok && $code !== CURLE_OPERATION_TIMEDOUT) {
                throw new RuntimeException($text['network']);
            }

            $have = (int) filesize($zipFile);
        }

        return $have >= $total ? ['step' => 'check'] : ['step' => 'download', 'done' => $have, 'total' => $total];
    }

    if ($step === 'check') {
        if (! hash_equals((string) $bundle['sha256'], (string) hash_file('sha256', $zipFile))) {
            @unlink($zipFile);

            throw new RuntimeException($text['damaged']);
        }

        return ['step' => 'unpack', 'done' => 0, 'total' => 1];
    }

    $zip = new ZipArchive;

    if ($zip->open($zipFile) !== true) {
        @unlink($zipFile);

        throw new RuntimeException($text['damaged']);
    }

    $cursor = (int) ($_POST['cursor'] ?? 0);
    $total = $zip->numFiles;

    for (; $cursor < $total && microtime(true) < $budget; ) {
        $batch = [];

        for (; $cursor < $total && count($batch) < 400; $cursor++) {
            $name = (string) $zip->getNameIndex($cursor);

            if (str_contains($name, '..') || str_contains($name, '\\')) {
                throw new RuntimeException('Unsafe path in the release: '.$name);
            }

            $batch[] = $name;
        }

        if (! $zip->extractTo($work.'/files', $batch)) {
            throw new RuntimeException($zip->getStatusString());
        }
    }

    $zip->close();

    if ($cursor < $total) {
        return ['step' => 'unpack', 'done' => $cursor, 'total' => $total];
    }

    if (! is_file("{$work}/files/runtime/{$version}/vendor/autoload.php") || ! @rename("{$work}/files/runtime/{$version}", "{$runtime}/{$version}")) {
        throw new RuntimeException($text['failed'].' runtime/'.$version);
    }

    $tmp = $runtime.'/.current.'.bin2hex(random_bytes(4));
    file_put_contents($tmp, $version."\n");
    rename($tmp, $runtime.'/current');

    // What's left of the work area can wait for the next tidy-up if this fails.
    @unlink($zipFile);
    $remove = static function (string $dir) use (&$remove): void {
        foreach (scandir($dir) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') {
                is_dir("{$dir}/{$name}") && ! is_link("{$dir}/{$name}") ? $remove("{$dir}/{$name}") : @unlink("{$dir}/{$name}");
            }
        }

        @rmdir($dir);
    };
    $remove($work);

    return ['step' => 'done'];
};

$writable = is_writable(__DIR__) && (is_dir($runtime) || @mkdir($runtime, 0o755, true)) && is_writable($runtime);
$problems = array_keys(array_filter([
    'zip' => ! class_exists(ZipArchive::class),
    'curl' => ! function_exists('curl_init'),
    'write' => ! $writable,
]));

header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    header('Content-Type: application/json');

    try {
        if (! is_array($bundle) || $problems !== []) {
            throw new RuntimeException($text['nobundle']);
        }

        echo json_encode($advance((string) ($_POST['step'] ?? 'download')));
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }

    return;
}

// Anything but the page itself waits for the site to exist.
if (strtok((string) ($_SERVER['REQUEST_URI'] ?? '/'), '?') !== '/' && ! str_ends_with((string) strtok((string) ($_SERVER['REQUEST_URI'] ?? '/'), '?'), '/')) {
    header('Location: ./', true, 302);

    return;
}

$brand = htmlspecialchars((string) ($bundle['brand'] ?? ''), ENT_QUOTES);
$mark = (string) ($bundle['mark'] ?? '');
?>
<!doctype html>
<html lang="<?= htmlspecialchars(isset($words[$lang]) ? $lang : 'en', ENT_QUOTES) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= str_replace(':brand', $brand, $text['title']) ?></title>
<style>
:root{color-scheme:light dark;--page:#f6f5f2;--card:#fff;--line:#e8e6e1;--ink:#17160f;--muted:#6f6d65;--bar:#ffe100;--track:#eeece6;--danger:#c0352b;--button:#17160f;--on-button:#fff}
@media (prefers-color-scheme:dark){:root{--page:#141411;--card:#1c1c18;--line:#2c2b26;--ink:#f4f3ee;--muted:#a09e94;--track:#2c2b26;--danger:#ef7a6f;--button:#f4f3ee;--on-button:#17160f}}
*{box-sizing:border-box}
body{margin:0;min-height:100dvh;display:grid;place-items:center;padding:24px 16px;background:var(--page);color:var(--ink);font:15px/1.5 ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif;-webkit-font-smoothing:antialiased}
main{width:100%;max-width:440px;background:var(--card);border:1px solid var(--line);border-radius:18px;padding:32px}
.brand{display:flex;align-items:center;gap:10px;font-weight:600;margin-bottom:24px}
.brand img{width:24px;height:24px}
h1{font-size:22px;line-height:1.25;font-weight:600;letter-spacing:-.015em;margin:0 0 8px}
p{margin:0;color:var(--muted)}
ul{list-style:none;margin:16px 0 0;padding:0;display:grid;gap:6px}
li{color:var(--danger)}
.track{height:6px;border-radius:99px;background:var(--track);overflow:hidden;margin-top:24px}
.bar{height:100%;width:0;border-radius:99px;background:var(--bar);transition:width .3s ease}
.status{margin-top:10px;font-size:13px;color:var(--muted);min-height:20px}
.error{margin-top:16px;color:var(--danger)}
button{margin-top:24px;width:100%;height:44px;border:0;border-radius:10px;background:var(--button);color:var(--on-button);font:inherit;font-weight:600;cursor:pointer}
button:focus-visible{outline:2px solid var(--muted);outline-offset:2px}
[hidden]{display:none}
@media (prefers-reduced-motion:reduce){.bar{transition:none}}
</style>
</head>
<body>
<main>
  <div class="brand"><?php if ($mark !== ''): ?><img src="<?= htmlspecialchars($mark, ENT_QUOTES) ?>" alt=""><?php endif ?><?= $brand ?></div>
  <h1><?= str_replace(':brand', $brand, $text['title']) ?></h1>
  <?php if (! is_array($bundle)): ?>
    <p><?= $text['nobundle'] ?></p>
  <?php elseif ($problems !== []): ?>
    <p><?= $text['needs'] ?></p>
    <ul><?php foreach ($problems as $problem): ?><li><?= $text[$problem] ?></li><?php endforeach ?></ul>
    <button type="button" onclick="location.reload()"><?= $text['retry'] ?></button>
  <?php else: ?>
    <p id="lead"><?= $text['lead'] ?></p>
    <div class="track"><div class="bar" id="bar"></div></div>
    <p class="status" id="status" role="status"></p>
    <p class="error" id="error" role="alert" hidden></p>
    <button type="button" id="retry" hidden><?= $text['retry'] ?></button>
    <script>
    (() => {
      const text = <?= json_encode(array_intersect_key($text, array_flip(['download', 'check', 'unpack', 'almost', 'failed'])), JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) ?>;
      const bar = document.getElementById('bar');
      const status = document.getElementById('status');
      const error = document.getElementById('error');
      const retry = document.getElementById('retry');
      // Download 0-70, check 70-75, unpack 75-100.
      const spans = { download: [0, 70], check: [70, 75], unpack: [75, 100] };
      let state = { step: 'download', done: 0, total: 1 };
      let misses = 0;
      const show = (s) => {
        const [from, to] = spans[s.step] || [100, 100];
        bar.style.width = (from + (to - from) * (s.total ? s.done / s.total : 0)) + '%';
        status.textContent = text[s.step] || text.almost;
      };
      async function run() {
        error.hidden = retry.hidden = true;
        while (state.step !== 'done') {
          show(state);
          const body = new URLSearchParams({ step: state.step, cursor: state.done || 0 });
          let answer;
          try {
            answer = await fetch(location.href, { method: 'POST', body });
            const json = await answer.json();
            if (!answer.ok) throw new Error(json.error);
            state = json;
            misses = 0;
          } catch (e) {
            // A time limit on one request only pauses it: the next one carries on.
            if (!answer?.ok && answer?.status !== 500 && ++misses < 4) { await new Promise((r) => setTimeout(r, 1000)); continue; }
            error.textContent = (e.message && e.message !== 'undefined') ? e.message : text.failed;
            error.hidden = retry.hidden = false;
            return;
          }
        }
        bar.style.width = '100%';
        status.textContent = text.almost;
        location.assign('install');
      }
      retry.addEventListener('click', run);
      run();
    })();
    </script>
  <?php endif ?>
</main>
</body>
</html>
