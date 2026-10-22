<?php
date_default_timezone_set('Europe/Rome');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: frame-ancestors 'none'");

const DB_PATH = '/opt/weather/weather.db';
const FORECAST_PATH = '/opt/weather/forecast.json';

function esc($v): string { return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function valore($v, int $d = 1): string { return number_format((float) $v, $d); }
function rnd(): float { return mt_rand() / mt_getrandmax(); }

/** Timestamp UTC del DB -> ora locale (gestisce l'ora legale). */
function dataMeteo($utc, string $f = 'd/m/Y H:i'): string {
    try {
        return (new DateTimeImmutable((string) $utc, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('Europe/Rome'))->format($f);
    } catch (Exception $e) {
        return '—';
    }
}

function wmo(int $c, bool $g): array {
    $m = [0 => ['Sereno', '☀️', '🌙'], 1 => ['Quasi sereno', '🌤️', '🌙'], 2 => ['Parzialmente nuvoloso', '⛅', '☁️'], 3 => ['Nuvoloso', '☁️', '☁️'],
        61 => ['Pioggia debole', '🌦️', '🌧️'], 63 => ['Pioggia', '🌧️', '🌧️'], 65 => ['Pioggia forte', '🌧️', '🌧️'],
        73 => ['Neve', '❄️', '❄️'], 75 => ['Neve forte', '❄️', '❄️'], 82 => ['Acquazzone forte', '🌧️', '🌧️']]
        + array_fill_keys([45, 48], ['Nebbia', '🌫️', '🌫️'])
        + array_fill_keys([51, 53, 55], ['Pioviggine', '🌦️', '🌧️'])
        + array_fill_keys([56, 57], ['Pioviggine gelata', '🌧️', '🌧️'])
        + array_fill_keys([66, 67], ['Pioggia gelata', '🌧️', '🌧️'])
        + array_fill_keys([71, 77], ['Neve debole', '🌨️', '🌨️'])
        + array_fill_keys([80, 81], ['Acquazzone', '🌦️', '🌧️'])
        + array_fill_keys([85, 86], ['Rovesci di neve', '🌨️', '🌨️'])
        + array_fill_keys([95, 96, 99], ['Temporale', '⛈️', '⛈️']);
    $r = $m[$c] ?? ['—', '☁️', '☁️'];
    return [$r[0], $g ? $r[1] : $r[2]];
}

/** Ultimo rilevamento -> [scena, è giorno, emoji]. Usa il codice icona OpenWeather (suffisso d/n = giorno/notte). */
function scena(?array $r): array {
    $ic = (string) ($r['icona_codice'] ?? '');
    if (preg_match('/^(\d{2})([dn])$/', $ic, $m)) {
        $p = $m[1];
        $g = $m[2] === 'd';
    } else {
        $h = (int) date('G');
        $g = $h >= 7 && $h < 19;
        $d = mb_strtolower((string) ($r['descrizione'] ?? ''));
        $p = preg_match('/temporal/', $d) ? '11'
            : (preg_match('/nev/', $d) ? '13'
            : (preg_match('/piog|piov|acquaz/', $d) ? '10'
            : (preg_match('/nebbi|foschi/', $d) ? '50'
            : (preg_match('/nuvol|copert/', $d) ? '04' : '01'))));
    }
    $mappa = ['01' => ['sereno', '☀️', '🌙'], '02' => ['nuvoloso', '🌤️', '☁️'], '03' => ['nuvoloso', '⛅', '☁️'],
        '04' => ['nuvoloso', '☁️', '☁️'], '09' => ['pioggia', '🌧️', '🌧️'], '10' => ['pioggia', '🌦️', '🌧️'],
        '11' => ['temporale', '⛈️', '⛈️'], '13' => ['neve', '❄️', '❄️'], '50' => ['nebbia', '🌫️', '🌫️']];
    $x = $mappa[$p] ?? $mappa['01'];
    return [$x[0], $g, $g ? $x[1] : $x[2]];
}

/** N elementi decorativi con posizione, durata, ritardo e scala casuali. */
function strato(int $n, string $cls, float $t0, float $t1, float $s0 = .6, float $s1 = 1.2): string {
    $o = '';
    for ($i = 0; $i < $n; $i++) {
        $t = $t0 + rnd() * ($t1 - $t0);
        $o .= sprintf('<i class="%s" style="--x:%d%%;--y:%d%%;--t:%.1fs;--w:-%.1fs;--s:%.2f"></i>',
            $cls, mt_rand(0, 100), mt_rand(0, 100), $t, rnd() * $t, $s0 + rnd() * ($s1 - $s0));
    }
    return $o;
}

function cielo(string $scena, bool $giorno, string $vento): string {
    $h = '<div class="sky" aria-hidden="true"><div class="astro"></div>';
    if (!$giorno && $scena === 'sereno') $h .= strato(28, 'stella', 2.5, 6, .5, 1.3);
    if ($scena !== 'sereno') $h .= strato($scena === 'nuvoloso' ? 4 : 5, 'nuvola', 70, 130, .8, 1.4);
    if ($scena === 'pioggia' || $scena === 'temporale') $h .= strato(55, 'goccia', .55, 1.0, .7, 1.1);
    if ($scena === 'temporale') $h .= '<div class="lampo"></div>';
    if ($scena === 'neve') $h .= strato(40, 'fiocco', 7, 14, .5, 1.3);
    if ($scena === 'nebbia') $h .= '<div class="foschia"></div><div class="foschia f2"></div>';
    if ($vento !== 'calmo') {
        $h .= $vento === 'forte' ? strato(9, 'raffica', 1.4, 2.6) : strato(5, 'raffica', 3, 5.5);
    }
    return $h . '</div>';
}

function sezioneValida($sezione, array $chiavi): bool {
    if (!is_array($sezione)) return false;
    foreach ($chiavi as $k) {
        if (!isset($sezione[$k]) || !is_array($sezione[$k])) return false;
    }
    return true;
}

// ---------- Dati ----------
$errore = null;
$storico = [];
$previsioni = null;

try {
    $db = new PDO('sqlite:' . DB_PATH, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 5,
    ]);
    $storico = $db->query('SELECT * FROM dati_meteo ORDER BY data_ora DESC LIMIT 20')->fetchAll();
} catch (Throwable $e) {
    error_log('[index] ' . $e->getMessage());
    $errore = 'Dati temporaneamente non disponibili.';
}
$ultimo = $storico[0] ?? null;

if (is_readable(FORECAST_PATH)) {
    try {
        $dec = json_decode(file_get_contents(FORECAST_PATH), true, 16, JSON_THROW_ON_ERROR);
        $previsioni = is_array($dec) ? $dec : null;
    } catch (Throwable $e) {
        error_log('[forecast] ' . $e->getMessage());
    }
}

if (isset($_GET['ajax'])) {
    header('Cache-Control: no-store');
    renderApp($errore, $ultimo, $previsioni, $storico);
    exit;
}

function renderApp($errore, $ultimo, $prev, $storico) {
    [$scena, $giorno, $emoji] = scena($ultimo);
    $v = (float) ($ultimo['velocita_vento'] ?? 0);
    $vento = $v >= 10 ? 'forte' : ($v >= 5 ? 'medio' : 'calmo');
    $hOk = sezioneValida($prev['hourly'] ?? null, ['time', 'weather_code', 'temperature_2m']);
    $dOk = sezioneValida($prev['daily'] ?? null, ['time', 'weather_code', 'temperature_2m_max', 'temperature_2m_min']);
    $gs = ['Dom', 'Lun', 'Mar', 'Mer', 'Gio', 'Ven', 'Sab'];
?>
<div id="app" class="<?= $giorno ? 'tema-giorno' : 'tema-notte' ?> scena-<?= $scena ?> vento-<?= $vento ?>">
<?= cielo($scena, $giorno, $vento) ?>
<div class="container">

    <header class="header">
        <h1 class="pill">Stazione Meteo</h1>
        <a href="mostraDB.php" class="pill">Archivio &rsaquo;</a>
    </header>

    <?php if ($errore): ?>
        <div class="errore"><b>Impossibile caricare i dati</b><?= esc($errore) ?></div>
    <?php endif; ?>

    <?php if ($ultimo): ?>
        <section class="bolla hero">
            <h2 class="citta"><?= esc($ultimo['localita']) ?></h2>
            <div class="em"><?= $emoji ?></div>
            <div class="temp"><?= valore($ultimo['temperatura']) ?>&deg;</div>
            <div class="desc"><?= esc($ultimo['descrizione']) ?></div>
            <span class="pill">Rilevato il <?= esc(dataMeteo($ultimo['data_ora'])) ?></span>
        </section>

        <section class="tessere">
            <div class="tessera"><small>Percepita</small><b><?= valore($ultimo['percepita']) ?>&deg;</b></div>
            <div class="tessera"><small>Umidità</small><b><?= esc($ultimo['umidita']) ?>%</b></div>
            <div class="tessera"><small>Pressione</small><b><?= esc($ultimo['pressione']) ?></b><em>hPa</em></div>
            <div class="tessera"><small>Vento</small><b><?= valore($v) ?></b><em>m/s &middot; <?= valore($v * 3.6, 0) ?> km/h</em></div>
        </section>
    <?php endif; ?>

    <?php if ($hOk): ?>
        <section class="bolla">
            <h3 class="titolo">Prossime 24 ore</h3>
            <div class="orario">
                <?php
                $h = $prev['hourly'];
                $start = array_search(date('Y-m-d\TH:00'), $h['time'], true);
                if ($start === false) $start = 0;
                $fine = min($start + 24, count($h['time']));
                for ($i = $start; $i < $fine; $i++):
                    if (!isset($h['weather_code'][$i], $h['temperature_2m'][$i])) continue;
                    $ts = (int) strtotime((string) $h['time'][$i]);
                    $ora = (int) date('G', $ts);
                    $g = isset($h['is_day'][$i]) ? (bool) $h['is_day'][$i] : ($ora >= 7 && $ora < 19);
                    [$desc, $em] = wmo((int) $h['weather_code'][$i], $g);
                    $pr = (int) ($h['precipitation_probability'][$i] ?? 0);
                ?>
                    <div class="capsula" title="<?= esc($desc) ?>">
                        <span><?= $i === $start ? 'Ora' : esc(date('H:i', $ts)) ?></span>
                        <span class="em"><?= $em ?></span>
                        <b><?= (int) round((float) $h['temperature_2m'][$i]) ?>&deg;</b>
                        <?php if ($pr > 0): ?><small>💧 <?= $pr ?>%</small><?php endif; ?>
                    </div>
                <?php endfor; ?>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($dOk): ?>
        <section class="bolla">
            <h3 class="titolo">Prossimi giorni</h3>
            <div class="giorni">
                <?php
                $d = $prev['daily'];
                for ($i = 0, $n = count($d['time']); $i < $n; $i++):
                    if (!isset($d['weather_code'][$i], $d['temperature_2m_max'][$i], $d['temperature_2m_min'][$i])) continue;
                    $ts = (int) strtotime((string) $d['time'][$i]);
                    [$desc, $em] = wmo((int) $d['weather_code'][$i], true);
                    $pr = (int) ($d['precipitation_probability_max'][$i] ?? 0);
                ?>
                    <div class="riga" title="<?= esc($desc) ?>">
                        <span class="g"><?= $i === 0 ? 'Oggi' : esc($gs[(int) date('w', $ts)] . ' ' . date('d/m', $ts)) ?></span>
                        <span class="em"><?= $em ?></span>
                        <span class="p"><?= $pr > 0 ? '💧 ' . $pr . '%' : '' ?></span>
                        <span class="r"><?= (int) round((float) $d['temperature_2m_max'][$i]) ?>&deg;<i><?= (int) round((float) $d['temperature_2m_min'][$i]) ?>&deg;</i></span>
                    </div>
                <?php endfor; ?>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($storico): ?>
        <section class="bolla">
            <h3 class="titolo">Tendenze recenti</h3>
            <div class="scroll">
                <table>
                    <thead><tr><th>Orario</th><th>Temp</th><th>Umidità</th><th>Vento</th><th>Condizioni</th></tr></thead>
                    <tbody>
                        <?php foreach ($storico as $r): ?>
                            <tr>
                                <td><?= esc(dataMeteo($r['data_ora'])) ?></td>
                                <td class="caldo"><?= valore($r['temperatura']) ?>&deg;</td>
                                <td><?= esc($r['umidita']) ?>%</td>
                                <td><?= valore($r['velocita_vento']) ?> m/s</td>
                                <td class="cap"><?= esc($r['descrizione']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>

    <footer class="credito">
        Dati meteo forniti da <a href="https://open-meteo.com/" target="_blank" rel="noopener noreferrer">Open-Meteo</a>
    </footer>

</div>
</div>
<?php
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Stazione Meteo</title>
    <link rel="manifest" href="manifest.webmanifest">
    <meta name="theme-color" content="#2f80ed">
    <link rel="icon" type="image/png" href="icons/icon-192.png">
    <link rel="apple-touch-icon" href="icons/icon-192.png">
    <link rel="stylesheet" href="stile.css?v=<?= (int) @filemtime(__DIR__ . '/stile.css') ?>">
</head>
<body>
<?php renderApp($errore, $ultimo, $previsioni, $storico); ?>
<script>
if ('serviceWorker' in navigator) navigator.serviceWorker.register('sw.js').catch(() => {});

// La barra di stato di Android prende il colore del cielo (giorno/notte/meteo)
const colorTema = () => {
    const app = document.getElementById('app'), meta = document.querySelector('meta[name=theme-color]');
    const c = app ? getComputedStyle(app).getPropertyValue('--c1').trim() : '';
    if (meta && c) meta.content = c;
};
colorTema();

setInterval(async () => {
    if (document.hidden) return;
    try {
        const r = await fetch('index.php?ajax=1');
        if (!r.ok) throw new Error('HTTP ' + r.status);
        const t = document.createElement('template');
        t.innerHTML = (await r.text()).trim();
        const nuovo = t.content.firstElementChild, app = document.getElementById('app');
        if (!nuovo || !app) return;
        // Se tema/condizione/vento sono cambiati ricarica anche il cielo, altrimenti solo i contenuti
        if (nuovo.className !== app.className) app.replaceWith(nuovo);
        else app.querySelector('.container').replaceWith(nuovo.querySelector('.container'));
        colorTema();
    } catch (e) {
        console.error('Aggiornamento fallito:', e);
    }
}, 300000);
</script>
</body>
</html>
