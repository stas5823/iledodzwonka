<?php
// --- Czas przechowywania danych w pamięci podręcznej (cache) ---
// 3600 sekund = 1 godzina. Po tym czasie strona pobierze świeże newsy.
define('CACHE_TTL', 3600);

// Wczytuje zapisane wcześniej newsy z pliku, jeśli są wystarczająco świeże
function loadCache(string $file): array {
    if (file_exists($file) && (time() - filemtime($file)) < CACHE_TTL) {
        return json_decode(file_get_contents($file), true)['items'] ?? [];
    }
    return [];
}

// Zapisuje pobrane newsy do pliku, żeby nie pobierać ich za każdym razem
function saveCache(string $file, array $items): void {
    file_put_contents($file, json_encode(['items' => $items], JSON_UNESCAPED_UNICODE));
}

// Pobiera listę newsów z podanych adresów RSS (specjalny format dla wiadomości)
function fetchRssFeed(array $urls, string $cacheFile): array {
    // Najpierw sprawdź, czy mamy już zapisane newsy – jeśli tak, użyj ich
    $cached = loadCache($cacheFile);
    if (!empty($cached)) return $cached;

    // Podszywamy się pod zwykłą przeglądarkę, żeby serwery nas nie blokowały
    $headers = [
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Accept-Language: pl-PL,pl;q=0.9',
        'Accept-Encoding: identity',
    ];

    // Próbujemy kolejno każdy adres RSS, aż znajdziemy działający
    foreach ($urls as $url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,  // zwróć odpowiedź zamiast ją wypisywać
            CURLOPT_FOLLOWLOCATION => true,  // podążaj za przekierowaniami
            CURLOPT_TIMEOUT        => 15,    // czekaj max 15 sekund na odpowiedź
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => true,  // sprawdzaj certyfikat HTTPS
        ]);
        $body = curl_exec($ch);
        curl_close($ch);

        // Jeśli odpowiedź jest pusta lub to nie jest RSS – pomiń i próbuj dalej
        if (!$body || !preg_match('/<(rss|feed|channel)/i', $body)) continue;
        // Usuń ewentualne śmieci przed właściwą zawartością XML
        $body = preg_replace('/^[\s\S]*?(<\?xml|<rss|<feed)/i', '$1', $body);
        $xml = @simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NOERROR);
        if (!$xml || !isset($xml->channel->item)) continue;

        // Wyciągnij tytuł, link i datę każdego newsa
        $items = [];
        foreach ($xml->channel->item as $item) {
            $items[] = [
                'title' => trim((string)$item->title),
                'link'  => (string)$item->link,
                'date'  => trim((string)($item->pubDate ?? '')),
            ];
        }

        if ($items) {
            saveCache($cacheFile, $items);
            return $items;
        }
    }

    // Jeśli wszystko zawiedzie, wróć do starych zapisanych danych
    if (file_exists($cacheFile)) {
        return json_decode(file_get_contents($cacheFile), true)['items'] ?? [];
    }
    return [];
}

// Pobiera newsy ze strony szkoły SP02 (próbuje RSS, a jak nie ma – parsuje stronę HTML)
function fetchSp02Homepage(string $cacheFile): array {
    $cached = loadCache($cacheFile);
    if (!empty($cached)) return $cached;

    // Lista możliwych adresów RSS na stronie szkoły
    $rssUrls = [
        'https://sp02.edu.bydgoszcz.pl/feed/',
        'https://sp02.edu.bydgoszcz.pl/rss/',
        'https://sp02.edu.bydgoszcz.pl/rss.xml',
        'https://sp02.edu.bydgoszcz.pl/feed/rss/',
        'https://sp02.edu.bydgoszcz.pl/atom/',
    ];

    $rssItems = fetchRssFeed($rssUrls, $cacheFile);
    if (!empty($rssItems)) return $rssItems;

    // Jeśli RSS nie działa, pobierz całą stronę główną i poszukaj newsów w HTML
    $baseUrl = 'https://sp02.edu.bydgoszcz.pl';
    $ch = curl_init($baseUrl . '/');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_ENCODING       => '',  // obsługuj kompresję automatycznie
        CURLOPT_HTTPHEADER     => [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: pl-PL,pl;q=0.9',
        ],
    ]);
    $html = curl_exec($ch);
    curl_close($ch);

    if (!$html) {
        if (file_exists($cacheFile)) return json_decode(file_get_contents($cacheFile), true)['items'] ?? [];
        return [];
    }

    // Metoda 1: szukaj linków do newsów za pomocą struktury dokumentu (DOM)
    $items = extractSp02WithDom($html, $baseUrl);
    if (!empty($items)) {
        saveCache($cacheFile, $items);
        return $items;
    }

    // Metoda 2 (zapasowa): szukaj newsów za pomocą wzorców tekstowych (regex)
    $items = extractSp02WithRegex($html, $baseUrl);
    if (!empty($items)) {
        saveCache($cacheFile, $items);
        return $items;
    }

    if (file_exists($cacheFile)) return json_decode(file_get_contents($cacheFile), true)['items'] ?? [];
    return [];
}

