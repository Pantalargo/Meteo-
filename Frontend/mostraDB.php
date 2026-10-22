<?php
date_default_timezone_set('Europe/Rome');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: frame-ancestors 'none'");

const DB_PATH = '/opt/weather/weather.db';
const PER_PAGINA = 100;

function esc($v): string { return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function valore($v, int $d = 1): string { return number_format((float) $v, $d); }

function dataMeteo($utc, string $f = 'd/m/Y H:i'): string {
    try {
        return (new DateTimeImmutable((string) $utc, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('Europe/Rome'))->format($f);
    } catch (Exception $e) {
        return '—';
    }
}

/** Parametro GET sempre come stringa (scarta gli array). */
function parametroGet(string $nome, string $default = ''): string {
    $v = $_GET[$nome] ?? $default;
    return is_string($v) ? $v : $default;
}

$isAjax = isset($_GET['ajax']);

// ---------- Input validato ----------
$dataFiltro = parametroGet('data_filtro');
$dataObj = DateTimeImmutable::createFromFormat('!Y-m-d', $dataFiltro);
if (!$dataObj || $dataObj->format('Y-m-d') !== $dataFiltro) $dataFiltro = '';

$tempCondizione = parametroGet('temp_cond', 'maggiore') === 'minore' ? 'minore' : 'maggiore';

$tempValore = parametroGet('temp_val');
if ($tempValore !== '' && (!is_numeric($tempValore) || !is_finite((float) $tempValore))) $tempValore = '';

$offsetRaw = parametroGet('offset');
$offset = ($isAjax && ctype_digit($offsetRaw) && strlen($offsetRaw) <= 9) ? (int) $offsetRaw : 0;

// ---------- Query ----------
$rilevamenti = [];
$errore = null;
$totaleFiltrati = 0;

try {
    $db = new PDO('sqlite:' . DB_PATH, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 5,
    ]);

    $where = [];
    $parametri = [];

    if ($dataFiltro !== '') {
        // Giorno locale -> intervallo UTC (usa l'indice, gestisce l'ora legale)
        $tzUtc = new DateTimeZone('UTC');
        $inizio = new DateTimeImmutable($dataFiltro . ' 00:00:00', new DateTimeZone('Europe/Rome'));
        $where[] = 'data_ora >= :da AND data_ora < :a';
        $parametri[':da'] = $inizio->setTimezone($tzUtc)->format('Y-m-d H:i:s');
        $parametri[':a'] = $inizio->modify('+1 day')->setTimezone($tzUtc)->format('Y-m-d H:i:s');
    }

    if ($tempValore !== '') {
        $operatore = $tempCondizione === 'minore' ? '<' : '>'; // whitelist
        $where[] = "percepita $operatore CAST(:temperatura AS REAL)";
        $parametri[':temperatura'] = (string) (float) $tempValore;
    }

    $sqlWhere = $where ? ' WHERE ' . implode(' AND ', $where) : '';

    $stmtCount = $db->prepare('SELECT COUNT(*) FROM dati_meteo' . $sqlWhere);
    $stmtCount->execute($parametri);
    $totaleFiltrati = (int) $stmtCount->fetchColumn();

    $stmt = $db->prepare('SELECT id, data_ora, temperatura, percepita, umidita, descrizione FROM dati_meteo'
        . $sqlWhere . ' ORDER BY data_ora DESC, id DESC LIMIT :limite OFFSET :offset');
    foreach ($parametri as $k => $val) $stmt->bindValue($k, $val);
    $stmt->bindValue(':limite', PER_PAGINA, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rilevamenti = $stmt->fetchAll();
} catch (Throwable $e) {
    error_log('[mostraDB] ' . $e->getMessage());
    $errore = 'Servizio temporaneamente non disponibile.';
}

$caricatiTotali = $offset + count($rilevamenti);

// ---------- Rendering condiviso (pagina + AJAX) ----------
function renderRighe(array $righe, bool $mostraVuoto): string {
    if (!$righe) {
        return $mostraVuoto ? '<tr><td colspan="6" class="vuoto">Nessun rilevamento trovato.</td></tr>' : '';
    }
    $o = '';
    foreach ($righe as $r) {
        $o .= '<tr><td>' . esc($r['id']) . '</td>'
            . '<td class="mono">' . esc(dataMeteo($r['data_ora'], 'd/m/Y H:i:s')) . '</td>'
            . '<td class="caldo">' . valore($r['temperatura']) . '°C</td>'
            . '<td class="percepita">' . valore($r['percepita']) . '°C</td>'
            . '<td>' . esc($r['umidita']) . '%</td>'
            . '<td class="cap">' . esc($r['descrizione']) . '</td></tr>';
    }
    return $o;
}

function renderPaginazione(int $totale, int $caricati): string {
    $rimanenti = max(0, $totale - $caricati);
    $info = 'Mostrati <strong>' . $caricati . '</strong> di <strong>' . $totale . '</strong> risultati';
    if ($rimanenti > 0) {
        return '<div class="pag"><span>' . $info . '</span><button type="button" id="btn-more" class="pill primario">Carica altre '
            . min(PER_PAGINA, $rimanenti) . '</button></div>';
    }
    return '<div class="pag"><span>' . $info . ' (tutti caricati)</span></div>';
}

if ($isAjax) {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    if ($errore !== null) http_response_code(500);
    echo json_encode([
        'success' => $errore === null,
        'errore' => $errore,
        'html' => renderRighe($rilevamenti, $offset === 0),
        'paginazione' => renderPaginazione($totaleFiltrati, $caricatiTotali),
        'caricatiTotali' => $caricatiTotali,
    ], JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Archivio - Stazione Meteo</title>
    <link rel="manifest" href="manifest.webmanifest">
    <meta name="theme-color" content="#3b82f6">
    <link rel="icon" type="image/png" href="icons/icon-192.png">
    <link rel="apple-touch-icon" href="icons/icon-192.png">
    <link rel="stylesheet" href="stile.css?v=<?= (int) @filemtime(__DIR__ . '/stile.css') ?>">
</head>
<body class="archivio">
<div class="container">

    <header class="header">
        <h1 class="pill">Archivio dati</h1>
        <a href="index.php" class="pill">&lsaquo; Home</a>
    </header>

    <div id="error-container">
        <?php if ($errore): ?><div class="errore"><b>Attenzione</b><?= esc($errore) ?></div><?php endif; ?>
    </div>

    <section class="bolla">
        <form id="filter-form" method="GET" action="mostraDB.php" class="filtri">
            <div class="campo">
                <label for="data_filtro">Giorno</label>
                <input type="date" id="data_filtro" name="data_filtro" value="<?= esc($dataFiltro) ?>">
            </div>
            <div class="campo">
                <label for="temp_cond">Condizione</label>
                <select id="temp_cond" name="temp_cond">
                    <option value="maggiore" <?= $tempCondizione === 'maggiore' ? 'selected' : '' ?>>Maggiore di (&gt;)</option>
                    <option value="minore" <?= $tempCondizione === 'minore' ? 'selected' : '' ?>>Minore di (&lt;)</option>
                </select>
            </div>
            <div class="campo">
                <label for="temp_val">Temp. percepita</label>
                <input type="number" id="temp_val" name="temp_val" step="0.1" placeholder="Es. 22.5" value="<?= esc($tempValore) ?>">
            </div>
            <div class="azioni">
                <button type="submit" class="pill primario">Filtra</button>
                <button type="button" id="btn-reset" class="pill">Reset</button>
            </div>
        </form>
    </section>

    <section class="bolla">
        <div class="scroll">
            <table>
                <thead><tr><th>ID</th><th>Data e ora</th><th>Temp.</th><th>Percepita</th><th>Umidità</th><th>Descrizione</th></tr></thead>
                <tbody id="table-body"><?= renderRighe($rilevamenti, true) ?></tbody>
            </table>
        </div>
        <div id="pagination-wrapper" data-loaded="<?= (int) $caricatiTotali ?>"><?= renderPaginazione($totaleFiltrati, $caricatiTotali) ?></div>
    </section>

</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('filter-form');
    const tableBody = document.getElementById('table-body');
    const pagination = document.getElementById('pagination-wrapper');
    const errorBox = document.getElementById('error-container');

    let loaded = Number(pagination.dataset.loaded) || 0;
    let filtri = new URLSearchParams(new FormData(form)); // filtri dell'ultima ricerca eseguita
    let busy = false;

    const showError = (msg) => {
        errorBox.replaceChildren();
        if (!msg) return;
        const box = document.createElement('div');
        box.className = 'errore';
        const b = document.createElement('b');
        b.textContent = 'Attenzione';
        box.append(b, msg);
        errorBox.append(box);
    };

    // append=false: nuova ricerca (sostituisce la tabella); append=true: accoda solo le righe nuove
    const fetchPage = async (offset, append) => {
        if (busy) return;
        busy = true;
        const params = new URLSearchParams(filtri);
        params.set('ajax', '1');
        params.set('offset', String(offset));
        try {
            const data = await (await fetch('mostraDB.php?' + params.toString())).json();
            if (!data.success) { showError(data.errore || 'Errore durante il caricamento dei dati.'); return; }
            showError(null);
            if (append) tableBody.insertAdjacentHTML('beforeend', data.html);
            else tableBody.innerHTML = data.html;
            loaded = data.caricatiTotali;
            pagination.innerHTML = data.paginazione;
            attach();
        } catch (e) {
            console.error(e);
            showError('Errore di comunicazione con il server.');
        } finally {
            busy = false;
        }
    };

    const attach = () => {
        const more = document.getElementById('btn-more');
        if (more) more.addEventListener('click', () => fetchPage(loaded, true));
    };

    form.addEventListener('submit', (e) => {
        e.preventDefault();
        filtri = new URLSearchParams(new FormData(form));
        fetchPage(0, false);
    });

    document.getElementById('btn-reset').addEventListener('click', () => {
        form.reset();
        document.getElementById('data_filtro').value = '';
        document.getElementById('temp_cond').value = 'maggiore';
        document.getElementById('temp_val').value = '';
        filtri = new URLSearchParams(new FormData(form));
        fetchPage(0, false);
    });

    attach();
});
</script>
<script>if ('serviceWorker' in navigator) navigator.serviceWorker.register('sw.js').catch(() => {});</script>

</body>
</html>
