<?php

error_reporting(E_ALL);
set_time_limit(0);

$mediawiki = '';
$markdown = '';

if (isset($_POST['markdown'])) {
    $markdown = $_POST['markdown'];

    $citations = extractCitations($markdown);
    $mediawiki = md2mediawiki($markdown);
    $mediawiki = replaceCitations($mediawiki, $citations);
}

function replaceCitations($mediawiki, $citations)
{
    $pattern = '@\[([0-9]+)\]@';
    preg_match_all($pattern, $mediawiki, $matches, PREG_SET_ORDER);

    $remainingCitations = array();

    foreach ($matches as $match) {
        $url = $citations[$match[1]]['url'];
        $title = $citations[$match[1]]['title'];

        if (!empty($title))
            $url = "[$url $title]";

        $number = $match[1];
        $count = 0;
        $mediawiki = preg_replace("/" . preg_quote($match[0]) . "/", "<ref name=\"ref_$number\">$url</ref>", $mediawiki, 1, $count);
        $mediawiki = preg_replace("/" . preg_quote($match[0]) . "/", "<ref name=\"ref_$number\" />", $mediawiki);

        if ($count == 0) {
            $remainingCitations[$number] = "\n* " . $url;
        }
    }

    $mediawiki .= "\n{{Pages liées}}\n\n== Références ==\n" . implode('', $remainingCitations) . "\n</references>";

    // Also replace those spans: <span id="conséquences-de-lhydromorphie"></span>
    $mediawiki = preg_replace('@<span id="([^"]+)"></span>@', '', $mediawiki);
    $mediawiki = preg_replace('@==\n[\n]+@', "==\n", $mediawiki);

    $mediawiki = "{{Facteur environnemental | Nom=\n| Image=\n}}\n" . $mediawiki;

    return $mediawiki;
}

function getOpenGraphTitleFromURL($url)
{
    // Don't parse PDFs:
    if (preg_match('@\.pdf$@', $url)) {
        return '';
    }

    $html = '';

    try {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 7);
        $html = curl_exec($ch);
        curl_close($ch);
    } catch (\Throwable $th) {
        return '';
    }

    if (empty($html)) {
        return '';
    }
    
    $title = '';

    $doc = new DOMDocument();
    if (!$doc->loadHTML($html)) {
        return '';
    }

    $xpath = new DOMXPath($doc);

    $titleNode = $xpath->query('//meta[@property="og:title"]/@content')->item(0);
    $title = $titleNode ? $titleNode->nodeValue : '';

    if (empty($title)) {
        $titleNode = $xpath->query('//title')->item(0);
        $title = $titleNode ? $titleNode->nodeValue : '';
    }

    $title = trim(str_replace('|', '-', $title));
    
    return $title;
}

function extractCitations(&$markdown)
{
    $citations = array();

    $pattern = '@^\[([0-9]+)\] ([^\n]+)@m';
    preg_match_all($pattern, $markdown, $matches, PREG_SET_ORDER);

    foreach ($matches as $match) {
        $url = trim($match[2]);

        $citations[$match[1]] = ['url' => $url, 'title' => getOpenGraphTitleFromURL($url)];
    }

    $markdown = preg_replace($pattern, '', $markdown);
    $markdown = preg_replace('@^Citations:@m', '', $markdown);
    $markdown = trim($markdown);

    return $citations;
}

function md2mediawiki($md)
{
    $proc = proc_open('pandoc -f markdown -t mediawiki', 
            array(0 => array('pipe', 'r'),
                1 => array('pipe', 'w'), 
                2 => array('pipe', 'w')), $pipes);

    fwrite($pipes[0], $md);
    fclose($pipes[0]);

    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    
    proc_close($proc);
    
    return $stdout;
}

?>
<html>
<head>
    <title>MD2Wiki</title>
</head>
<body>
    <h1>MD2Wiki</h1>
    <form action="md2wiki.php" method="post">
        <textarea name="markdown" rows="40" cols="80"><?php echo htmlentities($markdown) ?></textarea>
        <div style="text-align:center"><input type="submit" value="Convert"></div>
    </form>

    <div style="border: 1px solid; width: 589px; padding: 10px;">
        <pre style="white-space: break-spaces;"><?php echo htmlentities($mediawiki); ?></pre>
    </div>
</body>
</html>