// Wyciąga linki do newsów z HTML, analizując strukturę strony (drzewko elementów)
function extractSp02WithDom(string $html, string $baseUrl): array {
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML($html, LIBXML_NOERROR);
    $xpath = new DOMXPath($dom);
    $items = [];

    // Szukaj linków pasujących do wzorca adresów newsów SP02
    $links = $xpath->query('//a[contains(@href, "-m1,") and contains(@href, ".html")]');
    foreach ($links as $link) {
        $href  = $link->getAttribute('href');
        $title = trim($link->nodeValue);
        // Pomiń zbyt krótkie tytuły i przyciski „czytaj więcej"
        if (mb_strlen($title) < 8) continue;
        if (preg_match('/więcej|czytaj|dalej|więcej\s*»|^\[...\]$/i', $title)) continue;

        // Wyjdź w górę drzewka HTML, żeby znaleźć kontener newsa z datą
        $container = $link;
        while ($container && !in_array($container->nodeName, ['div', 'article', 'li', 'section'])) {
            $container = $container->parentNode;
        }
        if (!$container) $container = $link->parentNode;

        // Szukaj daty w tekście kontenera lub w znaczniku <time>
        $date = '';
        $text = $container->textContent;
        if (preg_match('/(\d{1,2}[.\-]\d{1,2}[.\-]\d{2,4})/', $text, $m)) {
            $date = $m[1];
        } else {
            $times = $xpath->query('.//time', $container);
            if ($times->length > 0) {
                $datetime = $times->item(0)->getAttribute('datetime');
                if ($datetime) $date = $datetime;
            }
        }

        $items[] = [
            'title' => $title,
            'link'  => resolveUrl($baseUrl, $href),
            'date'  => $date,
        ];
        if (count($items) >= 8) break; // pobierz max 8 newsów
    }
    return $items;
}

// Zapasowa metoda: szukaj newsów za pomocą wyrażeń regularnych (wzorców tekstowych)
function extractSp02WithRegex(string $html, string $baseUrl): array {
    // Usuń menu i stopkę – nie ma tam newsów, tylko przeszkadzają
    $clean = preg_replace('/<(nav|header|footer)[\s>].*?<\/\1>/si', '', $html);
    $clean = preg_replace('/<[^>]+class=["\'][^"\']*(?:menu|nav|breadcrumb|topbar|navbar|top-bar|site-header)[^"\']*["\'][^>]*>.*?<\/(?:ul|div|nav)>/si', '', $clean);

    $items = [];
    // Znajdź wszystkie linki pasujące do adresów newsów SP02
    preg_match_all('/<a\s[^>]*href=["\']([^"\']*-m1,[0-9]+\.html)["\'][^>]*>(.*?)<\/a>/si', $clean, $links, PREG_OFFSET_CAPTURE);

    $seen = [];
    foreach ($links[1] as $i => $hrefMatch) {
        $href   = $hrefMatch[0];
        $title  = trim(strip_tags($links[2][$i][0]));
        $offset = $hrefMatch[1];

        // Pomiń duplikaty i przyciski nawigacyjne
        if (mb_strlen($title) < 8 || isset($seen[$href])) continue;
        if (preg_match('/więcej|czytaj|dalej|archiw|kategoria|tag/i', $title)) continue;
        $seen[$href] = true;

        // Szukaj daty w tekście wokół linka (400 znaków przed i 500 po)
        $ctx  = substr($clean, max(0, $offset - 400), 900);
        $date = '';
        if (preg_match('/(\d{1,2}[.\-]\d{1,2}[.\-]\d{2,4})/', $ctx, $m)) $date = $m[1];

        $items[] = [
            'title' => $title,
            'link'  => resolveUrl($baseUrl, $href),
            'date'  => $date,
        ];
        if (count($items) >= 8) break;
    }
    return $items;
}

// Zamienia względny adres URL na pełny (np. /news.html → https://strona.pl/news.html)
function resolveUrl(string $base, string $url): string {
    if (!$url) return '';
    if (preg_match('/^https?:\/\//', $url)) return $url;        // już jest pełny
    if (str_starts_with($url, '//')) return 'https:' . $url;    // brakuje tylko protokołu
    if (str_starts_with($url, '/')) return $base . $url;        // ścieżka bezwzględna
    return $base . '/' . $url;                                  // ścieżka względna
}

// Formatuje datę po polsku, np. "2025-03-14" → "pt., 14 mar"
function formatPolishDate(string $dateString): string {
    if (empty($dateString)) return '';
    $timestamp = strtotime($dateString);
    if ($timestamp === false) return $dateString; // jeśli data jest nieczytelna, pokaż ją bez zmian

    // Użyj wbudowanej biblioteki językowej PHP (jeśli dostępna)
    if (class_exists('IntlDateFormatter')) {
        $formatter = new IntlDateFormatter('pl_PL', IntlDateFormatter::NONE, IntlDateFormatter::NONE, null, null, 'E, dd MMM');
        return $formatter->format($timestamp);
    }

    // Zapasowe tłumaczenie dni i miesięcy ręcznie
    $days   = ['Mon'=>'pon.','Tue'=>'wt.','Wed'=>'śr.','Thu'=>'czw.','Fri'=>'pt.','Sat'=>'sob.','Sun'=>'niedz.'];
    $months = ['Jan'=>'sty','Feb'=>'lut','Mar'=>'mar','Apr'=>'kwi','May'=>'maj','Jun'=>'cze','Jul'=>'lip','Aug'=>'sie','Sep'=>'wrz','Oct'=>'paź','Nov'=>'lis','Dec'=>'gru'];
    $dateStr = date('D, d M', $timestamp);
    $dateStr = str_replace(array_keys($days), array_values($days), $dateStr);
    $dateStr = str_replace(array_keys($months), array_values($months), $dateStr);
    return $dateStr;
}

// --- Pobieranie newsów przy ładowaniu strony ---
// Newsy z miasta Bydgoszcz
$feedBydgoszcz = fetchRssFeed([
    'https://www.bydgoszcz.pl/rss/',
    'https://www.bydgoszcz.pl/feed/',
    'https://www.bydgoszcz.pl/rss.xml',
], __DIR__ . '/cache_bydgoszcz.json');

// Newsy ze strony szkoły SP02
$feedSp02 = fetchSp02Homepage(__DIR__ . '/cache_sp02.json');

// Wyświetla listę newsów jako elementy HTML <li>
function renderItems(array $items): void {
    if (empty($items)) {
        echo '<li class="rss-error">Nie udało się załadować newsów</li>';
        return;
    }
    // Pokaż max 6 newsów, przytnij tytuły do 90 znaków
    foreach (array_slice($items, 0, 6) as $item) {
        $title = htmlspecialchars(mb_substr($item['title'], 0, 90));
        $link  = htmlspecialchars($item['link']);
        $date  = htmlspecialchars(formatPolishDate($item['date'] ?? ''));
        echo '<li class="rss-item">';
        echo "<a href=\"$link\" target=\"_blank\" rel=\"noopener\" class=\"rss-link\">";
        echo "<span class=\"rss-text\"><span class=\"rss-title-text\">$title</span>";
        if ($date) echo "<span class=\"rss-date\">$date</span>";
        echo '</span></a></li>';
    }
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="description" content="Ile do dzwonka? Sprawdź ile zostało do końca lekcji, przerwy lub obiadu w SP02 Bydgoszcz.">
  <link rel="icon" href="favicon.ico" type="image/x-icon">
  <title>Ile do dzwonka?</title>
  <link href="https://fonts.googleapis.com/css2?family=Oxygen:wght@300;400;700&family=Oxygen+Mono&display=swap" rel="stylesheet">
  <style>
    /* --- Kolory i zmienne globalne – zmień tu, żeby dostosować wygląd --- */
    :root {
      --bg:                #1b1e20;
      --bg-alt:            #232629;
      --widget:            #2a2e32;
      --widget-alt:        #31363b;
      --border:            #3a3f44;
      --border-light:      #444a50;
      --text:              #eff0f1;
      --text-dim:          #a0a8b0;
      --text-muted:        #7a8390;
      --accent:            #3daee9;
      --accent-hover:      #5bc0ef;
      --positive:          #27ae60;
      --positive-dim:      #1a4d30;
      --header-bg:         #1f2326;
      --btn-bg:            #31363b;
      --btn-hover:         #3a4048;
      --btn-active-bg:     #1f4f6e;
      --btn-active-border: #3daee9;
    }

    /* Resetowanie domyślnych marginesów przeglądarki */
    * { box-sizing: border-box; margin: 0; padding: 0; }

    body {
      font-family: 'Oxygen', 'Noto Sans', sans-serif;
      font-size: 17px;
      background: var(--bg);
      min-height: 100vh;
      padding: 14px 14px 28px;
      color: var(--text);
    }

    /* Główny kontener strony – wszystko mieści się w max 1380px szerokości */
    .layout {
      max-width: 1380px;
      margin: 0 auto;
      display: flex;
      flex-direction: column;
      gap: 10px;
    }

    /* Siatka dwukolumnowa – górne panele (lekcja i obiad) oraz dolne newsy */
    .top-grid, .news-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 10px;
    }

    /* Pojedynczy panel (kafelek) */
    .panel {
      background: var(--widget);
      border: 1px solid var(--border);
      border-radius: 4px;
      overflow: hidden;
    }

    /* Pasek tytułowy panelu */
    .panel-titlebar {
      background: var(--header-bg);
      border-bottom: 1px solid var(--border);
      padding: 8px 13px;
      display: flex;
      align-items: center;
      gap: 8px;
      font-size: 17px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.6px;
      color: var(--text-dim);
      user-select: none;
    }

    .panel-titlebar svg {
      width: 16px;
      height: 16px;
      opacity: 0.7;
      flex-shrink: 0;
    }

    .panel-titlebar a {
      color: var(--text-dim);
      text-decoration: none;
    }
    .panel-titlebar a:hover { color: var(--accent); }

    .panel-body { padding: 18px 20px 22px; }

    /* Duży napis statusu (np. „Lekcja 3", „Obiad!!") */
    .timer-status {
      font-size: 30px;
      font-weight: 700;
      color: var(--text);
      line-height: 1.2;
      margin-bottom: 3px;
    }

    /* Mały podpis pod statusem (np. „Do przerwy:") */
    .timer-sub {
      font-size: 17px;
      color: var(--text-muted);
      text-transform: uppercase;
      letter-spacing: 0.5px;
      margin-bottom: 8px;
    }

    /* Cyfrowy wyświetlacz odliczania – styl jak stary budzik */
    .timer-display {
      font-family: 'Oxygen Mono', 'Courier New', monospace;
      font-size: 64px;
      font-weight: 400;
      color: var(--accent);
      background: var(--bg);
      border: 1px solid var(--border-light);
      border-top-color: var(--bg);
      border-left-color: var(--bg);
      display: inline-block;
      padding: 5px 12px 5px 10px;
      letter-spacing: 1px;
      border-radius: 2px;
      line-height: 1;
      margin-top: 2px;
      box-shadow: inset 1px 1px 3px rgba(0,0,0,0.4);
    }

    /* Zielony wariant wyświetlacza – używany podczas trwania obiadu */
    .timer-display.green {
      color: var(--positive);
      border-color: var(--positive-dim);
    }

    /* Mały zegar z aktualną godziną */
    .timer-clock {
      font-family: 'Oxygen Mono', monospace;
      font-size: 17px;
      color: var(--text-muted);
      letter-spacing: 2px;
      margin-top: 12px;
    }

    /* Grupa przycisków wyboru godziny (np. 8:00 / 8:55 / 9:50) */
    .kde-btn-group {
      margin-top: 14px;
      display: flex;
      gap: 5px;
      flex-wrap: wrap;
    }

    .kde-btn-group button {
      font-family: 'Oxygen', sans-serif;
      font-size: 16px;
      font-weight: 400;
      padding: 5px 17px;
      background: var(--btn-bg);
      color: var(--text);
      border: 1px solid var(--border-light);
      border-radius: 3px;
      cursor: pointer;
      transition: background 0.1s, border-color 0.1s;
      outline: none;
    }

    .kde-btn-group button:hover {
      background: var(--btn-hover);
      border-color: #555c63;
    }

    .kde-btn-group button:focus-visible { border-color: var(--accent); }

    /* Aktywny (wybrany) przycisk */
    .kde-btn-group button.active {
      background: var(--btn-active-bg);
      border-color: var(--btn-active-border);
      color: var(--text);
    }

    /* Lista newsów */
    .rss-list { list-style: none; padding: 0; margin: 0; }

    .rss-item { border-bottom: 1px solid var(--border); }
    .rss-item:last-child { border-bottom: none; }

    .rss-link {
      display: flex;
      align-items: flex-start;
      gap: 9px;
      padding: 11px 18px;
      text-decoration: none;
      color: var(--text);
      transition: background 0.1s;
    }

    .rss-link:hover { background: var(--widget-alt); }

    /* Strzałka przed każdym newsem */
    .rss-link::before {
      content: '›';
      color: var(--accent);
      font-size: 20px;
      line-height: 1.3;
      flex-shrink: 0;
      margin-top: 1px;
    }

    .rss-text {
      display: flex;
      flex-direction: column;
      gap: 4px;
      min-width: 0;
    }

    .rss-title-text {
      font-size: 17px;
      font-weight: 400;
      color: var(--text);
      line-height: 1.4;
    }

    .rss-link:hover .rss-title-text { color: var(--accent-hover); }

    /* Data newsa (mała, pod tytułem) */
    .rss-date {
      font-family: 'Oxygen Mono', monospace;
      font-size: 14px;
      color: var(--text-muted);
      letter-spacing: 0.3px;
    }

    /* Komunikat o błędzie ładowania newsów */
    .rss-error {
      font-size: 16px;
      color: #da4453;
      padding: 12px 18px;
    }

    /* Pasek stanu na dole strony z linkami */
    .statusbar {
      background: var(--header-bg);
      border: 1px solid var(--border);
      border-radius: 3px;
      padding: 7px 14px;
      display: flex;
      align-items: center;
      gap: 16px;
      font-size: 17px;
      color: var(--text-muted);
    }

    /* Pionowa linia oddzielająca linki w pasku stanu */
    .statusbar-sep {
      width: 1px;
      height: 14px;
      background: var(--border-light);
    }

    .statusbar a {
      color: var(--accent);
      text-decoration: none;
    }
    .statusbar a:hover {
      color: var(--accent-hover);
      text-decoration: underline;
    }

    /* --- Responsywność: na małych ekranach (telefony) jedna kolumna --- */
    @media (max-width: 680px) {
      body { padding: 8px 8px 20px; }
      .top-grid, .news-grid { grid-template-columns: 1fr; }
      .timer-display { font-size: 46px; }
      .timer-status { font-size: 24px; }
    }
  </style>
</head>
<body>

<div class="layout">

  <!-- Górny rząd: panel lekcji (po lewej) i panel obiadu (po prawej) -->
  <div class="top-grid">
    <div class="panel">
      <div class="panel-titlebar">
        <!-- Ikonka zegara SVG (rysowana kodem, bez pliku graficznego) -->
        <svg viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
          <circle cx="8" cy="8" r="6.5" stroke="currentColor" stroke-width="1.2"/>
          <line x1="8" y1="4" x2="8" y2="8.5" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/>
          <line x1="8" y1="8.5" x2="11" y2="10.5" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/>
        </svg>
        Aktualna lekcja / przerwa
      </div>
      <div class="panel-body">
        <!-- Te elementy są wypełniane przez JavaScript co sekundę -->
        <div id="lesson-status" class="timer-status">—</div>
        <div id="lesson-sub" class="timer-sub"></div>
        <div id="lesson-timer" class="timer-display"></div>
        <div id="lesson-clock" class="timer-clock">--:--:--</div>
        <!-- Przyciski wyboru godziny pierwszej lekcji -->
        <div id="start-toggle" class="kde-btn-group">
          <button id="start0800" class="active" onclick="setStartTime(8,0)">8:00</button>
          <button id="start0855" onclick="setStartTime(8,55)">8:55</button>
          <button id="start0950" onclick="setStartTime(9,50)">9:50</button>
        </div>
      </div>
    </div>

    <div class="panel">
      <div class="panel-titlebar">
        <!-- Ikonka talerza/obiadu -->
        <svg viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
          <path d="M3 6h10v5a2 2 0 01-2 2H5a2 2 0 01-2-2V6z" stroke="currentColor" stroke-width="1.2"/>
          <path d="M2 4h12" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/>
          <path d="M6 10h4" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/>
        </svg>
        Obiad
      </div>
      <div class="panel-body">
        <div id="obiad-status" class="timer-status">Do obiadu</div>
        <div id="obiad-sub" class="timer-sub">Obiad o 13:30</div>
        <div id="countdown" class="timer-display">--:--:--</div>
        <div id="clock" class="timer-clock">--:--:--</div>
        <!-- Przyciski wyboru godziny obiadu -->
        <div id="toggle" class="kde-btn-group">
          <button id="btn1330" class="active" onclick="setMode('13:30')">13:30</button>
          <button id="btn1225" onclick="setMode('12:25')">12:25</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Dolny rząd: newsy ze szkoły (po lewej) i z miasta (po prawej) -->
  <div class="news-grid">
    <div class="panel">
      <div class="panel-titlebar">
        <svg viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
          <rect x="2" y="2" width="12" height="12" rx="1.5" stroke="currentColor" stroke-width="1.2"/>
          <line x1="5" y1="6" x2="11" y2="6" stroke="currentColor" stroke-width="1.1" stroke-linecap="round"/>
          <line x1="5" y1="8.5" x2="11" y2="8.5" stroke="currentColor" stroke-width="1.1" stroke-linecap="round"/>
          <line x1="5" y1="11" x2="8.5" y2="11" stroke="currentColor" stroke-width="1.1" stroke-linecap="round"/>
        </svg>
        <a href="https://sp02.edu.bydgoszcz.pl" target="_blank" rel="noopener">sp02.edu.bydgoszcz.pl</a>
      </div>
      <!-- PHP wstawia tutaj listę newsów ze szkoły -->
      <ul class="rss-list"><?php renderItems($feedSp02); ?></ul>
    </div>

    <div class="panel">
      <div class="panel-titlebar">
        <svg viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
          <rect x="2" y="2" width="12" height="12" rx="1.5" stroke="currentColor" stroke-width="1.2"/>
          <line x1="5" y1="6" x2="11" y2="6" stroke="currentColor" stroke-width="1.1" stroke-linecap="round"/>
          <line x1="5" y1="8.5" x2="11" y2="8.5" stroke="currentColor" stroke-width="1.1" stroke-linecap="round"/>
          <line x1="5" y1="11" x2="8.5" y2="11" stroke="currentColor" stroke-width="1.1" stroke-linecap="round"/>
        </svg>
        <a href="https://www.bydgoszcz.pl" target="_blank" rel="noopener">bydgoszcz.pl</a>
      </div>
      <!-- PHP wstawia tutaj listę newsów z miasta -->
      <ul class="rss-list"><?php renderItems($feedBydgoszcz); ?></ul>
    </div>
  </div>

  <!-- Pasek na dole z linkami pomocniczymi -->
  <div class="statusbar">
    <a href="info.html">O stronie</a>
    <div class="statusbar-sep"></div>
    <!-- Zmieniono z zegar.html na tablica.html -->
    <a href="tablica.html">Wersja na tablicę</a>
    <div class="statusbar-sep"></div>
    <a href="mailto:kontakt@iledodzwonka.pl">kontakt@iledodzwonka.pl</a>
  </div>

</div>

<script>
  // ── Konfiguracja ────────────────────────────────────────────────
  // Korekta czasu: dzwonek w tej szkole spóźnia się o 33 sekundy
  const OFFSET = -33;

  // Plan lekcji: start i koniec każdej lekcji/przerwy w minutach od północy
  // Np. 480 minut = 8:00, 525 minut = 8:45 itd.
  const PLAN = [
    {type:'lesson',num:1,  start:480,  end:525},
    {type:'break',  after:1,  start:525,  end:535},
    {type:'lesson',num:2,  start:535,  end:580},
    {type:'break',  after:2,  start:580,  end:590},
    {type:'lesson',num:3,  start:590,  end:635},
    {type:'break',  after:3,  start:635,  end:645},
    {type:'lesson',num:4,  start:645,  end:690},
    {type:'break',  after:4,  start:690,  end:700},
    {type:'lesson',num:5,  start:700,  end:745},
    {type:'break',  after:5,  start:745,  end:765},
    {type:'lesson',num:6,  start:765,  end:810},
    {type:'break',  after:6,  start:810,  end:830},
    {type:'lesson',num:7,  start:830,  end:875},
    {type:'break',  after:7,  start:875,  end:885},
    {type:'lesson',num:8,  start:885,  end:930},
    {type:'break',  after:8,  start:930,  end:940},
    {type:'lesson',num:9,  start:940,  end:985},
    {type:'break',  after:9,  start:985,  end:995},
    {type:'lesson',num:10, start:995,  end:1040},
  ];
  // ────────────────────────────────────────────────────────────────

  // Przelicz minuty na sekundy i dodaj korektę spóźnienia dzwonka
  const SCHEDULE = PLAN.map(s => ({
    ...s,
    startS: s.start * 60 + OFFSET,
    endS:   s.end   * 60 + OFFSET,
  }));

  // Koniec ostatniej lekcji (w sekundach od północy)
  const LAST_S = 1040 * 60 + OFFSET;

  // Zmienna przechowująca wybraną godzinę pierwszej lekcji (domyślnie 8:00)
  let firstHour = 8, firstMin = 0;
  // Zmienna przechowująca wybraną godzinę obiadu (domyślnie 13:30)
  let lunchHour = 13, lunchMin = 30;

  // Pomocnik: uzupełnia liczbę zerami, żeby była dwucyfrowa (np. 9 → "09")
  const pad = n => String(n).padStart(2, '0');

  // Formatuje milisekundy jako czas HH:MM:SS lub MM:SS
  const fmt = (ms, showHours = true) => {
    if (ms <= 0) return showHours ? '00:00:00' : '00:00';
    const t = Math.floor(ms / 1000);
    const h = Math.floor(t / 3600);
    const m = Math.floor(t / 60) % 60;
    const s = t % 60;
    return showHours ? `${pad(h)}:${pad(m)}:${pad(s)}` : `${pad(m)}:${pad(s)}`;
  };

  // Oblicza datę i godzinę następnego dnia szkolnego (pomija sobotę i niedzielę)
  function nextSchoolDay(now, targetS) {
    const day = now.getDay(); // 0=niedziela, 5=piątek, 6=sobota
    const add = day === 5 ? 3 : day === 6 ? 2 : 1; // piątek → +3 dni, sobota → +2, inne → +1
    const d = new Date(now);
    d.setDate(d.getDate() + add);
    d.setHours(Math.floor(targetS / 3600), Math.floor((targetS % 3600) / 60), targetS % 60, 0);
    return d;
  }

  // Obsługuje kliknięcie przycisku wyboru godziny obiadu
  function setMode(t) {
    if (t === '13:30') { lunchHour = 13; lunchMin = 30; }
    else               { lunchHour = 12; lunchMin = 25; }
    // Zaznacz właściwy przycisk jako aktywny
    document.getElementById('btn1330').classList.toggle('active', t === '13:30');
    document.getElementById('btn1225').classList.toggle('active', t === '12:25');
    update(); // odśwież wyświetlane dane natychmiast
  }

  // Obsługuje kliknięcie przycisku wyboru godziny pierwszej lekcji
  function setStartTime(h, m) {
    firstHour = h;
    firstMin  = m;
    // Zaznacz właściwy przycisk jako aktywny
    document.getElementById('start0800').classList.toggle('active', h === 8 && m === 0);
    document.getElementById('start0855').classList.toggle('active', h === 8 && m === 55);
    document.getElementById('start0950').classList.toggle('active', h === 9 && m === 50);
    update(); // odśwież wyświetlane dane natychmiast
  }

  // Główna funkcja aktualizująca wszystkie wyświetlacze – wywoływana co sekundę
  function update() {
    const now = new Date();       // pobierz aktualny czas
    const day = now.getDay();     // dzień tygodnia (0=niedziela … 6=sobota)
    const h = now.getHours(), m = now.getMinutes(), s = now.getSeconds();

    // Aktualizuj zegary z aktualną godziną
    document.getElementById('clock').textContent       = `${pad(h)}:${pad(m)}:${pad(s)}`;
    document.getElementById('lesson-clock').textContent = `${pad(h)}:${pad(m)}:${pad(s)}`;

    const isWd  = day >= 1 && day <= 5; // czy to dzień roboczy (pn–pt)?
    const nowS  = h * 3600 + m * 60 + s; // aktualny czas w sekundach od północy

    // Okno nocne: ukryj przyciski wyboru godziny przed lekcjami i po 17:20
    const hideFrom = 8 * 3600 + OFFSET;
    const hideTo   = 17 * 3600 + 20 * 60 + OFFSET;
    const isNightWindow = nowS < hideFrom || nowS >= hideTo;
    document.getElementById('start-toggle').style.display = (isNightWindow || !isWd) ? 'flex' : 'none';

    // Oblicz godziny obiadu w sekundach (z korektą spóźnienia)
    const lunchStartS = lunchHour * 3600 + lunchMin * 60 + OFFSET;
    const lunchEndS   = lunchStartS + 20 * 60; // obiad trwa 20 minut

    // Elementy HTML panelu obiadu
    const obiadStatus = document.getElementById('obiad-status');
    const obiadSub    = document.getElementById('obiad-sub');
    const countdown   = document.getElementById('countdown');

    // --- Panel obiadu: wyświetl odpowiedni komunikat ---
    if (isWd && nowS >= lunchStartS && nowS < lunchEndS) {
      // Trwa obiad – odliczaj czas do jego końca
      obiadStatus.textContent = '🍽️ Obiad!!';
      obiadStatus.style.color = 'var(--positive)'; // zielony kolor
      obiadSub.textContent    = 'Koniec za:';
      countdown.textContent   = fmt((lunchEndS - nowS) * 1000, false);
      countdown.classList.add('green');
    } else {
      // Obiad jeszcze nie nastąpił lub już był – odliczaj do następnego
      obiadStatus.style.color = '';
      countdown.classList.remove('green');
      let targetMs;
      if (isWd && nowS < lunchStartS) {
        // Dziś jeszcze nie było obiadu – odliczaj do niego
        obiadSub.textContent    = `Obiad o ${pad(lunchHour)}:${pad(lunchMin)}`;
        obiadStatus.textContent = isNightWindow ? 'Do następnego obiadu' : 'Do obiadu';
        targetMs = (lunchStartS - nowS) * 1000;
      } else {
        // Obiad był dziś lub to weekend – odliczaj do następnego dnia szkolnego
        obiadStatus.textContent = 'Do następnego obiadu';
        obiadSub.textContent    = `Obiad o ${pad(lunchHour)}:${pad(lunchMin)}`;
        targetMs = nextSchoolDay(now, lunchStartS) - now;
      }
      countdown.textContent = fmt(targetMs);
    }

    // Elementy HTML panelu lekcji
    const st = document.getElementById('lesson-status');
    const sb = document.getElementById('lesson-sub');
    const ti = document.getElementById('lesson-timer');

    // Godzina pierwszej lekcji w sekundach (z korektą)
    const firstStartS    = firstHour * 3600 + firstMin * 60 + OFFSET;
    // Numer pierwszej lekcji odpowiadający wybranej godzinie
    const firstLessonNum = PLAN.find(slot => slot.type === 'lesson' && slot.start === firstHour * 60 + firstMin)?.num ?? 1;

    // --- Panel lekcji: wyświetl odpowiedni komunikat ---

    // Weekend: odliczaj do poniedziałku
    if (!isWd) {
      st.textContent = 'Weekend 🎉';
      sb.textContent = `Do następnej lekcji (${pad(firstHour)}:${pad(firstMin)}):`;
      ti.textContent = fmt(nextSchoolDay(now, firstStartS) - now);
      return;
    }

    // Przed pierwszą lekcją: odliczaj do jej początku
    if (nowS < firstStartS) {
      st.textContent = 'Przed lekcjami';
      sb.textContent = isNightWindow
        ? `Do następnej lekcji (${pad(firstHour)}:${pad(firstMin)}):`
        : `Do lekcji ${firstLessonNum} (${pad(firstHour)}:${pad(firstMin)}):`;
      ti.textContent = fmt((firstStartS - nowS) * 1000);
      return;
    }

    // Po ostatniej lekcji: odliczaj do następnego dnia
    if (nowS >= LAST_S) {
      st.textContent = 'Koniec lekcji 🏁';
      sb.textContent = `Do następnej lekcji (${pad(firstHour)}:${pad(firstMin)}):`;
      ti.textContent = fmt(nextSchoolDay(now, firstStartS) - now);
      return;
    }

    // Znajdź aktualny slot w planie (lekcja lub przerwa)
    const slot = SCHEDULE.find(slot => nowS >= slot.startS && nowS < slot.endS);
    if (!slot) {
      // Czas między slotami (nie powinno się zdarzyć przy prawidłowym planie)
      st.textContent = '—'; sb.textContent = ''; ti.textContent = '';
      return;
    }

    if (slot.type === 'lesson') {
      // Trwa lekcja – odliczaj do przerwy
      st.textContent = `📚 Lekcja ${slot.num}`;
      sb.textContent = 'Do przerwy:';
      ti.textContent = fmt((slot.endS - nowS) * 1000);
    } else {
      // Trwa przerwa – odliczaj do następnej lekcji
      const next = SCHEDULE.find(s => s.type === 'lesson' && s.startS >= slot.endS);
      if (next) {
        st.textContent = `☕ Przerwa (po ${slot.after})`;
        sb.textContent = `Do lekcji ${next.num}:`;
        ti.textContent = fmt((next.startS - nowS) * 1000);
      }
    }
  }

  // Uruchom od razu przy załadowaniu strony, a potem co sekundę
  update();
  setInterval(update, 1000);
</script>
</body>
</html>